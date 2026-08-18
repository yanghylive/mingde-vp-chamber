<?php

declare(strict_types=1);

use crmeb\services\CacheService;
use crmeb\utils\JwtAuth;
use think\App;
use think\facade\Db;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

(new App())->initialize();

try {
    $action = $argv[1] ?? '';
    if ($action === 'setup') {
        setupChamberHttpFixture();
    } elseif ($action === 'inspect') {
        inspectChamberHttpFixture((int) ($argv[2] ?? 0));
    } elseif ($action === 'cleanup') {
        cleanupChamberHttpFixture(array_slice($argv, 2));
    } else {
        throw new InvalidArgumentException('Expected setup, inspect, or cleanup action');
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'chamber HTTP fixture failure: ' . $exception->getMessage() . "\n");
    exit(1);
}

/**
 * 造会员 + 积分 + 大咖 + 档期，签 member token + admin token。
 * 覆盖三个能力的 HTTP 验收：预约幂等 / 通知已读隔离 / 结算 claim。
 */
function setupChamberHttpFixture(): void
{
    cleanupChamberHttpFixture([]);
    $scope = chamberTenantScope();
    $run = strtoupper(bin2hex(random_bytes(5)));
    $now = time();
    $account = 'ch_http_' . strtolower($run);

    // 会员 + 积分（30000，够线上预约 10000）
    $uid = (int) Db::table('eb_user')->insertGetId([
        'account' => $account, 'nickname' => $account,
        'phone' => '137' . substr(hash('sha256', $run), 0, 8),
        'add_time' => $now, 'status' => 1, 'user_type' => 'h5', 'is_del' => 0,
    ]);
    $memberId = (int) Db::table('ch_tenant_member')->insertGetId([
        'tenant_id' => $scope['tenant_id'], 'uid' => $uid,
        'first_channel_id' => $scope['channel_id'], 'current_channel_id' => $scope['channel_id'],
        'referrer_uid' => 0, 'invite_code' => 'CH' . substr($run, 0, 14),
        'attribution_locked_time' => $now, 'tier' => 2, 'verification_status' => 2,
        'current_verification_id' => 0, 'status' => 1, 'join_time' => $now, 'certified_time' => $now,
        'tier_expire_time' => 0, 'current_membership_term_id' => 0, 'membership_version' => 1,
        'add_time' => $now, 'update_time' => $now, 'is_del' => 0,
    ]);
    Db::table('ch_point_account')->insert([
        'tenant_id' => $scope['tenant_id'], 'member_id' => $memberId, 'uid' => $uid,
        'balance' => 30000, 'frozen_balance' => 0, 'version' => 1,
        'add_time' => $now, 'update_time' => $now,
    ]);

    // 大咖（ch_expert，member_id=0 未关联会员）+ AI 分身 + 未来档期
    $expertId = (int) Db::table('ch_expert')->insertGetId([
        'tenant_id' => $scope['tenant_id'], 'name' => '验收大咖', 'title' => '测试',
        'company' => '', 'industry' => '', 'bio' => '',
        'online_points' => 10000, 'online_cash' => 0, 'offline_points' => 20000, 'offline_cash' => 0,
        'member_id' => 0,
        'add_time' => $now, 'update_time' => $now,
    ]);
    Db::table('ch_expert_ai')->insert([
        'tenant_id' => $scope['tenant_id'], 'member_id' => $expertId,
        'persona_name' => '验收大咖', 'chat_points_cost' => 20,
        'training_status' => 2, 'add_time' => $now, 'update_time' => $now,
    ]);
    $slotId = (int) Db::table('ch_expert_slot')->insertGetId([
        'tenant_id' => $scope['tenant_id'], 'expert_id' => $expertId,
        'start_time' => $now + 86400, 'end_time' => $now + 90000,
        'status' => 'open', 'location' => 0, 'add_time' => $now,
    ]);

    /** @var JwtAuth $jwt */
    $jwt = app()->make(JwtAuth::class);
    $token = $jwt->createToken($uid, 'api')['token'];
    // admin token：超管 id=1
    $adminToken = $jwt->createToken(1, 'admin')['token'];

    outputChamberJson([
        'token' => $token,
        'admin_token' => $adminToken,
        'uid' => $uid,
        'member_id' => $memberId,
        'expert_id' => $expertId,
        'slot_id' => $slotId,
    ]);
}

function inspectChamberHttpFixture(int $uid): void
{
    $member = Db::table('ch_tenant_member')->where('uid', $uid)->where('is_del', 0)->find();
    $account = is_array($member) ? Db::table('ch_point_account')->where('member_id', (int) $member['id'])->find() : null;
    outputChamberJson([
        'balance' => (int) ($account['balance'] ?? -1),
        'appointment_count' => (int) Db::table('ch_appointment')->where('uid', $uid)->count(),
        'notification_read_count' => (int) Db::table('ch_notification_read')
            ->where('member_id', is_array($member) ? (int) $member['id'] : -1)->count(),
    ]);
}

function cleanupChamberHttpFixture(array $tokens): void
{
    foreach ($tokens as $token) {
        if (is_string($token) && $token !== '') {
            CacheService::delete(md5($token));
        }
    }
    $uids = array_map('intval', Db::table('eb_user')->whereLike('account', 'ch\_http\_%')->column('uid'));
    if ($uids === []) {
        return;
    }
    $memberIds = array_map('intval', Db::table('ch_tenant_member')->whereIn('uid', $uids)->column('id'));
    $expertIds = array_map('intval', Db::table('ch_expert')->whereLike('name', '验收大咖')->column('id'));

    if ($memberIds !== []) {
        Db::table('ch_appointment')->whereIn('member_id', $memberIds)->delete();
        Db::table('ch_notification_read')->whereIn('member_id', $memberIds)->delete();
        Db::table('ch_point_ledger')->whereIn('member_id', $memberIds)
            ->whereIn('source_type', ['appointment', 'appointment_cancel', 'ai_twin_chat', 'ai_twin_chat_refund'])->delete();
        Db::table('ch_point_account')->whereIn('member_id', $memberIds)->delete();
        Db::table('ch_tenant_member')->whereIn('id', $memberIds)->delete();
    }
    if ($expertIds !== []) {
        Db::table('ch_expert_slot')->whereIn('expert_id', $expertIds)->delete();
        Db::table('ch_expert_ai')->whereIn('member_id', $expertIds)->delete();
        Db::table('ch_expert')->whereIn('id', $expertIds)->delete();
    }
    // 结算 fixture：order_no 在 ch_settlement，detail/payout 按 settlement 关联清理
    $settlementIds = Db::table('ch_settlement')->whereLike('order_no', 'CHTEST%')->column('id');
    if ($settlementIds) {
        $detailIds = Db::table('ch_settlement_detail')->whereIn('settlement_id', $settlementIds)->column('id');
        Db::table('ch_payout_record')->whereIn('settlement_detail_id', $detailIds ?: [0])->delete();
        Db::table('ch_settlement_detail')->whereIn('settlement_id', $settlementIds)->delete();
        Db::table('ch_settlement')->whereIn('id', $settlementIds)->delete();
    }
    Db::table('ch_settlement_rule')->where('business_type', 'membership_fee')->delete();
    Db::table('ch_settlement_balance')->where('id', '>', 0)->delete();
    Db::table('ch_payout_record')->where('id', '>', 0)->delete();
    Db::table('ch_event_notification')->whereLike('title', '验收通知%')->delete();
    if ($uids !== []) {
        Db::table('eb_user')->whereIn('uid', $uids)->delete();
    }
}

function chamberTenantScope(): array
{
    $row = Db::table('ch_tenant')->alias('tenant')
        ->join(['ch_channel' => 'channel'], 'channel.tenant_id=tenant.id')
        ->where('tenant.slug', 'local-primary')->where('channel.code', 'default')
        ->where('tenant.status', 1)->where('tenant.is_del', 0)
        ->where('channel.status', 1)->where('channel.is_del', 0)
        ->field('tenant.id AS tenant_id,channel.id AS channel_id')->find();
    if (!is_array($row)) {
        throw new RuntimeException('Local chamber tenant scope is unavailable');
    }
    return ['tenant_id' => (int) $row['tenant_id'], 'channel_id' => (int) $row['channel_id']];
}

function outputChamberJson(array $value): void
{
    $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($json)) {
        throw new RuntimeException('Fixture output could not be encoded');
    }
    fwrite(STDOUT, $json . "\n");
}
