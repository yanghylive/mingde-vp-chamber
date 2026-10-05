<?php

declare(strict_types=1);

namespace app\chamber\services;

use app\chamber\exceptions\MemberTransactionException;
use think\facade\Db;

/** 课时账户：定位/创建账户 + 充值（grant + 账本 + 乐观锁）。 */
final class CreditAccountService
{
    public function ensureAccount(
        int $tenantId,
        string $ownerType,
        int $ownerId,
        int $uid,
        int $now
    ): array {
        $account = Db::table('ch_credit_account')
            ->where('tenant_id', $tenantId)
            ->where('owner_type', $ownerType)
            ->where('owner_id', $ownerId)
            ->lock(true)
            ->find();
        if (is_array($account)) {
            return $account;
        }
        $id = (int) Db::table('ch_credit_account')->insertGetId([
            'tenant_id' => $tenantId,
            'owner_type' => $ownerType,
            'owner_id' => $ownerId,
            'uid' => $uid,
            'balance_hours' => '0.00',
            'frozen_hours' => '0.00',
            'version' => 1,
            'add_time' => $now,
            'update_time' => $now,
        ]);
        if ($id <= 0) {
            throw new MemberTransactionException(503, 'credit_account_failed', '课时账户创建失败');
        }
        $account = Db::table('ch_credit_account')->where('id', $id)->lock(true)->find();
        if (!is_array($account)) {
            throw new MemberTransactionException(503, 'credit_account_failed', '课时账户读取失败');
        }

        return $account;
    }

    public function getAccountById(int $tenantId, int $accountId): ?array
    {
        $account = Db::table('ch_credit_account')
            ->where('tenant_id', $tenantId)
            ->where('id', $accountId)
            ->find();

        return is_array($account) ? $account : null;
    }

    /**
     * 充值：插入一条授予 + 追加账本 + CAS 更新余额。
     * @return array{grant_id:int, balance_after:string}
     */
    public function grant(
        int $tenantId,
        array $account,
        int $memberId,
        int $uid,
        string $totalHours,
        int $expireTime,
        int $packageId,
        int $now,
        string $idempotencyKey
    ): array {
        $totalHours = self::decimal($totalHours, 'total_hours');
        $grantId = (int) Db::table('ch_credit_grant')->insertGetId([
            'tenant_id' => $tenantId,
            'account_id' => (int) $account['id'],
            'package_id' => $packageId,
            'member_id' => $memberId,
            'uid' => $uid,
            'hours_total' => $totalHours,
            'hours_remaining' => $totalHours,
            'expire_time' => $expireTime,
            'source_order_context_id' => 0,
            'status' => 1,
            'add_time' => $now,
            'update_time' => $now,
        ]);
        if ($grantId <= 0) {
            throw new MemberTransactionException(503, 'credit_grant_failed', '课时授予记录创建失败');
        }
        $balance = (float) $account['balance_hours'];
        $balanceAfter = number_format($balance + (float) $totalHours, 2, '.', '');
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
            'delta_hours' => $totalHours,
            'balance_after' => $balanceAfter,
            'source_type' => 'package_purchase',
            'source_id' => (string) $grantId,
            'idempotency_key' => $idempotencyKey,
            'status' => 1,
            'reversal_id' => 0,
            'add_time' => $now,
        ]);
        if ($ledgerId <= 0) {
            throw new MemberTransactionException(409, 'credit_grant_failed', '课时账本记录创建失败');
        }

        return ['grant_id' => $grantId, 'balance_after' => $balanceAfter];
    }

    private static function decimal(string $value, string $field): string
    {
        if (!preg_match('/^[0-9]+(?:\.[0-9]{1,2})?$/D', $value)) {
            throw new MemberTransactionException(422, 'request_validation_failed', $field . ' must be a decimal');
        }

        return number_format((float) $value, 2, '.', '');
    }
}
