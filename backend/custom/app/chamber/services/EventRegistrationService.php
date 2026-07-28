<?php

declare(strict_types=1);

namespace app\chamber\services;

use app\chamber\activity\EventEligibility;
use app\chamber\activity\EventRegistrationRequest;
use app\chamber\commerce\Money;
use app\chamber\exceptions\MemberTransactionException;
use app\chamber\identity\AuthenticatedUserContext;
use app\chamber\tenancy\TenantContext;
use RuntimeException;
use think\facade\Db;

/** Creates immediate free/points registrations under one database transaction. */
final class EventRegistrationService
{
    /** @var EventService */
    private $events;

    /** @var EventIdempotency */
    private $idempotency;

    /** @var callable */
    private $registrationNoFactory;

    public function __construct(
        EventService $events = null,
        EventIdempotency $idempotency = null,
        callable $registrationNoFactory = null
    ) {
        $this->events = $events ?: new EventService();
        $this->idempotency = $idempotency ?: new EventIdempotency();
        $this->registrationNoFactory = $registrationNoFactory ?: function (
            int $tenantId,
            int $eventId,
            int $uid,
            int $now
        ): string {
            return strtoupper(substr(hash('sha256', implode(':', [
                'event_registration',
                $tenantId,
                $eventId,
                $uid,
                $now,
                bin2hex(random_bytes(16)),
            ])), 0, 32));
        };
    }

    public function register(
        TenantContext $tenant,
        AuthenticatedUserContext $auth,
        int $eventId,
        EventRegistrationRequest $request,
        string $callerKey
    ): array {
        if ($eventId <= 0) {
            throw $this->validation('event_id', 'invalid_value', 'event_id must be a positive integer');
        }

        return $this->idempotency->execute(
            $tenant,
            'createEventRegistration',
            'crmeb_user',
            $auth->uid(),
            $callerKey,
            array_merge(['event_id' => $eventId], $request->toIdempotencyArray()),
            201,
            function (int $now) use ($tenant, $auth, $eventId, $request): array {
                $member = $this->member($tenant, $auth, true);
                $event = $this->event($tenant, $eventId);
                $existing = Db::table('ch_event_registration')
                    ->where('tenant_id', $tenant->tenantId())
                    ->where('event_id', $eventId)
                    ->where('uid', $auth->uid())
                    ->lock(true)
                    ->find();
                if (is_array($existing)) {
                    throw new MemberTransactionException(
                        409,
                        'registration_already_exists',
                        'Member already has a registration for this event'
                    );
                }
                $ticket = $this->ticket($tenant->tenantId(), $eventId, $request->ticketId());
                $amount = Money::assertAmount((string) $ticket['price'], 'ticket.price');
                $integral = (int) $ticket['integral_price'];
                $this->assertExpectedPrice($request, $amount, $integral);

                $account = $this->pointAccount($tenant->tenantId(), $member, $integral > 0);
                $points = is_array($account) ? (int) $account['balance'] : 0;
                $roles = $this->roleCodes($tenant->tenantId(), (int) $member['id'], $now);
                $capacity = (int) $ticket['capacity'];
                $hasCapacity = $capacity === 0
                    || ((int) $ticket['reserved_count'] + (int) $ticket['paid_count']) < $capacity;
                $reason = EventEligibility::reason(
                    $event,
                    $ticket,
                    $member,
                    $roles,
                    $points,
                    $now,
                    $hasCapacity
                );
                if ($reason !== null) {
                    throw new MemberTransactionException(409, $reason, 'Member is not eligible for this event ticket');
                }
                if (Money::toMinor($amount) > 0) {
                    throw new MemberTransactionException(
                        409,
                        'event_payment_unavailable',
                        'Cash event registration is not available yet'
                    );
                }

                if ($integral > $points) {
                    throw new MemberTransactionException(409, 'points_required', 'Member points are insufficient');
                }

                $registrationNo = call_user_func(
                    $this->registrationNoFactory,
                    $tenant->tenantId(),
                    $eventId,
                    $auth->uid(),
                    $now
                );
                if (!is_string($registrationNo)
                    || preg_match('/^[A-Z0-9]{16,32}$/D', $registrationNo) !== 1) {
                    throw new RuntimeException('Event registration number factory returned an invalid value');
                }

                $registrationId = (int) Db::table('ch_event_registration')->insertGetId([
                    'tenant_id' => $tenant->tenantId(),
                    'event_id' => $eventId,
                    'ticket_id' => (int) $ticket['id'],
                    'member_id' => (int) $member['id'],
                    'uid' => $auth->uid(),
                    'registration_no' => $registrationNo,
                    'order_pk' => 0,
                    'order_no' => '',
                    'order_context_id' => 0,
                    'amount' => $amount,
                    'integral_amount' => $integral,
                    'status' => 1,
                    'reserve_expire_time' => 0,
                    'paid_time' => $now,
                    'cancel_time' => 0,
                    'refund_time' => 0,
                    'add_time' => $now,
                    'update_time' => $now,
                ]);
                if ($registrationId <= 0) {
                    throw new MemberTransactionException(409, 'event_registration_failed', 'Event registration could not be created');
                }

                $updated = Db::table('ch_event_ticket')
                    ->where('tenant_id', $tenant->tenantId())
                    ->where('id', (int) $ticket['id'])
                    ->where('paid_count', (int) $ticket['paid_count'])
                    ->update([
                        'paid_count' => (int) $ticket['paid_count'] + 1,
                        'update_time' => $now,
                    ]);
                if ($updated !== 1) {
                    throw new MemberTransactionException(409, 'event_full', 'Event ticket capacity changed');
                }

                if ($integral > 0) {
                    $this->debitPoints($tenant->tenantId(), $member, $account, $registrationId, $integral, $now);
                }

                return $this->events->registrationDetail($tenant, $auth, $registrationId);
            },
            function () use ($tenant, $auth): void {
                $this->member($tenant, $auth, false);
            }
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
        $member = $query->find();
        if (!is_array($member)) {
            throw new MemberTransactionException(404, 'member_not_found', 'Member account was not found');
        }
        if ((int) $member['status'] !== 1 || (int) $member['is_del'] !== 0) {
            throw new MemberTransactionException(403, 'member_disabled', 'Member account is not active');
        }
        if ((int) $member['current_channel_id'] !== $tenant->channelId()) {
            throw new MemberTransactionException(403, 'tenant_scope_denied', 'Member is not active in the requested channel');
        }

        return $member;
    }

    private function event(TenantContext $tenant, int $eventId): array
    {
        $event = Db::table('ch_event')
            ->where('tenant_id', $tenant->tenantId())
            ->where('channel_id', $tenant->channelId())
            ->where('id', $eventId)
            ->where('is_del', 0)
            ->lock(true)
            ->find();
        if (!is_array($event)) {
            throw new MemberTransactionException(404, 'event_not_found', 'Event was not found');
        }

        return $event;
    }

    private function ticket(int $tenantId, int $eventId, int $ticketId): array
    {
        $ticket = Db::table('ch_event_ticket')
            ->where('tenant_id', $tenantId)
            ->where('event_id', $eventId)
            ->where('id', $ticketId)
            ->where('is_del', 0)
            ->lock(true)
            ->find();
        if (!is_array($ticket)) {
            throw new MemberTransactionException(404, 'event_ticket_not_found', 'Event ticket was not found');
        }

        return $ticket;
    }

    private function assertExpectedPrice(
        EventRegistrationRequest $request,
        string $amount,
        int $integral
    ): void {
        if ($request->expectedAmount() !== null && $request->expectedAmount() !== $amount) {
            throw $this->validation('expected_amount', 'snapshot_mismatch', 'Event ticket cash amount changed');
        }
        if ($request->expectedIntegral() !== null && $request->expectedIntegral() !== $integral) {
            throw $this->validation('expected_integral', 'snapshot_mismatch', 'Event ticket points amount changed');
        }
    }

    private function pointAccount(int $tenantId, array $member, bool $required): ?array
    {
        $account = Db::table('ch_point_account')
            ->where('tenant_id', $tenantId)
            ->where('member_id', (int) $member['id'])
            ->lock(true)
            ->find();
        if (!is_array($account)) {
            if ($required) {
                throw new MemberTransactionException(409, 'points_required', 'Member points are insufficient');
            }

            return null;
        }
        if ((int) $account['uid'] !== (int) $member['uid']) {
            throw new MemberTransactionException(409, 'points_required', 'Member points account is inconsistent');
        }

        return $account;
    }

    private function roleCodes(int $tenantId, int $memberId, int $now): array
    {
        $roles = Db::table('ch_member_role')->alias('member_role')
            ->join(
                ['ch_persona_role' => 'persona_role'],
                'persona_role.id = member_role.role_id AND persona_role.tenant_id = member_role.tenant_id'
            )
            ->where('member_role.tenant_id', $tenantId)
            ->where('member_role.member_id', $memberId)
            ->where('member_role.status', 1)
            ->where('member_role.effective_time', '<=', $now)
            ->where(function ($query) use ($now): void {
                $query->where('member_role.expire_time', 0)
                    ->whereOr('member_role.expire_time', '>', $now);
            })
            ->where('persona_role.status', 1)
            ->where('persona_role.is_del', 0)
            ->column('persona_role.code');

        return array_values(array_unique(array_map('strval', $roles)));
    }

    private function debitPoints(
        int $tenantId,
        array $member,
        array $account,
        int $registrationId,
        int $points,
        int $now
    ): void {
        $balance = (int) $account['balance'];
        if ($balance < $points) {
            throw new MemberTransactionException(409, 'points_required', 'Member points are insufficient');
        }
        $balanceAfter = $balance - $points;
        $updated = Db::table('ch_point_account')
            ->where('id', (int) $account['id'])
            ->where('tenant_id', $tenantId)
            ->where('version', (int) $account['version'])
            ->update([
                'balance' => $balanceAfter,
                'version' => (int) $account['version'] + 1,
                'update_time' => $now,
            ]);
        if ($updated !== 1) {
            throw new MemberTransactionException(409, 'points_required', 'Member points balance changed');
        }
        $ledgerId = (int) Db::table('ch_point_ledger')->insertGetId([
            'tenant_id' => $tenantId,
            'account_id' => (int) $account['id'],
            'member_id' => (int) $member['id'],
            'uid' => (int) $member['uid'],
            'delta' => -$points,
            'balance_after' => $balanceAfter,
            'source_type' => 'event_registration',
            'source_id' => (string) $registrationId,
            'idempotency_key' => hash('sha256', implode(':', [
                'event_registration_points',
                $tenantId,
                $registrationId,
            ])),
            'status' => 1,
            'reversal_id' => 0,
            'add_time' => $now,
        ]);
        if ($ledgerId <= 0) {
            throw new MemberTransactionException(409, 'event_registration_failed', 'Event points debit could not be recorded');
        }
    }

    private function validation(string $field, string $code, string $message): MemberTransactionException
    {
        return new MemberTransactionException(
            422,
            'request_validation_failed',
            $message,
            [['field' => $field, 'code' => $code]]
        );
    }
}
