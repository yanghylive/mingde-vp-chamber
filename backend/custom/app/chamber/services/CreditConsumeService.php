<?php

declare(strict_types=1);

namespace app\chamber\services;

use app\chamber\exceptions\MemberTransactionException;
use app\chamber\membership\CreditAccountSnapshot;
use think\facade\Db;

/** 课时划扣：FIFO（先到期先扣）+ 账本 + 乐观锁。 */
final class CreditConsumeService
{
    /**
     * @return array{consumed_grants:array, balance_after:string, consumed_hours:string}
     */
    public function consume(
        int $tenantId,
        array $account,
        int $memberId,
        int $uid,
        string $requiredHours,
        int $now,
        string $idempotencyKey
    ): array {
        $required = (float) self::decimal($requiredHours, 'required_hours');
        if ($required <= 0) {
            throw new MemberTransactionException(422, 'request_validation_failed', 'required_hours must be positive');
        }

        $grants = Db::table('ch_credit_grant')
            ->where('tenant_id', $tenantId)
            ->where('account_id', (int) $account['id'])
            ->where('status', 1)
            ->where('hours_remaining', '>', '0.00')
            ->where(function ($query) use ($now): void {
                $query->where('expire_time', 0)->whereOr('expire_time', '>', $now);
            })
            ->order('expire_time', 'asc')
            ->lock(true)
            ->select()
            ->toArray();

        $remaining = $required;
        $consumedGrants = [];
        foreach ($grants as $grant) {
            if ($remaining <= 0.0001) {
                break;
            }
            $available = (float) $grant['hours_remaining'];
            $take = min($available, $remaining);
            $takeStr = number_format($take, 2, '.', '');
            $newRemaining = number_format($available - $take, 2, '.', '');
            $status = ((float) $newRemaining <= 0.0001) ? 3 : 1;
            $updated = Db::table('ch_credit_grant')
                ->where('id', (int) $grant['id'])
                ->where('hours_remaining', $grant['hours_remaining'])
                ->update([
                    'hours_remaining' => $newRemaining,
                    'status' => $status,
                    'update_time' => $now,
                ]);
            if ($updated !== 1) {
                throw new MemberTransactionException(409, 'credit_grant_conflict', '课时授予并发变更，请重试');
            }
            $consumedGrants[] = ['grant_id' => (int) $grant['id'], 'taken' => $takeStr];
            $remaining -= $take;
        }

        if ($remaining > 0.0001) {
            throw new MemberTransactionException(409, 'credit_insufficient', '课时不足');
        }

        $balance = (float) $account['balance_hours'];
        $balanceAfter = number_format($balance - $required, 2, '.', '');
        $updated = Db::table('ch_credit_account')
            ->where('id', (int) $account['id'])
            ->where('tenant_id', $tenantId)
            ->where('version', (int) $account['version'])
            ->update([
                'balance_hours' => $balanceAfter,
                'version' => (int) $account['version'] + 1,
                'update_time' => $now,
            ]);
        if ($updated !== 1) {
            throw new MemberTransactionException(409, 'credit_balance_conflict', '课时账户余额变更冲突');
        }
        $ledgerId = (int) Db::table('ch_credit_ledger')->insertGetId([
            'tenant_id' => $tenantId,
            'account_id' => (int) $account['id'],
            'member_id' => $memberId,
            'uid' => $uid,
            'delta_hours' => '-' . number_format($required, 2, '.', ''),
            'balance_after' => $balanceAfter,
            'source_type' => 'session_consume',
            'source_id' => (string) $account['id'],
            'idempotency_key' => $idempotencyKey,
            'status' => 1,
            'reversal_id' => 0,
            'add_time' => $now,
        ]);
        if ($ledgerId <= 0) {
            throw new MemberTransactionException(409, 'credit_consume_failed', '课时划扣账本记录失败');
        }

        return [
            'consumed_grants' => $consumedGrants,
            'balance_after' => $balanceAfter,
            'consumed_hours' => number_format($required, 2, '.', ''),
        ];
    }

    /** 读取账户快照（含授予明细与账本）。 */
    public function snapshot(int $tenantId, int $accountId): ?CreditAccountSnapshot
    {
        $account = Db::table('ch_credit_account')
            ->where('tenant_id', $tenantId)
            ->where('id', $accountId)
            ->find();
        if (!is_array($account)) {
            return null;
        }
        $snapshot = CreditAccountSnapshot::fromRow($account);
        $grants = Db::table('ch_credit_grant')
            ->where('tenant_id', $tenantId)
            ->where('account_id', $accountId)
            ->order('id', 'desc')
            ->select()
            ->toArray();
        $ledger = Db::table('ch_credit_ledger')
            ->where('tenant_id', $tenantId)
            ->where('account_id', $accountId)
            ->order('id', 'desc')
            ->limit(50)
            ->select()
            ->toArray();
        $grantsOut = array_map(function (array $g): array {
            return [
                'id' => (int) $g['id'],
                'package_id' => (int) $g['package_id'],
                'hours_total' => (string) $g['hours_total'],
                'hours_remaining' => (string) $g['hours_remaining'],
                'expire_time' => (int) $g['expire_time'],
                'status' => (int) $g['status'],
                'add_time' => (int) $g['add_time'],
            ];
        }, $grants);
        $ledgerOut = array_map(function (array $l): array {
            return [
                'id' => (int) $l['id'],
                'delta_hours' => (string) $l['delta_hours'],
                'balance_after' => (string) $l['balance_after'],
                'source_type' => (string) $l['source_type'],
                'source_id' => (string) $l['source_id'],
                'add_time' => (int) $l['add_time'],
            ];
        }, $ledger);

        return $snapshot->withGrants($grantsOut)->withLedger($ledgerOut);
    }

    private static function decimal(string $value, string $field): string
    {
        if (!preg_match('/^[0-9]+(?:\.[0-9]{1,2})?$/D', $value)) {
            throw new MemberTransactionException(422, 'request_validation_failed', $field . ' must be a decimal');
        }

        return number_format((float) $value, 2, '.', '');
    }
}
