<?php

declare(strict_types=1);

namespace app\chamber\services;

use app\chamber\activity\CourseBookingRequest;
use app\chamber\activity\CourseCheckinRequest;
use app\chamber\activity\CourseCheckinToken;
use app\chamber\exceptions\MemberTransactionException;
use app\chamber\identity\AuthenticatedAdminContext;
use app\chamber\identity\AuthenticatedUserContext;
use app\chamber\membership\CourseConsumptionPolicy;
use app\chamber\tenancy\TenantContext;
use think\facade\Db;

/** 约课 + 签到闭环：占座（按规则预填课时）、扫码划扣（HMAC 校验 + FIFO）、管理代签。 */
final class CourseBookingService
{
    /** @var CourseIdempotency */
    private $idempotency;

    /** @var CreditAccountService */
    private $accounts;

    /** @var CreditConsumeService */
    private $consume;

    /** @var callable */
    private $clock;

    public function __construct(
        CourseIdempotency $idempotency = null,
        CreditAccountService $accounts = null,
        CreditConsumeService $consume = null,
        callable $clock = null
    ) {
        $this->idempotency = $idempotency ?: new CourseIdempotency();
        $this->accounts = $accounts ?: new CreditAccountService();
        $this->consume = $consume ?: new CreditConsumeService();
        $this->clock = $clock ?: function (): int {
            return time();
        };
    }

    public function book(
        TenantContext $tenant,
        AuthenticatedUserContext $auth,
        int $sessionId,
        CourseBookingRequest $request,
        string $callerKey
    ): array {
        if ($sessionId <= 0) {
            throw new MemberTransactionException(422, 'request_validation_failed', 'session_id must be a positive integer');
        }

        return $this->idempotency->execute(
            $tenant,
            'createCourseBooking',
            'crmeb_user',
            $auth->uid(),
            $callerKey,
            [
                'session_id' => $sessionId,
                'participants' => $request->participants(),
            ],
            201,
            function (int $now) use ($tenant, $auth, $sessionId, $request, $callerKey): array {
                $member = $this->member($tenant, $auth, true);
                $session = Db::table('ch_course_session')
                    ->where('tenant_id', $tenant->tenantId())
                    ->where('id', $sessionId)
                    ->lock(true)
                    ->find();
                if (!is_array($session)) {
                    throw new MemberTransactionException(404, 'course_session_not_found', '课次不存在');
                }
                if ((int) $session['status'] !== 1) {
                    throw new MemberTransactionException(409, 'course_session_not_open', '该课次当前不可约');
                }
                if ((int) $session['booked_count'] >= (int) $session['capacity']) {
                    throw new MemberTransactionException(409, 'course_session_full', '该课次名额已满');
                }

                $policy = CourseConsumptionPolicy::resolve(
                    (string) $session['course_type'],
                    $request->participants(),
                    (string) $session['duration_hours']
                );
                $account = $this->resolveAccount($tenant, $member, $session, $now);
                $bookingKey = hash('sha256', 'course-book:' . $tenant->tenantId() . ':' . $callerKey);

                $bookingId = (int) Db::table('ch_course_booking')->insertGetId([
                    'tenant_id' => $tenant->tenantId(),
                    'session_id' => $sessionId,
                    'member_id' => (int) $member['id'],
                    'uid' => $auth->uid(),
                    'account_id' => (int) $account['id'],
                    'credit_cost_hours' => $policy['required_hours'],
                    'consume_rule' => $policy['rule'],
                    'participants' => $request->participants(),
                    'status' => 1,
                    'idempotency_key' => $bookingKey,
                    'booking_time' => $now,
                    'attended_time' => 0,
                    'add_time' => $now,
                    'update_time' => $now,
                ]);
                if ($bookingId <= 0) {
                    throw new MemberTransactionException(409, 'course_booking_conflict', '约课记录创建冲突');
                }
                Db::table('ch_course_session')
                    ->where('id', $sessionId)
                    ->where('tenant_id', $tenant->tenantId())
                    ->update([
                        'booked_count' => (int) $session['booked_count'] + 1,
                        'status' => ((int) $session['booked_count'] + 1) >= (int) $session['capacity'] ? 2 : 1,
                        'update_time' => $now,
                    ]);

                return [
                    'booking_id' => $bookingId,
                    'session_id' => $sessionId,
                    'course_type' => (string) $session['course_type'],
                    'owner_type' => (string) $account['owner_type'],
                    'account_id' => (int) $account['id'],
                    'credit_cost_hours' => $policy['required_hours'],
                    'consume_rule' => $policy['rule'],
                    'participants' => $request->participants(),
                    'status' => 1,
                    'replayed' => false,
                ];
            },
            function () use ($tenant, $auth): void {
                $this->member($tenant, $auth, false);
            }
        );
    }

    public function checkinByToken(
        TenantContext $tenant,
        AuthenticatedUserContext $auth,
        int $sessionId,
        CourseCheckinRequest $request,
        string $callerKey
    ): array {
        if ($sessionId <= 0) {
            throw new MemberTransactionException(422, 'request_validation_failed', 'session_id must be a positive integer');
        }

        return $this->idempotency->execute(
            $tenant,
            'createCourseCheckin',
            'crmeb_user',
            $auth->uid(),
            $callerKey,
            [
                'session_id' => $sessionId,
                'booking_id' => $request->bookingId(),
                'token_digest' => CourseCheckinToken::digest($request->token()),
            ],
            201,
            function (int $now) use ($tenant, $auth, $sessionId, $request, $callerKey): array {
                $member = $this->member($tenant, $auth, true);
                $booking = $this->activeBooking($tenant, $member, $sessionId, $request->bookingId());
                $session = Db::table('ch_course_session')
                    ->where('tenant_id', $tenant->tenantId())
                    ->where('id', $sessionId)
                    ->lock(true)
                    ->find();
                if (!is_array($session)) {
                    throw new MemberTransactionException(404, 'course_session_not_found', '课次不存在');
                }
                $this->verifyToken($tenant, $sessionId, $request->token(), $now);

                return $this->consumeBooking($tenant, $member, $booking, $session, $now, $callerKey, 'scan', '');
            },
            function () use ($tenant, $auth): void {
                $this->member($tenant, $auth, false);
            }
        );
    }

    public function manualCheckin(
        TenantContext $tenant,
        AuthenticatedAdminContext $admin,
        int $sessionId,
        int $bookingId,
        string $reason,
        string $callerKey
    ): array {
        if ($sessionId <= 0) {
            throw new MemberTransactionException(422, 'request_validation_failed', 'session_id must be a positive integer');
        }
        if ($bookingId <= 0) {
            throw new MemberTransactionException(422, 'request_validation_failed', 'booking_id must be a positive integer');
        }
        $reason = trim($reason);
        if ($reason === '' || strlen($reason) > 500) {
            throw new MemberTransactionException(422, 'request_validation_failed', 'reason must contain 1 to 500 characters');
        }

        return $this->idempotency->execute(
            $tenant,
            'createManualCourseCheckinForAdmin',
            'crmeb_admin',
            $admin->adminId(),
            $callerKey,
            ['session_id' => $sessionId, 'booking_id' => $bookingId, 'reason' => $reason],
            201,
            function (int $now) use ($tenant, $sessionId, $bookingId, $reason, $callerKey): array {
                $booking = Db::table('ch_course_booking')
                    ->where('tenant_id', $tenant->tenantId())
                    ->where('session_id', $sessionId)
                    ->where('id', $bookingId)
                    ->lock(true)
                    ->find();
                if (!is_array($booking) || (int) $booking['status'] !== 1) {
                    throw new MemberTransactionException(404, 'course_booking_not_found', '约课记录不存在或已签到');
                }
                $member = Db::table('ch_tenant_member')
                    ->where('tenant_id', $tenant->tenantId())
                    ->where('uid', (int) $booking['uid'])
                    ->find();
                if (!is_array($member)) {
                    throw new MemberTransactionException(404, 'member_not_found', '会员不存在');
                }
                $session = Db::table('ch_course_session')
                    ->where('tenant_id', $tenant->tenantId())
                    ->where('id', $sessionId)
                    ->lock(true)
                    ->find();
                if (!is_array($session) || in_array((int) $session['status'], [4], true)) {
                    throw new MemberTransactionException(409, 'course_session_not_open', '课次不可签到');
                }

                return $this->consumeBooking($tenant, $member, $booking, $session, $now, $callerKey, 'manual', $reason);
            },
            null
        );
    }

    public function listForMember(TenantContext $tenant, AuthenticatedUserContext $auth, int $page, int $limit): array
    {
        $total = (int) Db::table('ch_course_booking')
            ->where('tenant_id', $tenant->tenantId())
            ->where('uid', $auth->uid())
            ->count();
        $rows = Db::table('ch_course_booking')
            ->where('tenant_id', $tenant->tenantId())
            ->where('uid', $auth->uid())
            ->order('id', 'desc')
            ->page($page, $limit)
            ->select()
            ->toArray();
        $sessionIds = array_values(array_unique(array_column($rows, 'session_id')));
        $sessionMap = [];
        if ($sessionIds !== []) {
            $sessions = Db::table('ch_course_session')
                ->where('id', 'in', $sessionIds)
                ->select()
                ->toArray();
            foreach ($sessions as $s) {
                $sessionMap[(int) $s['id']] = $s;
            }
        }
        $items = [];
        foreach ($rows as $row) {
            $s = $sessionMap[(int) $row['session_id']] ?? null;
            $items[] = [
                'booking_id' => (int) $row['id'],
                'session_id' => (int) $row['session_id'],
                'session_title' => $s !== null ? (string) $s['title'] : '',
                'course_type' => $s !== null ? (string) $s['course_type'] : '',
                'start_time' => $s !== null ? (int) $s['start_time'] : 0,
                'duration_hours' => $s !== null ? (string) $s['duration_hours'] : '0.00',
                'participants' => (int) $row['participants'],
                'credit_cost_hours' => (string) $row['credit_cost_hours'],
                'consume_rule' => (string) $row['consume_rule'],
                'status' => (int) $row['status'],
                'booking_time' => (int) $row['booking_time'],
                'attended_time' => (int) $row['attended_time'],
            ];
        }

        return [
            'items' => $items,
            'page' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'has_more' => ($page * $limit) < $total,
            ],
        ];
    }

    private function consumeBooking(
        TenantContext $tenant,
        array $member,
        array $booking,
        array $session,
        int $now,
        string $callerKey,
        string $checkinType,
        string $reason
    ): array {
        $account = $this->accounts->getAccountById($tenant->tenantId(), (int) $booking['account_id']);
        if (!is_array($account)) {
            throw new MemberTransactionException(404, 'credit_account_not_found', '课时账户不存在');
        }
        $ledgerKey = hash('sha256', 'course-consume:' . $tenant->tenantId() . ':' . (int) $booking['id'] . ':' . $callerKey);
        $this->consume->consume(
            $tenant->tenantId(),
            $account,
            (int) $member['id'],
            (int) $member['uid'],
            (string) $booking['credit_cost_hours'],
            $now,
            $ledgerKey
        );
        Db::table('ch_course_booking')
            ->where('id', (int) $booking['id'])
            ->where('tenant_id', $tenant->tenantId())
            ->where('status', 1)
            ->update([
                'status' => 3,
                'attended_time' => $now,
                'update_time' => $now,
            ]);

        return [
            'booking_id' => (int) $booking['id'],
            'session_id' => (int) $booking['session_id'],
            'account_id' => (int) $account['id'],
            'credit_cost_hours' => (string) $booking['credit_cost_hours'],
            'consume_rule' => (string) $booking['consume_rule'],
            'status' => 3,
            'checkin_type' => $checkinType,
            'reason' => $reason,
            'attended_at' => $now,
            'replayed' => false,
        ];
    }

    private function activeBooking(TenantContext $tenant, array $member, int $sessionId, int $bookingId): array
    {
        $query = Db::table('ch_course_booking')
            ->where('tenant_id', $tenant->tenantId())
            ->where('session_id', $sessionId)
            ->where('member_id', (int) $member['id'])
            ->where('uid', $member['uid']);
        if ($bookingId > 0) {
            $query->where('id', $bookingId);
        }
        $booking = $query->lock(true)->find();
        if (!is_array($booking) || (int) $booking['status'] !== 1) {
            throw new MemberTransactionException(404, 'course_booking_not_found', '约课记录不存在或已签到');
        }

        return $booking;
    }

    private function verifyToken(TenantContext $tenant, int $sessionId, string $token, int $now): void
    {
        $verified = CourseCheckinToken::verify($token, $tenant->tenantId(), $sessionId, $now);
        $digest = CourseCheckinToken::digest($token);
        $tokenRow = Db::table('ch_course_checkin_token')
            ->where('tenant_id', $tenant->tenantId())
            ->where('session_id', $sessionId)
            ->where('token_digest', $digest)
            ->where('status', 1)
            ->where('valid_from', '<=', $now)
            ->where('expires_time', '>=', $now)
            ->lock(true)
            ->find();
        if (!$verified || !is_array($tokenRow)) {
            throw new MemberTransactionException(422, 'checkin_token_invalid', '课程签到令牌无效或已过期');
        }
    }

    private function resolveAccount(TenantContext $tenant, array $member, array $session, int $now): array
    {
        if ((string) $session['course_type'] === CourseConsumptionPolicy::TYPE_FAMILY) {
            $familyMemberRow = Db::table('ch_family_member')
                ->where('tenant_id', $tenant->tenantId())
                ->where('member_id', (int) $member['id'])
                ->where('status', 1)
                ->find();
            if (!is_array($familyMemberRow)) {
                throw new MemberTransactionException(409, 'family_required', '家庭卡课程需先创建或加入家庭');
            }
            $family = Db::table('ch_family')
                ->where('tenant_id', $tenant->tenantId())
                ->where('id', (int) $familyMemberRow['family_id'])
                ->where('status', 1)
                ->find();
            if (!is_array($family)) {
                throw new MemberTransactionException(409, 'family_required', '家庭不存在或已解散');
            }

            return $this->accounts->ensureAccount(
                $tenant->tenantId(),
                'family',
                (int) $family['id'],
                (int) $family['owner_uid'],
                $now
            );
        }

        return $this->accounts->ensureAccount(
            $tenant->tenantId(),
            'member',
            (int) $member['id'],
            $member['uid'],
            $now
        );
    }

    private function member(TenantContext $tenant, AuthenticatedUserContext $auth, bool $lock): array
    {
        $query = Db::table('ch_tenant_member')
            ->where('tenant_id', $tenant->tenantId())
            ->where('uid', $auth->uid());
        if ($lock) {
            $query->lock(true);
        }
        $row = $query->find();
        if (!is_array($row)) {
            throw new MemberTransactionException(404, 'member_not_found', '会员不存在');
        }
        if ((int) $row['status'] !== 1 || (int) $row['is_del'] !== 0) {
            throw new MemberTransactionException(403, 'member_disabled', '会员状态不可用');
        }
        if ((int) $row['current_channel_id'] !== $tenant->channelId()) {
            throw new MemberTransactionException(403, 'tenant_scope_denied', '会员不在当前渠道');
        }

        return $row;
    }
}
