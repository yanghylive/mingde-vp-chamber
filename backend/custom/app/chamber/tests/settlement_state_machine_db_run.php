<?php

declare(strict_types=1);

/**
 * 分账状态机 DB 测试：崩溃回收 + unknown 对账态 + 结算单关闭正确性。
 *
 * 覆盖第六轮复核的 P0：
 *  1. runDue 只扫 pending/failed，processing 崩溃残留永不回收 → 现在可回收
 *  2. closeCompleted 只统计 pending/failed，processing 残留被错误置 done → 现在不 done
 *  3. payout_record 已存在（pending）时盲目重打 → 现在置 unknown 对账态，不重打
 */

use app\chamber\services\SettlementService;
use app\chamber\tenancy\TenantContext;
use app\chamber\tenancy\TenantRecord;
use think\App;
use think\facade\Db;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$assertions = 0;
$failure = null;
$runId = strtolower(bin2hex(random_bytes(6)));
$now = time();
$tenantId = 0;
$channelIds = [];
$ruleIds = [];

try {
    (new App())->initialize();

    prerequisites();
    $tenant = firstActiveTenant();
    $tenantId = (int) $tenant['id'];

    $channelId = createChannelFixture($tenantId, $runId, $channelIds);
    $ctx = new TenantContext(new TenantRecord(
        $tenantId,
        (string) $tenant['slug'],
        $channelId,
        'st-' . $runId,
        true
    ), 'database-test');

    $service = new SettlementService();

    // ---------- 场景 1：正常结算闭环（pending → runDue → success → 结算单 done） ----------
    createRule($tenantId, $runId, $ruleIds);
    $r1 = $service->settle($tenantId, 'membership_fee', 'ORD1_' . $runId, '100.00');
    assertSame(false, $r1['skipped']);
    $sid1 = (int) $r1['id'];
    assertSame(1, detailCount($sid1), 'settle 应生成 1 条明细');

    $summary1 = $service->runDue(50);
    assertSame(1, $summary1['done'], '正常明细应 1 条成功');
    assertSame(0, $summary1['unknown'], '正常场景不应有 unknown');
    assertSame('success', detailStatus($sid1), '正常明细应 success');
    assertSame('done', settlementStatus($sid1), '正常结算单应 done');

    // ---------- 场景 2：processing 崩溃残留（claim 过期）→ runDue 回收打款 → 结算单 done ----------
    $r2 = $service->settle($tenantId, 'membership_fee', 'ORD2_' . $runId, '100.00');
    $sid2 = (int) $r2['id'];
    $did2 = detailId($sid2);
    crashIntoProcessing($did2, $now);

    $summary2 = $service->runDue(50);
    assertSame(1, $summary2['done'], 'processing 崩溃残留应被回收并成功');
    assertSame('success', detailStatus($sid2), '回收后明细应 success');
    assertSame('done', settlementStatus($sid2), '回收完成后结算单应 done');

    // ---------- 场景 3：payout pending（渠道状态不明）→ detail 置 unknown，结算单不 done，不重打 ----------
    $r3 = $service->settle($tenantId, 'membership_fee', 'ORD3_' . $runId, '100.00');
    $sid3 = (int) $r3['id'];
    $did3 = detailId($sid3);
    crashIntoProcessing($did3, $now);
    // 模拟上次执行已在渠道调用前写好了 payout_record(pending)（崩溃在半路）
    $idemKey = hash('sha256', implode(':', ['settlement_payout', $tenantId, $did3]));
    Db::table('ch_payout_record')->insert([
        'settlement_detail_id' => $did3,
        'tenant_id' => $tenantId,
        'channel' => 'merchant_transfer',
        'channel_order_no' => '',
        'amount' => '50.00',
        'status' => 'pending',
        'idempotency_key' => $idemKey,
        'request_payload_hash' => str_repeat('a', 64),
        'raw_response' => '',
        'add_time' => $now,
        'update_time' => $now,
    ]);

    $summary3 = $service->runDue(50);
    assertSame(1, $summary3['unknown'], 'payout pending 应进入 unknown 对账态');
    assertSame('unknown', detailStatus($sid3), '明细应置 unknown');
    assertSame('pending', settlementStatus($sid3), '存在 unknown 的结算单不能置 done（保持 pending）');
    assertSame(1, payoutCount($did3), '不能新增第二条 payout（防重复打款）');

    // ---------- 场景 4：payout success（渠道已打款）→ detail 幂等补成功 ----------
    $r4 = $service->settle($tenantId, 'membership_fee', 'ORD4_' . $runId, '100.00');
    $sid4 = (int) $r4['id'];
    $did4 = detailId($sid4);
    crashIntoProcessing($did4, $now);
    $idemKey4 = hash('sha256', implode(':', ['settlement_payout', $tenantId, $did4]));
    Db::table('ch_payout_record')->insert([
        'settlement_detail_id' => $did4,
        'tenant_id' => $tenantId,
        'channel' => 'merchant_transfer',
        'channel_order_no' => 'CH_NO_4',
        'amount' => '50.00',
        'status' => 'success',
        'idempotency_key' => $idemKey4,
        'request_payload_hash' => str_repeat('b', 64),
        'raw_response' => '{}',
        'add_time' => $now,
        'update_time' => $now,
    ]);

    $summary4 = $service->runDue(50);
    assertSame(0, $summary4['done'], 'payout success 收敛不应再计入 done（claim 跳过）');
    assertSame('success', detailStatus($sid4), 'payout success 时明细应幂等补 success');
    assertSame('CH_NO_4', detailChannelRef($sid4), 'channel_ref 应回填渠道单号');
    assertSame('done', settlementStatus($sid4), '补成功后结算单应 done');

    echo 'PASS settlement state machine database service (' . $assertions . " assertions; fixtures removed)\n";
} catch (Throwable $e) {
    $failure = $e;
} finally {
    cleanupFixtures($tenantId, $runId, $channelIds, $ruleIds);
}

if ($failure !== null) {
    fwrite(STDERR, 'FAIL settlement state machine database service: ' . $failure->getMessage() . "\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// helpers
// ---------------------------------------------------------------------------

function prerequisites(): void
{
    foreach (['ch_tenant', 'ch_settlement', 'ch_settlement_detail', 'ch_settlement_rule', 'ch_payout_record'] as $table) {
        $rows = Db::query(
            'SELECT COUNT(*) AS aggregate FROM information_schema.`TABLES` '
            . 'WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = ?',
            [$table]
        );
        if ((int) ($rows[0]['aggregate'] ?? 0) !== 1) {
            throw new RuntimeException(sprintf('Required database table %s is unavailable', $table));
        }
    }
}

function tenantRow(string $slug): array
{
    $row = Db::table('ch_tenant')
        ->where('slug', $slug)
        ->where('status', 1)
        ->where('is_del', 0)
        ->find();
    if (!is_array($row)) {
        throw new RuntimeException(sprintf('Tenant fixture %s is unavailable', $slug));
    }

    return $row;
}

/** 取第一个启用租户（本地库 slug=local-primary，生产库 slug=md-kaypal，自适应） */
function firstActiveTenant(): array
{
    $row = Db::table('ch_tenant')
        ->where('status', 1)
        ->where('is_del', 0)
        ->order('id', 'asc')
        ->find();
    if (!is_array($row)) {
        throw new RuntimeException('No active tenant available for settlement state machine test');
    }

    return $row;
}

function createChannelFixture(int $tenantId, string $runId, array &$channelIds): int
{
    $now = time();
    $id = (int) Db::table('ch_channel')->insertGetId([
        'tenant_id' => $tenantId,
        'name' => 'Settlement state machine fixture',
        'code' => 'st-' . $runId,
        'entry_key' => substr(hash('sha256', 'st-channel:' . $runId), 0, 32),
        'status' => 1,
        'sort' => 0,
        'add_time' => $now,
        'update_time' => $now,
        'is_del' => 0,
    ]);
    if ($id <= 0) {
        throw new RuntimeException('Settlement channel fixture was not created');
    }
    $channelIds[] = $id;

    return $id;
}

function createRule(int $tenantId, string $runId, array &$ruleIds): void
{
    $now = time();
    $id = (int) Db::table('ch_settlement_rule')->insertGetId([
        'tenant_id' => $tenantId,
        'business_type' => 'membership_fee',
        'receiver_type' => 'company',
        'receiver_id' => 1,
        'receiver_name' => '状态机测试-' . $runId,
        'ratio' => 50,
        'channel' => 'merchant_transfer',
        'status' => 1,
        'sort' => 0,
        'is_del' => 0,
        'add_time' => $now,
        'update_time' => $now,
    ]);
    $ruleIds[] = $id;
}

/** 模拟 worker 认领后崩溃：detail 置 processing 且 claim 已过期 */
function crashIntoProcessing(int $detailId, int $now): void
{
    Db::table('ch_settlement_detail')
        ->where('id', $detailId)
        ->update([
            'status' => 'processing',
            'claim_token' => bin2hex(random_bytes(8)),
            'claim_expire_time' => $now - 1,
            'update_time' => $now,
        ]);
}

function detailCount(int $settlementId): int
{
    return (int) Db::table('ch_settlement_detail')->where('settlement_id', $settlementId)->count();
}

function detailId(int $settlementId): int
{
    $row = Db::table('ch_settlement_detail')->where('settlement_id', $settlementId)->find();
    if (!is_array($row)) {
        throw new RuntimeException('Settlement detail missing');
    }

    return (int) $row['id'];
}

function detailStatus(int $settlementId): string
{
    $row = Db::table('ch_settlement_detail')->where('settlement_id', $settlementId)->find();

    return is_array($row) ? (string) $row['status'] : 'missing';
}

function detailChannelRef(int $settlementId): string
{
    $row = Db::table('ch_settlement_detail')->where('settlement_id', $settlementId)->find();

    return is_array($row) ? (string) $row['channel_ref'] : '';
}

function settlementStatus(int $settlementId): string
{
    $row = Db::table('ch_settlement')->where('id', $settlementId)->find();

    return is_array($row) ? (string) $row['status'] : 'missing';
}

function payoutCount(int $detailId): int
{
    return (int) Db::table('ch_payout_record')->where('settlement_detail_id', $detailId)->count();
}

function cleanupFixtures(int $tenantId, string $runId, array $channelIds, array $ruleIds): void
{
    try {
        foreach ($ruleIds as $rid) {
            Db::table('ch_settlement_rule')->where('id', $rid)->delete();
        }
        foreach ($channelIds as $cid) {
            Db::table('ch_channel')->where('id', $cid)->delete();
        }
        // order_no 在 ch_settlement，detail/payout 通过 settlement 关联清理
        $settlementIds = Db::table('ch_settlement')
            ->where('tenant_id', $tenantId)
            ->whereLike('order_no', 'ORD%_' . $runId)
            ->column('id');
        if ($settlementIds) {
            $detailIds = Db::table('ch_settlement_detail')
                ->whereIn('settlement_id', $settlementIds)
                ->column('id');
            Db::table('ch_payout_record')
                ->whereIn('settlement_detail_id', $detailIds ?: [0])
                ->delete();
            Db::table('ch_settlement_detail')
                ->whereIn('settlement_id', $settlementIds)
                ->delete();
            Db::table('ch_settlement')
                ->whereIn('id', $settlementIds)
                ->delete();
        }
        Db::table('ch_settlement_balance')
            ->where('tenant_id', $tenantId)
            ->where('receiver_name', 'like', '状态机测试-%')
            ->delete();
    } catch (Throwable $e) {
        fwrite(STDERR, 'Fixture cleanup warning: ' . $e->getMessage() . "\n");
    }
}

function assertSame($expected, $actual, string $label = ''): void
{
    global $assertions;
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf(
            '%s: Expected %s, got %s',
            $label !== '' ? $label : 'assertSame',
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
}
