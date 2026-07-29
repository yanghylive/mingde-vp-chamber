<?php

declare(strict_types=1);

namespace app\chamber\services;

use app\chamber\exceptions\MemberTransactionException;
use think\facade\Db;

/** Reverses attendance rewards inside the trusted full-refund transaction. */
final class EventRewardReversalService
{
    private const REVERSAL_TYPE = 'refund_reversal';

    public function reverseForRefund(
        int $tenantId,
        int $eventId,
        int $registrationId,
        int $uid,
        string $completionFingerprint,
        int $now = 0
    ): array {
        if ($tenantId <= 0 || $eventId <= 0 || $registrationId <= 0 || $uid <= 0
            || preg_match('/^[a-f0-9]{64}$/D', $completionFingerprint) !== 1) {
            throw new MemberTransactionException(
                422,
                'request_validation_failed',
                'event reward reversal parameters are invalid'
            );
        }
        $now = $now > 0 ? $now : time();

        return Db::transaction(function () use (
            $tenantId,
            $eventId,
            $registrationId,
            $uid,
            $completionFingerprint,
            $now
        ): array {
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
            if (!in_array((int) $registration['status'], [3, 5], true)) {
                throw $this->conflict('Event registration is not checked in or refunded');
            }
            $memberId = (int) $registration['member_id'];
            if ($memberId <= 0) {
                throw $this->conflict('Event registration has no tenant member');
            }

            $rewards = Db::table('ch_event_reward')
                ->where('tenant_id', $tenantId)
                ->where('event_id', $eventId)
                ->where('registration_id', $registrationId)
                ->where('uid', $uid)
                ->order('id', 'asc')
                ->lock(true)
                ->select()
                ->toArray();

            $originals = array_values(array_filter($rewards, static function (array $reward): bool {
                return (string) $reward['reward_type'] !== self::REVERSAL_TYPE;
            }));
            if ($originals === []) {
                return [
                    'reversed' => false,
                    'replayed' => false,
                    'points' => 0,
                    'contribution' => 0,
                    'reversal_ids' => [],
                ];
            }

            $points = 0;
            $contribution = 0;
            $reversalIds = [];
            $replayed = true;
            foreach ($originals as $original) {
                $result = $this->reverseReward(
                    $tenantId,
                    $registrationId,
                    $memberId,
                    $uid,
                    $original,
                    $completionFingerprint,
                    $now
                );
                $points += (int) $result['points'];
                $contribution += (int) $result['contribution'];
                $reversalIds[] = (int) $result['reversal_id'];
                $replayed = $replayed && (bool) $result['replayed'];
            }

            return [
                'reversed' => true,
                'replayed' => $replayed,
                'points' => $points,
                'contribution' => $contribution,
                'reversal_ids' => $reversalIds,
            ];
        });
    }

    private function reverseReward(
        int $tenantId,
        int $registrationId,
        int $memberId,
        int $uid,
        array $original,
        string $completionFingerprint,
        int $now
    ): array {
        $originalId = (int) $original['id'];
        $points = (int) $original['points'];
        $contribution = (int) $original['contribution'];
        $reversalKey = hash('sha256', implode(':', [
            'event_reward_refund_reversal',
            $completionFingerprint,
            $originalId,
        ]));
        $reversal = Db::table('ch_event_reward')
            ->where('tenant_id', $tenantId)
            ->where('idempotency_key', $reversalKey)
            ->lock(true)
            ->find();
        if (is_array($reversal)) {
            $this->assertRewardReplay($original, $reversal, $registrationId, $uid, $points, $contribution);
            $this->assertLedgerReplay(
                'ch_point_ledger',
                $tenantId,
                $original,
                $reversalKey,
                $points
            );
            $this->assertLedgerReplay(
                'ch_contribution_ledger',
                $tenantId,
                $original,
                $reversalKey,
                $contribution
            );

            return [
                'replayed' => true,
                'reversal_id' => (int) $reversal['id'],
                'points' => $points,
                'contribution' => $contribution,
            ];
        }
        if ((int) $original['status'] !== 1 || (int) $original['reversal_id'] !== 0) {
            throw $this->conflict('Event reward was already reversed by a different completion');
        }

        $pointLedger = $this->lockOriginalLedger(
            'ch_point_ledger',
            $tenantId,
            $original,
            $points,
            $registrationId,
            $memberId,
            $uid,
            ':points'
        );
        $contributionLedger = $this->lockOriginalLedger(
            'ch_contribution_ledger',
            $tenantId,
            $original,
            $contribution,
            $registrationId,
            $memberId,
            $uid,
            ':contribution'
        );

        $balanceAfter = 0;
        if ($points > 0) {
            $account = Db::table('ch_point_account')
                ->where('tenant_id', $tenantId)
                ->where('id', (int) $pointLedger['account_id'])
                ->where('member_id', $memberId)
                ->where('uid', $uid)
                ->lock(true)
                ->find();
            if (!is_array($account)) {
                throw $this->conflict('Tenant points account is unavailable');
            }
            if ((int) $account['balance'] < $points) {
                throw new MemberTransactionException(
                    409,
                    'event_reward_reversal_balance_insufficient',
                    'Event reward points were already consumed; manual reconciliation is required'
                );
            }
            $balanceAfter = (int) $account['balance'] - $points;
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
                throw $this->conflict('Event reward reversal could not update the tenant points balance');
            }
        }

        $reversalId = (int) Db::table('ch_event_reward')->insertGetId([
            'tenant_id' => $tenantId,
            'event_id' => (int) $original['event_id'],
            'registration_id' => $registrationId,
            'uid' => $uid,
            'reward_type' => self::REVERSAL_TYPE,
            'points' => $points,
            'contribution' => $contribution,
            'idempotency_key' => $reversalKey,
            'status' => 2,
            'reversal_id' => $originalId,
            'add_time' => $now,
        ]);
        if ($reversalId <= 0) {
            throw $this->conflict('Event reward reversal could not be recorded');
        }

        if ($points > 0) {
            $this->appendLedgerReversal(
                'ch_point_ledger',
                $tenantId,
                $registrationId,
                $memberId,
                $uid,
                $pointLedger,
                $reversalKey,
                $points,
                $balanceAfter,
                ':points',
                $now
            );
        }
        if ($contribution > 0) {
            $this->appendLedgerReversal(
                'ch_contribution_ledger',
                $tenantId,
                $registrationId,
                $memberId,
                $uid,
                $contributionLedger,
                $reversalKey,
                $contribution,
                0,
                ':contribution',
                $now
            );
        }

        $updated = Db::table('ch_event_reward')
            ->where('tenant_id', $tenantId)
            ->where('id', $originalId)
            ->where('status', 1)
            ->where('reversal_id', 0)
            ->update(['status' => 2, 'reversal_id' => $reversalId]);
        if ($updated !== 1) {
            throw $this->conflict('Event reward reversal could not link the original reward');
        }

        return [
            'replayed' => false,
            'reversal_id' => $reversalId,
            'points' => $points,
            'contribution' => $contribution,
        ];
    }

    private function lockOriginalLedger(
        string $table,
        int $tenantId,
        array $original,
        int $amount,
        int $registrationId,
        int $memberId,
        int $uid,
        string $keySuffix
    ): array {
        if ($amount === 0) {
            return [];
        }
        $ledger = Db::table($table)
            ->where('tenant_id', $tenantId)
            ->where('idempotency_key', hash('sha256', (string) $original['idempotency_key'] . $keySuffix))
            ->lock(true)
            ->find();
        if (!is_array($ledger)
            || (int) $ledger['delta'] !== $amount
            || (int) $ledger['member_id'] !== $memberId
            || (int) $ledger['uid'] !== $uid
            || (string) $ledger['source_type'] !== 'event_checkin'
            || (string) $ledger['source_id'] !== (string) $registrationId
            || (int) $ledger['status'] !== 1
            || (int) $ledger['reversal_id'] !== 0) {
            throw $this->conflict('Event reward ledger is inconsistent');
        }

        return $ledger;
    }

    private function appendLedgerReversal(
        string $table,
        int $tenantId,
        int $registrationId,
        int $memberId,
        int $uid,
        array $originalLedger,
        string $reversalKey,
        int $amount,
        int $balanceAfter,
        string $keySuffix,
        int $now
    ): void {
        $values = [
            'tenant_id' => $tenantId,
            'member_id' => $memberId,
            'uid' => $uid,
            'delta' => -$amount,
            'source_type' => 'event_checkin_refund',
            'source_id' => (string) $registrationId,
            'idempotency_key' => hash('sha256', $reversalKey . $keySuffix),
            'status' => 1,
            'reversal_id' => (int) $originalLedger['id'],
            'add_time' => $now,
        ];
        if ($table === 'ch_point_ledger') {
            $values['account_id'] = (int) $originalLedger['account_id'];
            $values['balance_after'] = $balanceAfter;
        }
        $ledgerId = (int) Db::table($table)->insertGetId($values);
        if ($ledgerId <= 0) {
            throw $this->conflict('Event reward reversal ledger could not be recorded');
        }
        $updated = Db::table($table)
            ->where('tenant_id', $tenantId)
            ->where('id', (int) $originalLedger['id'])
            ->where('status', 1)
            ->where('reversal_id', 0)
            ->update(['status' => 2, 'reversal_id' => $ledgerId]);
        if ($updated !== 1) {
            throw $this->conflict('Event reward reversal could not link the original ledger');
        }
    }

    private function assertRewardReplay(
        array $original,
        array $reversal,
        int $registrationId,
        int $uid,
        int $points,
        int $contribution
    ): void {
        if ((int) $original['status'] !== 2
            || (int) $original['reversal_id'] !== (int) $reversal['id']
            || (string) $reversal['reward_type'] !== self::REVERSAL_TYPE
            || (int) $reversal['status'] !== 2
            || (int) $reversal['reversal_id'] !== (int) $original['id']
            || (int) $reversal['registration_id'] !== $registrationId
            || (int) $reversal['uid'] !== $uid
            || (int) $reversal['points'] !== $points
            || (int) $reversal['contribution'] !== $contribution) {
            throw $this->conflict('Event reward reversal replay is inconsistent');
        }
    }

    private function assertLedgerReplay(
        string $table,
        int $tenantId,
        array $originalReward,
        string $reversalKey,
        int $amount
    ): void {
        if ($amount === 0) {
            return;
        }
        $suffix = $table === 'ch_point_ledger' ? ':points' : ':contribution';
        $original = Db::table($table)
            ->where('tenant_id', $tenantId)
            ->where('idempotency_key', hash('sha256', (string) $originalReward['idempotency_key'] . $suffix))
            ->lock(true)
            ->find();
        $reversal = Db::table($table)
            ->where('tenant_id', $tenantId)
            ->where('idempotency_key', hash('sha256', $reversalKey . $suffix))
            ->lock(true)
            ->find();
        if (!is_array($original) || !is_array($reversal)
            || (int) $original['status'] !== 2
            || (int) $original['reversal_id'] !== (int) $reversal['id']
            || (int) $reversal['delta'] !== -$amount
            || (int) $reversal['member_id'] !== (int) $original['member_id']
            || (int) $reversal['uid'] !== (int) $original['uid']
            || (string) $original['source_type'] !== 'event_checkin'
            || (string) $reversal['source_type'] !== 'event_checkin_refund'
            || (string) $reversal['source_id'] !== (string) $original['source_id']
            || (int) $reversal['status'] !== 1
            || (int) $reversal['reversal_id'] !== (int) $original['id']) {
            throw $this->conflict('Event reward reversal ledger replay is inconsistent');
        }
    }

    private function conflict(string $message): MemberTransactionException
    {
        return new MemberTransactionException(409, 'event_reward_reversal_failed', $message);
    }
}
