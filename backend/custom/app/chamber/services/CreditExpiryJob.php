<?php

declare(strict_types=1);

namespace app\chamber\services;

use think\facade\Db;

/** 定时任务：扫描到期授予，作废并冲减账户余额（实现「到期未上完自动作废」）。 */
final class CreditExpiryJob
{
    public function run(int $tenantId, int $batch = 200, int $now = null): array
    {
        $now = $now ?? time();
        $summary = ['scanned' => 0, 'forfeited' => 0, 'forfeited_hours' => '0.00', 'failed' => 0];

        $grants = Db::table('ch_credit_grant')
            ->where('tenant_id', $tenantId)
            ->where('status', 1)
            ->where('expire_time', '>', 0)
            ->where('expire_time', '<=', $now)
            ->where('hours_remaining', '>', '0.00')
            ->order('id', 'asc')
            ->limit($batch)
            ->lock(true)
            ->select()
            ->toArray();

        foreach ($grants as $grant) {
            $summary['scanned']++;
            try {
                Db::transaction(function () use ($tenantId, $grant, $now, &$summary): void {
                    $locked = Db::table('ch_credit_grant')
                        ->where('id', (int) $grant['id'])
                        ->where('status', 1)
                        ->where('hours_remaining', $grant['hours_remaining'])
                        ->lock(true)
                        ->find();
                    if (!is_array($locked) || (float) $locked['hours_remaining'] <= 0.0001) {
                        return;
                    }
                    $forfeit = (float) $locked['hours_remaining'];
                    $account = Db::table('ch_credit_account')
                        ->where('id', (int) $locked['account_id'])
                        ->where('tenant_id', $tenantId)
                        ->lock(true)
                        ->find();
                    if (!is_array($account)) {
                        return;
                    }
                    $balance = (float) $account['balance_hours'];
                    $balanceAfter = number_format(max(0.0, $balance - $forfeit), 2, '.', '');
                    $updated = Db::table('ch_credit_account')
                        ->where('id', (int) $account['id'])
                        ->where('version', (int) $account['version'])
                        ->update([
                            'balance_hours' => $balanceAfter,
                            'version' => (int) $account['version'] + 1,
                            'update_time' => $now,
                        ]);
                    if ($updated !== 1) {
                        throw new \RuntimeException('课时账户余额变更冲突');
                    }
                    Db::table('ch_credit_grant')
                        ->where('id', (int) $locked['id'])
                        ->update(['status' => 2, 'update_time' => $now]);
                    Db::table('ch_credit_ledger')->insertGetId([
                        'tenant_id' => $tenantId,
                        'account_id' => (int) $locked['account_id'],
                        'member_id' => (int) $locked['member_id'],
                        'uid' => (int) $locked['uid'],
                        'delta_hours' => '-' . number_format($forfeit, 2, '.', ''),
                        'balance_after' => $balanceAfter,
                        'source_type' => 'expire_forfeit',
                        'source_id' => (string) $locked['id'],
                        'idempotency_key' => hash('sha256', 'expire:' . $tenantId . ':' . $locked['id'] . ':' . $now),
                        'status' => 1,
                        'reversal_id' => 0,
                        'add_time' => $now,
                    ]);
                    $summary['forfeited']++;
                    $summary['forfeited_hours'] = number_format(
                        (float) $summary['forfeited_hours'] + $forfeit,
                        2,
                        '.',
                        ''
                    );
                });
            } catch (\Throwable $exception) {
                $summary['failed']++;
            }
        }

        return $summary;
    }
}
