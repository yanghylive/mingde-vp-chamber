<?php

declare(strict_types=1);

use app\chamber\exceptions\MemberTransactionException;
use app\chamber\identity\AuthenticatedUserContext;
use app\chamber\services\EventWaitlistService;
use app\chamber\tenancy\TenantContext;
use app\chamber\tenancy\TenantRecord;
use think\App;
use think\facade\Db;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

(new App())->initialize();

$now = time();
$runId = strtolower(bin2hex(random_bytes(6)));
$assertions = 0;
$sequence = 0;

Db::startTrans();
try {
    $tenantRow = waitlistFixtureTenant('local-primary');
    $channel = waitlistFixtureChannel((int) $tenantRow['id'], 'default');
    $otherTenant = waitlistFixtureTenant('local-secondary');
    $otherChannel = waitlistFixtureChannel((int) $otherTenant['id'], 'default');
    $tenant = waitlistTenantContext($tenantRow, $channel);
    $otherContext = waitlistTenantContext($otherTenant, $otherChannel);

    $uidA = (int) Db::table('ch_tenant_member')->max('uid') + random_int(1000, 10000);
    $uidB = $uidA + 1;
    $uidC = $uidA + 2;
    $memberA = waitlistCreateMember((int) $tenantRow['id'], (int) $channel['id'], $uidA, $now);
    $memberB = waitlistCreateMember((int) $tenantRow['id'], (int) $channel['id'], $uidB, $now);
    $memberC = waitlistCreateMember((int) $tenantRow['id'], (int) $channel['id'], $uidC, $now);
    $authA = new AuthenticatedUserContext($uidA, true, 'api');
    $authB = new AuthenticatedUserContext($uidB, true, 'api');
    $authC = new AuthenticatedUserContext($uidC, true, 'api');

    $clock = function () use ($now): int {
        return $now;
    };
    $waitlist = new EventWaitlistService($clock);

    // ---- 1. 未开启候补的票种：加入被拒 ----
    $plain = waitlistCreateTicket($tenantRow, $channel, $runId, $now, 1, 0, false);
    waitlistExpectReason('waitlist_not_enabled', 409, function () use ($waitlist, $tenant, $authA, $plain): void {
        $waitlist->join($tenant, $authA, $plain);
    });

    // ---- 2. 开启候补但仍有名额：提示直接报名 ----
    $available = waitlistCreateTicket($tenantRow, $channel, $runId, $now, 5, 0, true);
    waitlistExpectReason('ticket_available', 409, function () use ($waitlist, $tenant, $authA, $available): void {
        $waitlist->join($tenant, $authA, $available);
    });

    // ---- 3. 满员 + 开启候补：按加入顺序排队，重复加入幂等 ----
    $full = waitlistCreateTicket($tenantRow, $channel, $runId, $now, 2, 2, true);
    $joinA = $waitlist->join($tenant, $authA, $full);
    waitlistAssertSame('waiting', $joinA['status']);
    waitlistAssertSame(false, $joinA['already_joined']);
    waitlistAssertSame(1, $joinA['position']);
    $joinAReplay = $waitlist->join($tenant, $authA, $full);
    waitlistAssertSame(true, $joinAReplay['already_joined']);
    waitlistAssertSame($joinA['waitlist_id'], $joinAReplay['waitlist_id']);
    $joinB = $waitlist->join($tenant, $authB, $full);
    waitlistAssertSame(2, $joinB['position']);
    waitlistAssertSame(2, (int) Db::table('ch_event_waitlist')->where('ticket_id', $full)->count());

    // 候补不冻结积分、不建订单、不占席位（reserved 保持 0，仅 paid_count=2 占满）
    waitlistAssertSame(0, (int) Db::table('ch_event_ticket')->where('id', $full)->value('reserved_count'));
    waitlistAssertSame(2, (int) Db::table('ch_event_ticket')->where('id', $full)->value('paid_count'));
    waitlistAssertSame(0, (int) Db::table('ch_event_registration')
        ->whereIn('member_id', [$memberA, $memberB, $memberC])->count());

    // 我的候补列表（只含本人，租户隔离）
    $mineA = $waitlist->listForMember($tenant, $authA);
    waitlistAssertSame(1, count($mineA));
    waitlistAssertSame($joinA['waitlist_id'], $mineA[0]['waitlist_id']);
    waitlistAssertSame('waiting', $mineA[0]['status']);
    $mineB = $waitlist->listForMember($tenant, $authB);
    waitlistAssertSame(1, count($mineB));
    // 跨租户不可见：该会员不属于 secondary 租户，查询被拒（租户隔离）
    waitlistExpectReason('member_not_found', 404, function () use ($waitlist, $otherContext, $authC): void {
        $waitlist->listForMember($otherContext, $authC);
    });
    // 同租户但无候补 → 空列表
    $uidD = $uidC + 1;
    waitlistCreateMember((int) $tenantRow['id'], (int) $channel['id'], $uidD, $now);
    $authD = new AuthenticatedUserContext($uidD, true, 'api');
    waitlistAssertSame(0, count($waitlist->listForMember($tenant, $authD)));

    // ---- 4. 退出候补 ----
    $left = $waitlist->leave($tenant, $authB, $joinB['waitlist_id']);
    waitlistAssertSame('cancelled', $left['status']);
    waitlistExpectReason('waitlist_state_conflict', 409, function () use ($waitlist, $tenant, $authB, $joinB): void {
        $waitlist->leave($tenant, $authB, $joinB['waitlist_id']);
    });
    // 越权：会员不能退出他人的候补
    waitlistExpectReason('waitlist_not_found', 404, function () use ($waitlist, $tenant, $authC, $joinA): void {
        $waitlist->leave($tenant, $authC, $joinA['waitlist_id']);
    });
    // 重新排队可复用同一条记录
    $rejoin = $waitlist->join($tenant, $authB, $full);
    waitlistAssertSame($joinB['waitlist_id'], $rejoin['waitlist_id']);
    waitlistAssertSame(false, $rejoin['already_joined']);
    waitlistAssertSame('waiting', $rejoin['status']);
    waitlistAssertSame(2, (int) Db::table('ch_event_waitlist')->where('ticket_id', $full)->count());

    // ---- 5. 席位释放 → 按顺序自动转正 + 通知 ----
    $beforeNotices = (int) Db::table('ch_event_notification')->where('tenant_id', (int) $tenantRow['id'])->count();
    Db::table('ch_event_ticket')->where('id', $full)->update(['paid_count' => 1]);
    $promoted = $waitlist->promoteForTicket($full);
    waitlistAssertSame(1, $promoted['promoted']);
    $entryA = waitlistRow($joinA['waitlist_id']);
    waitlistAssertSame('promoted', (string) $entryA['status']);
    waitlistAssertSame(1, (int) $entryA['promote_seq']);
    waitlistAssertSame($now + EventWaitlistService::PROMOTE_WINDOW_SECONDS, (int) $entryA['promote_expire_time']);
    // 转正锁定一个席位（paid 2→1 释放 1 个空位，转正占用它）
    waitlistAssertSame(1, (int) Db::table('ch_event_ticket')->where('id', $full)->value('reserved_count'));
    // 通知已发且只发一次
    waitlistAssertSame($beforeNotices + 1, (int) Db::table('ch_event_notification')
        ->where('tenant_id', (int) $tenantRow['id'])->count());
    waitlistAssertSame(1, (int) Db::table('ch_event_notification')
        ->where('tenant_id', (int) $tenantRow['id'])
        ->where('member_id', $memberA)
        ->where('title', '候补转正通知')
        ->count());
    // 席位已满时不再继续转正
    $promotedAgain = $waitlist->promoteForTicket($full);
    waitlistAssertSame(0, $promotedAgain['promoted']);
    waitlistAssertSame('waiting', (string) waitlistRow($rejoin['waitlist_id'])['status']);

    // activePromotion / markConverted 回写
    $promotion = $waitlist->activePromotion($tenant, $authA, $full);
    waitlistAssertTrue(is_array($promotion), 'expected an active promotion');
    waitlistAssertSame($joinA['waitlist_id'], (int) $promotion['waitlist_id']);
    $waitlist->markConverted($joinA['waitlist_id'], 987654);
    $converted = waitlistRow($joinA['waitlist_id']);
    waitlistAssertSame('converted', (string) $converted['status']);
    waitlistAssertSame(987654, (int) $converted['registration_id']);
    waitlistAssertSame(0, (int) $converted['promote_expire_time']);
    // 已转正的条目不再计入候补
    waitlistAssertSame(null, $waitlist->activePromotion($tenant, $authA, $full));
    // A 完成报名：转正占用的席位转为正式报名（reserved 1→0, paid 1→2，仍满员）
    Db::table('ch_event_ticket')->where('id', $full)->update(['reserved_count' => 0, 'paid_count' => 2]);
    // 该席位随后被退款释放（paid 2→1），转正下一位
    Db::table('ch_event_ticket')->where('id', $full)->update(['paid_count' => 1]);
    $next = $waitlist->promoteForTicket($full);
    waitlistAssertSame(1, $next['promoted']);
    waitlistAssertSame('promoted', (string) waitlistRow($rejoin['waitlist_id'])['status']);
    waitlistAssertSame(1, (int) Db::table('ch_event_ticket')->where('id', $full)->value('reserved_count'));

    // ---- 6. 转正窗口过期 → 释放席位并顺延 ----
    $expireClock = function () use ($now): int {
        return $now + EventWaitlistService::PROMOTE_WINDOW_SECONDS + 1;
    };
    $expiring = new EventWaitlistService($expireClock);
    $expired = $expiring->expirePromotions(10);
    waitlistAssertSame(1, $expired['expired']);
    waitlistAssertSame('expired', (string) waitlistRow($rejoin['waitlist_id'])['status']);
    waitlistAssertSame(0, (int) Db::table('ch_event_ticket')->where('id', $full)->value('reserved_count'));

    // ---- 7. 幂等与非法输入 ----
    waitlistExpectReason('request_validation_failed', 422, function () use ($waitlist, $tenant, $authA): void {
        $waitlist->join($tenant, $authA, 0);
    });
    waitlistExpectReason('request_validation_failed', 422, function () use ($waitlist, $tenant, $authA): void {
        $waitlist->leave($tenant, $authA, 0);
    });
    // 跨租户退出：先过租户校验（member 查不到）→ member_not_found
    waitlistExpectReason('member_not_found', 404, function () use ($waitlist, $otherContext, $authC, $joinA): void {
        $waitlist->leave($otherContext, $authC, $joinA['waitlist_id']);
    });
    waitlistExpectReason('ticket_not_found', 404, function () use ($waitlist, $tenant, $authA): void {
        $waitlist->join($tenant, $authA, 2147483647);
    });
    // 重复过期回收不重复释放席位
    $reservedBefore = (int) Db::table('ch_event_ticket')->where('id', $full)->value('reserved_count');
    $again = $expiring->expirePromotions(10);
    waitlistAssertSame(0, $again['expired']);
    waitlistAssertSame($reservedBefore, (int) Db::table('ch_event_ticket')->where('id', $full)->value('reserved_count'));

    fwrite(STDOUT, sprintf(
        "Event waitlist database integration passed (%d assertions).\n",
        $assertions
    ));
} finally {
    Db::rollback();
}

function waitlistFixtureTenant(string $slug): array
{
    $row = Db::table('ch_tenant')->where('slug', $slug)->where('status', 1)->where('is_del', 0)->find();
    if (!is_array($row)) {
        throw new RuntimeException('Tenant fixture was not found: ' . $slug);
    }

    return $row;
}

function waitlistFixtureChannel(int $tenantId, string $code): array
{
    $row = Db::table('ch_channel')
        ->where('tenant_id', $tenantId)->where('code', $code)
        ->where('status', 1)->where('is_del', 0)->find();
    if (!is_array($row)) {
        throw new RuntimeException('Channel fixture was not found');
    }

    return $row;
}

function waitlistTenantContext(array $tenant, array $channel): TenantContext
{
    return new TenantContext(new TenantRecord(
        (int) $tenant['id'],
        (string) $tenant['slug'],
        (int) $channel['id'],
        (string) $channel['code'],
        true
    ), 'event-waitlist-db-test');
}

function waitlistCreateMember(int $tenantId, int $channelId, int $uid, int $now): int
{
    return (int) Db::table('ch_tenant_member')->insertGetId([
        'tenant_id' => $tenantId, 'uid' => $uid,
        'first_channel_id' => $channelId, 'current_channel_id' => $channelId,
        'referrer_uid' => 0, 'tier' => 2, 'verification_status' => 2,
        'primary_role_id' => 0, 'status' => 1, 'join_time' => $now,
        'certified_time' => $now, 'tier_expire_time' => 0,
        'add_time' => $now, 'update_time' => $now, 'is_del' => 0,
    ]);
}

function waitlistCreateTicket(
    array $tenantRow,
    array $channel,
    string $runId,
    int $now,
    int $capacity,
    int $paidCount,
    bool $waitlistEnabled
): int {
    global $sequence;
    $sequence++;
    $tenantId = (int) $tenantRow['id'];
    $eventId = (int) Db::table('ch_event')->insertGetId([
        'tenant_id' => $tenantId, 'channel_id' => (int) $channel['id'],
        'event_no' => substr('WL' . strtoupper($runId) . $sequence, 0, 32),
        'event_type' => 'growth', 'title' => 'Waitlist fixture',
        'cover_image' => '', 'summary' => '', 'tags_json' => '[]', 'speakers_json' => '[]', 'detail' => '',
        'start_time' => $now + 7200, 'end_time' => $now + 10800,
        'signup_start_time' => $now - 3600, 'signup_end_time' => $now + 3600,
        'location_name' => 'Local', 'address' => 'Local',
        'longitude' => '123.000000', 'latitude' => '41.000000',
        'min_tier' => 2, 'eligibility_json' => '{}', 'refund_policy_json' => '{}',
        'checkin_reward_points' => 0, 'checkin_reward_contribution' => 0,
        'status' => 1, 'created_admin_id' => 0, 'publish_time' => $now,
        'add_time' => $now, 'update_time' => $now, 'is_del' => 0,
    ]);

    return (int) Db::table('ch_event_ticket')->insertGetId([
        'tenant_id' => $tenantId, 'event_id' => $eventId, 'name' => 'Waitlist fixture ticket',
        'price' => '10.00', 'integral_price' => 0,
        'product_id' => 1, 'product_attr_unique' => 'wlsku000' . $sequence,
        'capacity' => $capacity, 'reserved_count' => 0, 'paid_count' => $paidCount,
        'min_tier' => 2, 'eligibility_json' => '{}', 'refund_policy_json' => '{}',
        'sale_start_time' => $now - 3600, 'sale_end_time' => $now + 3600,
        'status' => 1, 'sort' => 1, 'add_time' => $now, 'update_time' => $now, 'is_del' => 0,
        'waitlist_enabled' => $waitlistEnabled ? 1 : 0,
    ]);
}

function waitlistRow(int $waitlistId): array
{
    $row = Db::table('ch_event_waitlist')->where('id', $waitlistId)->find();
    if (!is_array($row)) {
        throw new RuntimeException('Waitlist fixture was not found');
    }

    return $row;
}

function waitlistExpectReason(string $reason, int $status, callable $callback): void
{
    try {
        $callback();
    } catch (MemberTransactionException $exception) {
        waitlistAssertSame($reason, $exception->reason());
        waitlistAssertSame($status, $exception->httpStatus());
        return;
    }

    throw new RuntimeException('Expected member transaction exception: ' . $reason);
}

function waitlistAssertTrue(bool $actual, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$actual) {
        throw new RuntimeException($message);
    }
}

function waitlistAssertSame($expected, $actual): void
{
    global $assertions;
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf(
            'Expected %s, got %s',
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
}
