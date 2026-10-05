<?php

declare(strict_types=1);

use app\chamber\activity\CourseBookingRequest;
use app\chamber\activity\CourseCheckinRequest;
use app\chamber\identity\AuthenticatedAdminContext;
use app\chamber\identity\AuthenticatedUserContext;
use app\chamber\membership\CourseConsumptionPolicy;
use app\chamber\membership\CoursePackageCheckoutRequest;
use app\chamber\services\CourseBookingService;
use app\chamber\services\CoursePackageCheckoutService;
use app\chamber\services\CourseSessionService;
use app\chamber\services\CreditAccountService;
use app\chamber\services\CreditExpiryJob;
use app\chamber\services\FamilyService;
use app\chamber\tenancy\TenantContext;
use app\chamber\tenancy\TenantRecord;
use think\App;
use think\facade\Db;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

(new App())->initialize();

$now = time();
$runId = strtolower(bin2hex(random_bytes(6)));
$assertions = 0;

Db::startTrans();
try {
    $primaryTenant = tenant('local-primary');
    $primaryChannel = channel((int) $primaryTenant['id'], 'default');
    $tenant = context($primaryTenant, $primaryChannel);
    $uid = (int) Db::table('ch_tenant_member')->max('uid') + random_int(100, 10000);
    $memberId = createMember((int) $primaryTenant['id'], (int) $primaryChannel['id'], $uid, $now);
    $auth = new AuthenticatedUserContext($uid, true, 'api');

    // ===== P1 购包闭环：免费包 → 账户 + 授予 + 账本 =====
    $pkgId = insertPackage((int) $primaryTenant['id'], (int) $primaryChannel['id'], 'FREE-PRIV-' . $runId, CourseConsumptionPolicy::TYPE_PRIVATE, '10.00', 6, 0, $now);
    $checkout = new CoursePackageCheckoutService();
    $freeKey = 'course-pkg-free-' . $runId;
    $free = $checkout->checkout($tenant, $auth, CoursePackageCheckoutRequest::fromArray([
        'package_id' => $pkgId,
        'coach_tier' => 0,
        'expected_amount' => null,
        'currency' => 'CNY',
    ]), $freeKey);
    assertSame(false, $free['payment_required']);
    assertSame('10.00', $free['granted_hours']);
    $personalAccount = Db::table('ch_credit_account')->where('id', $free['account_id'])->find();
    assertTrue(is_array($personalAccount), 'personal credit account created');
    assertSame('10.00', (string) $personalAccount['balance_hours']);
    assertSame(1, (int) Db::table('ch_credit_grant')->where('account_id', $free['account_id'])->count());
    assertSame(1, (int) Db::table('ch_credit_ledger')->where('account_id', $free['account_id'])->count());

    // 幂等重放：同一键不重复充值
    $freeReplay = $checkout->checkout($tenant, $auth, CoursePackageCheckoutRequest::fromArray([
        'package_id' => $pkgId, 'coach_tier' => 0, 'expected_amount' => null, 'currency' => 'CNY',
    ]), $freeKey);
    assertSame('10.00', (string) Db::table('ch_credit_account')->where('id', $free['account_id'])->value('balance_hours'));
    assertSame(1, (int) Db::table('ch_credit_grant')->where('account_id', $free['account_id'])->count());
    assertSame(
        BootstrapIdempotencyStub::same((array) $free, (array) $freeReplay),
        true
    );

    // 付费包 → 创建订单上下文（待支付），不充值课时
    $paidPkgId = insertPackage((int) $primaryTenant['id'], (int) $primaryChannel['id'], 'PAID-PRIV-' . $runId, CourseConsumptionPolicy::TYPE_PRIVATE, '120.00', 12, 1, $now);
    $paid = $checkout->checkout($tenant, $auth, CoursePackageCheckoutRequest::fromArray([
        'package_id' => $paidPkgId, 'coach_tier' => 0, 'expected_amount' => '120.00', 'currency' => 'CNY',
    ]), 'course-pkg-paid-' . $runId);
    assertSame(true, $paid['payment_required']);
    assertTrue(isset($paid['context_no']) && $paid['context_no'] !== '', 'paid package order context created');
    assertSame(1, (int) Db::table('ch_order_context')->where('business_type', 'course_package')->where('business_id', $paidPkgId)->count());
    assertSame('10.00', (string) Db::table('ch_credit_account')->where('id', $free['account_id'])->value('balance_hours'));

    // ===== P2 约课 + 签到闭环 =====
    $admin = new AuthenticatedAdminContext(900001, true, []);
    $sessionService = new CourseSessionService();
    $session = $sessionService->create($tenant, $admin, [
        'course_type' => CourseConsumptionPolicy::TYPE_PRIVATE,
        'coach_id' => 0,
        'title' => '测试私教课',
        'start_time' => $now + 7200,
        'end_time' => $now + 10800,
        'duration_hours' => '1.00',
        'capacity' => 4,
        'location' => ['name' => '测试场地', 'address' => '测试路 1 号'],
        'product_id' => 0,
        'status' => 1,
    ], 'course-session-1-' . $runId);
    $sessionId = (int) $session['id'];
    assertSame(1, (int) $session['status']);

    $bookingService = new CourseBookingService();
    $booking = $bookingService->book($tenant, $auth, $sessionId, CourseBookingRequest::fromArray(['participants' => 1]), 'course-book-1-' . $runId);
    assertSame(1, $booking['status']);
    assertSame('1.00', $booking['credit_cost_hours']);
    assertSame('10.00', (string) Db::table('ch_credit_account')->where('id', $free['account_id'])->value('balance_hours'));
    assertSame(1, (int) Db::table('ch_course_session')->where('id', $sessionId)->value('booked_count'));

    $token = $sessionService->issueCheckinToken($tenant, $admin, $sessionId, 300, 'course-token-1-' . $runId);
    assertTrue(isset($token['token']) && $token['token'] !== '', 'checkin token issued');
    $checkin = $bookingService->checkinByToken($tenant, $auth, $sessionId, CourseCheckinRequest::fromArray([
        'token' => $token['token'],
    ]), 'course-checkin-1-' . $runId);
    assertSame(3, $checkin['status']);
    assertSame('9.00', (string) Db::table('ch_credit_account')->where('id', $free['account_id'])->value('balance_hours'));
    assertSame(1, (int) Db::table('ch_credit_ledger')->where('account_id', $free['account_id'])->where('source_type', 'session_consume')->count());

    $checkinReplay = $bookingService->checkinByToken($tenant, $auth, $sessionId, CourseCheckinRequest::fromArray([
        'token' => $token['token'],
    ]), 'course-checkin-1-' . $runId);
    assertSame('9.00', (string) Db::table('ch_credit_account')->where('id', $free['account_id'])->value('balance_hours'));
    assertSame(
        BootstrapIdempotencyStub::same((array) $checkin, (array) $checkinReplay),
        true
    );

    // 手动签到路径
    $session2 = $sessionService->create($tenant, $admin, [
        'course_type' => CourseConsumptionPolicy::TYPE_PRIVATE,
        'coach_id' => 0,
        'title' => '测试私教课二',
        'start_time' => $now + 7200,
        'end_time' => $now + 10800,
        'duration_hours' => '1.00',
        'capacity' => 4,
        'location' => ['name' => '测试场地', 'address' => '测试路 1 号'],
        'product_id' => 0,
        'status' => 1,
    ], 'course-session-2-' . $runId);
    $booking2 = $bookingService->book($tenant, $auth, (int) $session2['id'], CourseBookingRequest::fromArray(['participants' => 1]), 'course-book-2-' . $runId);
    $manual = $bookingService->manualCheckin($tenant, $admin, (int) $session2['id'], (int) $booking2['booking_id'], 'operator verified', 'course-manual-1-' . $runId);
    assertSame(3, $manual['status']);
    assertSame('8.00', (string) Db::table('ch_credit_account')->where('id', $free['account_id'])->value('balance_hours'));

    // ===== P3 家庭共享 =====
    $familyPkgId = insertPackage((int) $primaryTenant['id'], (int) $primaryChannel['id'], 'FREE-FAM-' . $runId, CourseConsumptionPolicy::TYPE_FAMILY, '20.00', 6, 0, $now);
    $familyCheckout = $checkout->checkout($tenant, $auth, CoursePackageCheckoutRequest::fromArray([
        'package_id' => $familyPkgId, 'coach_tier' => 0, 'expected_amount' => null, 'currency' => 'CNY',
    ]), 'course-pkg-family-' . $runId);
    assertSame('family', $familyCheckout['owner_type']);
    assertSame('20.00', $familyCheckout['granted_hours']);
    $familyAccountId = (int) $familyCheckout['account_id'];
    $familyRow = Db::table('ch_family')->where('id', $familyCheckout['owner_id'])->find();
    assertTrue(is_array($familyRow), 'family created for family package');
    assertSame(1, (int) Db::table('ch_family_member')->where('family_id', $familyCheckout['owner_id'])->where('uid', $uid)->count());

    $famSession = $sessionService->create($tenant, $admin, [
        'course_type' => CourseConsumptionPolicy::TYPE_FAMILY,
        'coach_id' => 0,
        'title' => '测试家庭课',
        'start_time' => $now + 7200,
        'end_time' => $now + 10800,
        'duration_hours' => '1.00',
        'capacity' => 6,
        'location' => ['name' => '家庭场地', 'address' => '家庭路 1 号'],
        'product_id' => 0,
        'status' => 1,
    ], 'course-session-fam-' . $runId);
    $famBooking = $bookingService->book($tenant, $auth, (int) $famSession['id'], CourseBookingRequest::fromArray(['participants' => 1]), 'course-book-fam-' . $runId);
    assertSame('family', $famBooking['owner_type']);
    assertSame($familyAccountId, (int) $famBooking['account_id']);
    assertSame('1.50', $famBooking['credit_cost_hours']);
    $famToken = $sessionService->issueCheckinToken($tenant, $admin, (int) $famSession['id'], 300, 'course-token-fam-' . $runId);
    $famCheckin = $bookingService->checkinByToken($tenant, $auth, (int) $famSession['id'], CourseCheckinRequest::fromArray([
        'token' => $famToken['token'],
    ]), 'course-checkin-fam-' . $runId);
    assertSame(3, $famCheckin['status']);
    assertSame('18.50', (string) Db::table('ch_credit_account')->where('id', $familyAccountId)->value('balance_hours'));

    // 家庭成员加入。
    // 2026-10-05 合并时修正：生产 FamilyService::addMember 已改为「自助加入」语义
    // （登录会员凭家庭 ID 加入，忽略 member_uid，见该方法注释），加入者是调用者本人。
    // 生产带来的本测试仍按旧「指定 member_uid 加入」语义断言，故修正为新语义。
    $familySvc = new FamilyService();
    $myFamily = $familySvc->create($tenant, $auth, ['name' => '我的测试家庭'], 'fam-create-' . $runId);
    $joined = $familySvc->addMember($tenant, $auth, (int) $myFamily['id'], ['relation' => 'child'], 'fam-join-' . $runId);
    assertSame((int) $myFamily['id'], (int) $joined['id']);
    assertSame(1, (int) Db::table('ch_family_member')->where('family_id', (int) $myFamily['id'])->where('uid', $uid)->count());
    // 幂等：同 key 重放不重复入成员
    $joinedReplay = $familySvc->addMember($tenant, $auth, (int) $myFamily['id'], ['relation' => 'child'], 'fam-join-' . $runId);
    assertSame((int) $myFamily['id'], (int) $joinedReplay['id']);
    assertSame(1, (int) Db::table('ch_family_member')->where('family_id', (int) $myFamily['id'])->where('uid', $uid)->count());

    // ===== P4 定时作废：到期授予冲减余额 =====
    $credits = new CreditAccountService();
    $expAccount = $credits->ensureAccount((int) $primaryTenant['id'], 'member', $memberId + 999, $uid, $now);
    Db::table('ch_credit_account')->where('id', $expAccount['id'])->update(['balance_hours' => '5.00', 'update_time' => $now]);
    Db::table('ch_credit_ledger')->insertGetId([
        'tenant_id' => (int) $primaryTenant['id'],
        'account_id' => (int) $expAccount['id'],
        'member_id' => (int) $expAccount['owner_id'],
        'uid' => $uid,
        'delta_hours' => '5.00',
        'balance_after' => '5.00',
        'source_type' => 'package_purchase',
        'source_id' => 'expire-fixture',
        'idempotency_key' => hash('sha256', 'expire-fixture:' . $runId),
        'status' => 1,
        'reversal_id' => 0,
        'add_time' => $now,
    ]);
    $expGrantId = (int) Db::table('ch_credit_grant')->insertGetId([
        'tenant_id' => (int) $primaryTenant['id'],
        'account_id' => (int) $expAccount['id'],
        'package_id' => $pkgId,
        'member_id' => (int) $expAccount['owner_id'],
        'uid' => $uid,
        'hours_total' => '5.00',
        'hours_remaining' => '5.00',
        'expire_time' => $now - 86400,
        'source_order_context_id' => 0,
        'status' => 1,
        'add_time' => $now,
        'update_time' => $now,
    ]);
    $summary = (new CreditExpiryJob())->run((int) $primaryTenant['id'], 200, $now);
    assertTrue($summary['forfeited'] >= 1, 'at least one expired grant forfeited');
    assertSame('0.00', (string) Db::table('ch_credit_account')->where('id', $expAccount['id'])->value('balance_hours'));
    assertSame(2, (int) Db::table('ch_credit_grant')->where('id', $expGrantId)->value('status'));
    assertSame(1, (int) Db::table('ch_credit_ledger')->where('account_id', $expAccount['id'])->where('source_type', 'expire_forfeit')->count());

    fwrite(STDOUT, sprintf("Course domain database integration passed (%d assertions).\n", $assertions));
} finally {
    Db::rollback();
}

function tenant(string $slug): array
{
    $row = Db::table('ch_tenant')->where('slug', $slug)->where('status', 1)->where('is_del', 0)->find();
    if (!is_array($row)) {
        throw new RuntimeException('Tenant fixture was not found: ' . $slug);
    }

    return $row;
}

function channel(int $tenantId, string $code): array
{
    $row = Db::table('ch_channel')
        ->where('tenant_id', $tenantId)
        ->where('code', $code)
        ->where('status', 1)
        ->where('is_del', 0)
        ->find();
    if (!is_array($row)) {
        throw new RuntimeException('Channel fixture was not found');
    }

    return $row;
}

function context(array $tenant, array $channel): TenantContext
{
    return new TenantContext(new TenantRecord(
        (int) $tenant['id'],
        (string) $tenant['slug'],
        (int) $channel['id'],
        (string) $channel['code'],
        true
    ), 'course-db-test');
}

function createMember(int $tenantId, int $channelId, int $uid, int $now): int
{
    return (int) Db::table('ch_tenant_member')->insertGetId([
        'tenant_id' => $tenantId,
        'uid' => $uid,
        'first_channel_id' => $channelId,
        'current_channel_id' => $channelId,
        'referrer_uid' => 0,
        'tier' => 2,
        'verification_status' => 2,
        'primary_role_id' => 0,
        'status' => 1,
        'join_time' => $now,
        'certified_time' => $now,
        'tier_expire_time' => 0,
        'add_time' => $now,
        'update_time' => $now,
        'is_del' => 0,
    ]);
}

function insertPackage(int $tenantId, int $channelId, string $code, string $courseType, string $totalHours, int $validity, int $productId, int $now): int
{
    return (int) Db::table('ch_course_package')->insertGetId([
        'tenant_id' => $tenantId,
        'channel_id' => $channelId,
        'code' => $code,
        'version' => 1,
        'name' => '测试课时包 ' . $code,
        'course_type' => $courseType,
        'total_hours' => $totalHours,
        'validity_months' => $validity,
        'session_duration_hours' => '1.00',
        'frequency' => '',
        'min_participants' => 1,
        'max_participants' => 1,
        'price' => $totalHours,
        'currency' => 'CNY',
        'product_id' => $productId,
        'product_attr_unique' => '',
        'benefits_json' => json_encode(['测试权益']),
        'refund_policy_json' => json_encode(['不退不换']),
        'status' => 1,
        'effective_time' => $now - 10,
        'end_time' => 0,
        'add_time' => $now,
        'update_time' => $now,
    ]);
}

function assertTrue(bool $actual, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$actual) {
        throw new RuntimeException($message);
    }
}

function assertSame($expected, $actual): void
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

// 轻量幂等结果比较（避免重复引入 BootstrapIdempotency 的 canonicalJson 依赖）
class BootstrapIdempotencyStub
{
    public static function same(array $a, array $b): bool
    {
        ksort($a);
        ksort($b);

        return json_encode($a) === json_encode($b);
    }
}
