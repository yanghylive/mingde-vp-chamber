<?php

declare(strict_types=1);

namespace app\chamber\services;

use app\chamber\exceptions\MemberTransactionException;
use think\facade\Db;
use Throwable;

/** Writes attendance rewards as append-only, tenant-scoped idempotent ledgers. */
final class EventRewardService
{
    public function grant(
        int $tenantId,
        int $eventId,
        int $registrationId,
        int $uid,
        string $rewardType,
        int $points,
        int $contribution,
        string $idempotencyKey,
        int $now = 0
    ): array {
        if ($tenantId <= 0 || $eventId <= 0 || $registrationId <= 0 || $uid <= 0
            || preg_match('/^[a-z][a-z0-9_]{1,31}$/D', $rewardType) !== 1
            || $points < 0 || $contribution < 0
            || preg_match('/^[a-f0-9]{64}$/D', $idempotencyKey) !== 1) {
            throw new MemberTransactionException(422, 'request_validation_failed', 'event reward parameters are invalid');
        }
        if ($points === 0 && $contribution === 0) {
            return ['granted' => false, 'replayed' => false, 'points' => 0, 'contribution' => 0];
        }
        $now = $now > 0 ? $now : time();

        return Db::transaction(function () use (
            $tenantId,
            $eventId,
            $registrationId,
            $uid,
            $rewardType,
            $points,
            $contribution,
            $idempotencyKey,
            $now
        ): array {
            $existing = Db::table('ch_event_reward')
                ->where('tenant_id', $tenantId)
                ->where('idempotency_key', $idempotencyKey)
                ->lock(true)
                ->find();
            if (is_array($existing)) {
                if ((int) $existing['event_id'] !== $eventId
                    || (int) $existing['registration_id'] !== $registrationId
                    || (int) $existing['uid'] !== $uid
                    || (int) $existing['points'] !== $points
                    || (int) $existing['contribution'] !== $contribution
                    || (string) $existing['reward_type'] !== $rewardType) {
                    throw new MemberTransactionException(409, 'idempotency_conflict', 'event reward key conflicts with a different reward');
                }

                return [
                    'granted' => true,
                    'replayed' => true,
                    'reward_id' => (int) $existing['id'],
                    'points' => $points,
                    'contribution' => $contribution,
                ];
            }

            $registration = Db::table('ch_event_registration')
                ->where('tenant_id', $tenantId)
                ->where('id', $registrationId)
                ->where('event_id', $eventId)
                ->where('uid', $uid)
                ->lock(true)
                ->find();
            if (!is_array($registration)) {
                throw new MemberTransactionException(404, 'registration_not_found', 'Event registration was not found');
            }
            $memberId = (int) $registration['member_id'];
            if ($memberId <= 0) {
                throw new MemberTransactionException(409, 'event_reward_failed', 'Event registration has no tenant member');
            }

            $balance = 0;
            $accountId = 0;
            if ($points > 0) {
                $account = $this->lockOrCreatePointAccount($tenantId, $memberId, $uid, $now);
                $accountId = (int) $account['id'];
                $balance = (int) $account['balance'] + $points;
                $updated = Db::table('ch_point_account')
                    ->where('id', $accountId)
                    ->where('tenant_id', $tenantId)
                    ->where('version', (int) $account['version'])
                    ->update([
                        'balance' => $balance,
                        'version' => (int) $account['version'] + 1,
                        'update_time' => $now,
                    ]);
                if ($updated !== 1) {
                    throw new MemberTransactionException(409, 'event_reward_failed', 'event reward could not update the tenant points balance');
                }
            }

            $rewardId = (int) Db::table('ch_event_reward')->insertGetId([
                'tenant_id' => $tenantId,
                'event_id' => $eventId,
                'registration_id' => $registrationId,
                'uid' => $uid,
                'reward_type' => $rewardType,
                'points' => $points,
                'contribution' => $contribution,
                'idempotency_key' => $idempotencyKey,
                'status' => 1,
                'reversal_id' => 0,
                'add_time' => $now,
            ]);
            if ($rewardId <= 0) {
                throw new MemberTransactionException(409, 'event_reward_failed', 'event reward could not be recorded');
            }

            if ($points > 0) {
                Db::table('ch_point_ledger')->insert([
                    'tenant_id' => $tenantId,
                    'account_id' => $accountId,
                    'member_id' => $memberId,
                    'uid' => $uid,
                    'delta' => $points,
                    'balance_after' => $balance,
                    'source_type' => 'event_checkin',
                    'source_id' => (string) $registrationId,
                    'idempotency_key' => hash('sha256', $idempotencyKey . ':points'),
                    'status' => 1,
                    'reversal_id' => 0,
                    'add_time' => $now,
                ]);
            }

            if ($contribution > 0) {
                Db::table('ch_contribution_ledger')->insert([
                    'tenant_id' => $tenantId,
                    'member_id' => $memberId,
                    'uid' => $uid,
                    'delta' => $contribution,
                    'source_type' => 'event_checkin',
                    'source_id' => (string) $registrationId,
                    'idempotency_key' => hash('sha256', $idempotencyKey . ':contribution'),
                    'status' => 1,
                    'reversal_id' => 0,
                    'add_time' => $now,
                ]);
            }

            return [
                'granted' => true,
                'replayed' => false,
                'reward_id' => $rewardId,
                'points' => $points,
                'contribution' => $contribution,
                'points_balance' => $balance,
            ];
        });
    }

    private function lockOrCreatePointAccount(int $tenantId, int $memberId, int $uid, int $now): array
    {
        $query = Db::table('ch_point_account')
            ->where('tenant_id', $tenantId)
            ->where('member_id', $memberId)
            ->lock(true);
        $account = $query->find();
        if (!is_array($account)) {
            try {
                Db::table('ch_point_account')->insert([
                    'tenant_id' => $tenantId,
                    'member_id' => $memberId,
                    'uid' => $uid,
                    'balance' => 0,
                    'version' => 1,
                    'add_time' => $now,
                    'update_time' => $now,
                ]);
            } catch (Throwable $exception) {
                // A concurrent first write may win the unique member key.
            }
            $account = Db::table('ch_point_account')
                ->where('tenant_id', $tenantId)
                ->where('member_id', $memberId)
                ->lock(true)
                ->find();
        }
        if (!is_array($account) || (int) $account['uid'] !== $uid) {
            throw new MemberTransactionException(409, 'event_reward_failed', 'Tenant points account is unavailable');
        }

        return $account;
    }
}
