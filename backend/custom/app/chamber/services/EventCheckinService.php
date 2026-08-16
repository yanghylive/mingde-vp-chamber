<?php

declare(strict_types=1);

namespace app\chamber\services;

use app\chamber\activity\EventCheckinRequest;
use app\chamber\activity\EventCheckinToken;
use app\chamber\activity\EventEligibility;
use app\chamber\exceptions\MemberTransactionException;
use app\chamber\identity\AuthenticatedUserContext;
use app\chamber\tenancy\TenantContext;
use think\facade\Db;
use Throwable;

/** Owns authenticated scan check-in and its atomic attendance reward. */
final class EventCheckinService
{
    /** @var EventRewardService */
    private $rewards;

    /** @var callable */
    private $clock;

    /** @var EventIdempotency */
    private $idempotency;

    public function __construct(
        EventRewardService $rewards,
        callable $clock = null,
        EventIdempotency $idempotency = null
    )
    {
        $this->rewards = $rewards;
        $this->clock = $clock ?: function (): int {
            return time();
        };
        $this->idempotency = $idempotency ?: new EventIdempotency();
    }

    public function checkin(
        TenantContext $tenant,
        AuthenticatedUserContext $auth,
        int $eventId,
        EventCheckinRequest $request,
        string $callerKey
    ): array {
        if ($eventId <= 0) {
            throw $this->validation('event_id', 'invalid_value', 'event_id must be a positive integer');
        }
        return $this->idempotency->execute(
            $tenant,
            'createEventCheckin',
            'crmeb_user',
            $auth->uid(),
            $callerKey,
            [
                'event_id' => $eventId,
                'registration_id' => $request->registrationId(),
                'token_digest' => EventCheckinToken::digest($request->token()),
            ],
            201,
            function (int $unused) use ($tenant, $auth, $eventId, $request): array {
                return $this->performCheckin($tenant, $auth, $eventId, $request, $this->now());
            },
            function () use ($tenant, $auth): void {
                $this->member($tenant, $auth, false);
            }
        );
    }

    private function performCheckin(
        TenantContext $tenant,
        AuthenticatedUserContext $auth,
        int $eventId,
        EventCheckinRequest $request,
        int $now
    ): array {
        $member = $this->member($tenant, $auth, true);
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
        if (!in_array((int) $event['status'], [
            EventEligibility::EVENT_PUBLISHED,
            EventEligibility::EVENT_REGISTRATION_CLOSED,
            EventEligibility::EVENT_ENDED,
        ], true)) {
            throw new MemberTransactionException(409, 'event_not_open', 'Event is not open for check-in');
        }

        $registrationQuery = Db::table('ch_event_registration')
            ->where('tenant_id', $tenant->tenantId())
            ->where('event_id', $eventId)
            ->where('member_id', (int) $member['id'])
            ->where('uid', $auth->uid());
        if ($request->registrationId() > 0) {
            $registrationQuery->where('id', $request->registrationId());
        }
        $registration = $registrationQuery->lock(true)->find();
        if (!is_array($registration) || !in_array((int) $registration['status'], [1, 5], true)) {
            throw new MemberTransactionException(404, 'registration_not_found', 'Event registration was not found');
        }

        $digest = EventCheckinToken::digest($request->token());
        try {
            $verified = EventCheckinToken::verify(
                $request->token(),
                $tenant->tenantId(),
                $eventId,
                $now
            );
        } catch (Throwable $exception) {
            throw new MemberTransactionException(
                503,
                'checkin_token_unavailable',
                'Event check-in token verification is not configured'
            );
        }
        $token = Db::table('ch_event_checkin_token')
            ->where('tenant_id', $tenant->tenantId())
            ->where('event_id', $eventId)
            ->where('token_digest', $digest)
            ->where('status', 1)
            ->where('valid_from', '<=', $now)
            ->where('expires_time', '>=', $now)
            ->lock(true)
            ->find();
        if (!$verified || !is_array($token)) {
            throw new MemberTransactionException(422, 'checkin_token_invalid', 'Event check-in token is invalid or expired');
        }

        $existing = Db::table('ch_event_checkin')
            ->where('tenant_id', $tenant->tenantId())
            ->where('registration_id', (int) $registration['id'])
            ->lock(true)
            ->find();
        if (is_array($existing)) {
            $this->grantReward($tenant, $event, $registration, $now);

            return $this->result($existing, true, max(0, (int) ($event['checkin_reward_points'] ?? 0)));
        }

        try {
            $checkinId = (int) Db::table('ch_event_checkin')->insertGetId([
                'tenant_id' => $tenant->tenantId(),
                'event_id' => $eventId,
                'registration_id' => (int) $registration['id'],
                'member_id' => (int) $member['id'],
                'uid' => $auth->uid(),
                'checkin_type' => 1,
                'token_digest' => $digest,
                'operator_admin_id' => 0,
                'reason' => '',
                'checked_time' => $now,
                'add_time' => $now,
            ]);
        } catch (Throwable $exception) {
            $existing = Db::table('ch_event_checkin')
                ->where('tenant_id', $tenant->tenantId())
                ->where('registration_id', (int) $registration['id'])
                ->find();
            if (is_array($existing)) {
                $this->grantReward($tenant, $event, $registration, $now);

                return $this->result($existing, true, max(0, (int) ($event['checkin_reward_points'] ?? 0)));
            }
            throw new MemberTransactionException(409, 'event_reward_failed', 'Event check-in could not be recorded');
        }
        if ($checkinId <= 0) {
            throw new MemberTransactionException(409, 'event_reward_failed', 'Event check-in could not be recorded');
        }
        if ((int) $registration['status'] === 1) {
            $updated = Db::table('ch_event_registration')
                ->where('tenant_id', $tenant->tenantId())
                ->where('id', (int) $registration['id'])
                ->where('status', 1)
                ->update(['status' => 5, 'update_time' => $now]);
            if ($updated !== 1) {
                throw new MemberTransactionException(409, 'event_reward_failed', 'Event registration status could not be updated');
            }
        }
        $this->grantReward($tenant, $event, $registration, $now);

        return [
            'id' => $checkinId,
            'event_id' => $eventId,
            'registration_id' => (int) $registration['id'],
            'checked_at' => $now,
            'checkin_type' => 'scan',
            'replayed' => false,
            'points_awarded' => max(0, (int) ($event['checkin_reward_points'] ?? 0)),
        ];
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
            throw new MemberTransactionException(404, 'member_not_found', 'Member account was not found');
        }
        if ((int) $row['status'] !== 1 || (int) $row['is_del'] !== 0) {
            throw new MemberTransactionException(403, 'member_disabled', 'Member account is not active');
        }
        if ((int) $row['current_channel_id'] !== $tenant->channelId()) {
            throw new MemberTransactionException(403, 'tenant_scope_denied', 'Member is not active in the requested channel');
        }

        return $row;
    }

    private function grantReward(TenantContext $tenant, array $event, array $registration, int $now): void
    {
        $this->rewards->grant(
            $tenant->tenantId(),
            (int) $event['id'],
            (int) $registration['id'],
            (int) $registration['uid'],
            'attendance',
            max(0, (int) ($event['checkin_reward_points'] ?? 0)),
            max(0, (int) ($event['checkin_reward_contribution'] ?? 0)),
            hash('sha256', implode(':', [
                'event_checkin_reward',
                $tenant->tenantId(),
                (int) $event['id'],
                (int) $registration['id'],
            ])),
            $now
        );
    }

    private function result(array $row, bool $replayed, int $pointsAwarded = 0): array
    {
        return [
            'id' => (int) $row['id'],
            'event_id' => (int) $row['event_id'],
            'registration_id' => (int) $row['registration_id'],
            'checked_at' => (int) $row['checked_time'],
            'checkin_type' => (int) $row['checkin_type'] === 2 ? 'manual' : 'scan',
            'replayed' => $replayed,
            'points_awarded' => $pointsAwarded,
        ];
    }

    private function now(): int
    {
        return (int) call_user_func($this->clock);
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
