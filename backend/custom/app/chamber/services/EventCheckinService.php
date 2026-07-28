<?php

declare(strict_types=1);

namespace app\chamber\services;

use app\chamber\activity\EventCheckinRequest;
use app\chamber\activity\EventCheckinToken;
use app\chamber\activity\EventEligibility;
use app\chamber\exceptions\MemberTransactionException;
use app\chamber\identity\AuthenticatedUserContext;
use app\chamber\membership\BootstrapIdempotency;
use app\chamber\tenancy\TenantContext;
use InvalidArgumentException;
use think\facade\Db;
use Throwable;

/** Owns authenticated scan check-in and its atomic attendance reward. */
final class EventCheckinService
{
    /** @var EventRewardService */
    private $rewards;

    /** @var callable */
    private $clock;

    public function __construct(EventRewardService $rewards, callable $clock = null)
    {
        $this->rewards = $rewards;
        $this->clock = $clock ?: function (): int {
            return time();
        };
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
        try {
            BootstrapIdempotency::assertCallerKey($callerKey);
        } catch (InvalidArgumentException $exception) {
            throw new MemberTransactionException(
                400,
                'request_validation_failed',
                'Idempotency-Key header is invalid',
                [['field' => 'Idempotency-Key', 'code' => 'invalid_format']]
            );
        }
        $now = $this->now();

        return Db::transaction(function () use ($tenant, $auth, $eventId, $request, $now): array {
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

            $existing = Db::table('ch_event_checkin')
                ->where('tenant_id', $tenant->tenantId())
                ->where('registration_id', (int) $registration['id'])
                ->lock(true)
                ->find();
            if (is_array($existing)) {
                $this->grantReward($tenant, $event, $registration, $now);

                return $this->result($existing, true);
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

                    return $this->result($existing, true);
                }
                throw new MemberTransactionException(409, 'event_reward_failed', 'Event check-in could not be recorded');
            }
            if ($checkinId <= 0) {
                throw new MemberTransactionException(409, 'event_reward_failed', 'Event check-in could not be recorded');
            }
            if ((int) $registration['status'] === 1) {
                Db::table('ch_event_registration')
                    ->where('tenant_id', $tenant->tenantId())
                    ->where('id', (int) $registration['id'])
                    ->where('status', 1)
                    ->update(['status' => 5, 'update_time' => $now]);
            }
            $this->grantReward($tenant, $event, $registration, $now);

            return [
                'id' => $checkinId,
                'event_id' => $eventId,
                'registration_id' => (int) $registration['id'],
                'checked_at' => $now,
                'checkin_type' => 'scan',
                'replayed' => false,
            ];
        });
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

    private function result(array $row, bool $replayed): array
    {
        return [
            'id' => (int) $row['id'],
            'event_id' => (int) $row['event_id'],
            'registration_id' => (int) $row['registration_id'],
            'checked_at' => (int) $row['checked_time'],
            'checkin_type' => (int) $row['checkin_type'] === 2 ? 'manual' : 'scan',
            'replayed' => $replayed,
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
