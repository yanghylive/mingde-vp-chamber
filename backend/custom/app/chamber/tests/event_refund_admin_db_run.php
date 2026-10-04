<?php

declare(strict_types=1);

use app\chamber\activity\EventRefundRequest;
use app\chamber\commerce\EventRefundGatewayResult;
use app\chamber\commerce\RefundAttemptState;
use app\chamber\contracts\EventRefundGatewayInterface;
use app\chamber\exceptions\MemberTransactionException;
use app\chamber\identity\AuthenticatedAdminContext;
use app\chamber\identity\AuthenticatedUserContext;
use app\chamber\membership\OrderContextState;
use app\chamber\services\EventIdempotency;
use app\chamber\services\EventRegistrationCommerceProjection;
use app\chamber\services\EventRegistrationRefundService;
use app\chamber\services\EventService;
use app\chamber\services\ThinkDbCommerceEventStore;
use app\chamber\tenancy\TenantContext;
use app\chamber\tenancy\TenantRecord;
use think\App;
use think\facade\Db;

require dirname(__DIR__, 3) . '/vendor/autoload.php';
require __DIR__ . '/event_refund_gateway_fixture.php';

(new App())->initialize();

$now = time();
$runId = strtolower(bin2hex(random_bytes(6)));
$assertions = 0;
$sequence = 0;

Db::startTrans();
try {
    $tenantRow = refundFixtureTenant('local-primary');
    $channel = refundFixtureChannel((int) $tenantRow['id'], 'default');
    $otherTenant = refundFixtureTenant('local-secondary');
    $otherChannel = refundFixtureChannel((int) $otherTenant['id'], 'default');
    $tenant = refundTenantContext($tenantRow, $channel, 'refund-admin-db-test');
    $otherTenantContext = refundTenantContext($otherTenant, $otherChannel, 'refund-admin-db-test');
    $uid = (int) Db::table('ch_tenant_member')->max('uid') + random_int(1000, 10000);
    $memberId = refundCreateMember((int) $tenantRow['id'], (int) $channel['id'], $uid, $now);
    $admin = new AuthenticatedAdminContext(9001, false, []);

    $clock = function () use ($now): int {
        return $now;
    };
    $store = new ThinkDbCommerceEventStore();
    $projection = new EventRegistrationCommerceProjection();
    $service = new EventRegistrationRefundService(
        new EventService($clock),
        new EventIdempotency($clock),
        $store,
        $projection,
        new StubRefundGateway('processing'),
        $clock
    );

    // ---- 1. 人工确认主路径：unknown → manual，积分/席位冲正 ----
    $paid = refundCreatePaidRegistration(
        $tenantRow,
        $channel,
        $memberId,
        $uid,
        $runId,
        $now,
        OrderContextState::REFUND_PROCESSING
    );
    $attemptId = refundCreateAttempt(
        $tenantRow,
        $channel,
        $paid,
        $uid,
        $runId,
        $now,
        RefundAttemptState::UNKNOWN,
        'manual_confirmation_required'
    );
    $confirmed = $service->confirmManually($tenant, $admin, $attemptId, 'finance-offline-T1');
    refundAssertSame(true, $confirmed['confirmed']);
    refundAssertSame(false, $confirmed['replayed']);
    refundAssertSame('manual', $confirmed['status']);
    refundAssertSame(9001, $confirmed['manual_operator_id']);
    refundAssertSame('finance-offline-T1', $confirmed['manual_reference']);
    $attempt = refundAttemptRow($attemptId);
    refundAssertSame('manual', (string) $attempt['status']);
    refundAssertSame('manual_confirmed', (string) $attempt['provider_status']);
    refundAssertSame(1, (int) $attempt['final_confirmed']);
    refundAssertSame(RefundAttemptState::SOURCE_MANUAL, (string) $attempt['final_confirm_source']);
    refundAssertSame(0, (int) $attempt['next_query_time']);
    refundAssertSame('', (string) $attempt['lease_token']);
    refundAssertSame('10.00', (string) Db::table('ch_order_context')->where('id', $paid['context_id'])->value('refunded_amount'));
    refundAssertSame(
        OrderContextState::REFUND_COMPLETED,
        (int) Db::table('ch_order_context')->where('id', $paid['context_id'])->value('refund_status')
    );
    refundAssertSame(3, (int) Db::table('ch_event_registration')->where('id', $paid['registration_id'])->value('status'));
    refundAssertSame(1, (int) Db::table('ch_event_registration_effect')
        ->where('tenant_id', (int) $tenantRow['id'])
        ->where('registration_id', $paid['registration_id'])
        ->where('effect_type', 'full_refund')
        ->count());
    refundAssertSame(1, (int) Db::table('ch_refund_attempt_audit')
        ->where('refund_attempt_id', $attemptId)
        ->where('action', 'manual_confirmed')
        ->where('actor_type', 'admin')
        ->where('actor_id', 9001)
        ->count());
    refundAssertSame(0, (int) Db::table('ch_event_ticket')->where('id', $paid['ticket_id'])->value('paid_count'));

    // ---- 2. 重复确认幂等：不产生新效果/审计 ----
    $effectsBefore = (int) Db::table('ch_event_registration_effect')
        ->where('registration_id', $paid['registration_id'])->count();
    $auditsBefore = (int) Db::table('ch_refund_attempt_audit')
        ->where('refund_attempt_id', $attemptId)->count();
    $replayed = $service->confirmManually($tenant, $admin, $attemptId, 'finance-offline-T2');
    refundAssertSame(true, $replayed['confirmed']);
    refundAssertSame(true, $replayed['replayed']);
    refundAssertSame('manual', $replayed['status']);
    refundAssertSame($effectsBefore, (int) Db::table('ch_event_registration_effect')
        ->where('registration_id', $paid['registration_id'])->count());
    refundAssertSame($auditsBefore, (int) Db::table('ch_refund_attempt_audit')
        ->where('refund_attempt_id', $attemptId)->count());

    // ---- 3. 非法输入与状态拒绝 ----
    refundExpectReason('request_validation_failed', 422, function () use ($service, $tenant, $admin): void {
        $service->confirmManually($tenant, $admin, 0, 'x');
    });
    refundExpectReason('refund_attempt_not_found', 404, function () use ($service, $tenant, $admin): void {
        $service->confirmManually($tenant, $admin, 2147483647, 'x');
    });
    refundExpectReason('refund_attempt_not_found', 404, function () use ($service, $otherTenantContext, $admin, $attemptId): void {
        $service->confirmManually($otherTenantContext, $admin, $attemptId, 'x');
    });
    refundExpectReason('request_validation_failed', 422, function () use ($service, $tenant, $admin, $paid, $channel, $tenantRow, $runId, $now): void {
        $pending = refundCreateAttempt(
            $tenantRow, $channel, $paid, 0, $runId, $now, RefundAttemptState::PROCESSING, 'provider_accepted'
        );
        $service->confirmManually($tenant, $admin, $pending, '');
    });

    $succeeded = refundCreatePaidRegistration(
        $tenantRow, $channel, $memberId, $uid, $runId, $now, OrderContextState::REFUND_COMPLETED, 'S'
    );
    $succeededAttempt = refundCreateAttempt(
        $tenantRow, $channel, $succeeded, $uid, $runId, $now, RefundAttemptState::SUCCEEDED, 'balance_posted', 'D'
    );
    refundExpectReason('refund_state_conflict', 409, function () use ($service, $tenant, $admin, $succeededAttempt): void {
        $service->confirmManually($tenant, $admin, $succeededAttempt, 'finance-offline-T3');
    });
    $failedPaid = refundCreatePaidRegistration(
        $tenantRow, $channel, $memberId, $uid, $runId, $now, OrderContextState::REFUND_PROCESSING, 'F'
    );
    $failedAttempt = refundCreateAttempt(
        $tenantRow, $channel, $failedPaid, $uid, $runId, $now, RefundAttemptState::FAILED, 'application_rejected', 'E'
    );
    refundExpectReason('refund_state_conflict', 409, function () use ($service, $tenant, $admin, $failedAttempt): void {
        $service->confirmManually($tenant, $admin, $failedAttempt, 'finance-offline-T4');
    });

    // ---- 4. 渠道明确拒绝 → REFUND_FAILED 事件 → context 可重试 ----
    $rejectPaid = refundCreatePaidRegistration(
        $tenantRow, $channel, $memberId, $uid, $runId, $now, OrderContextState::REFUND_PROCESSING, 'R'
    );
    $rejectAttempt = refundCreateAttempt(
        $tenantRow, $channel, $rejectPaid, $uid, $runId, $now, RefundAttemptState::PROCESSING, 'application_pending', 'G'
    );
    Db::table('ch_refund_attempt')->where('id', $rejectAttempt)->update([
        'next_query_time' => $now - 1,
    ]);
    $rejectService = new EventRegistrationRefundService(
        new EventService($clock),
        new EventIdempotency($clock),
        $store,
        $projection,
        new StubRefundGateway('failed'),
        $clock
    );
    $querySummary = $rejectService->queryPending(10);
    refundAssertSame(1, $querySummary['scanned']);
    refundAssertSame('failed', (string) refundAttemptRow($rejectAttempt)['status']);
    refundAssertSame(
        OrderContextState::REFUND_FAILED,
        (int) Db::table('ch_order_context')->where('id', $rejectPaid['context_id'])->value('refund_status')
    );
    refundAssertSame(1, (int) Db::table('ch_commerce_event_inbox')
        ->where('business_type', 'event_registration')
        ->where('event_type', 'commerce.refund.failed.v1')
        ->where('payload_hash', Db::table('ch_commerce_event_inbox')
            ->where('business_type', 'event_registration')->order('id', 'desc')->value('payload_hash'))
        ->count());

    // ---- 5. 拒绝后会员可重新发起（新尝试生成，context 回到处理中） ----
    $retryService = new EventRegistrationRefundService(
        new EventService($clock),
        new EventIdempotency($clock),
        $store,
        $projection,
        new StubRefundGateway('processing'),
        $clock
    );
    $memberAuth = new AuthenticatedUserContext($uid, true, 'api');
    $attemptsBefore = (int) Db::table('ch_refund_attempt')
        ->where('tenant_id', (int) $tenantRow['id'])
        ->where('source_id', (string) $rejectPaid['registration_id'])->count();
    $retryService->refund(
        $tenant,
        $memberAuth,
        $rejectPaid['registration_id'],
        EventRefundRequest::fromArray(['reason' => 'retry after channel rejection']),
        'refund-admin-retry-' . $runId
    );
    refundAssertSame($attemptsBefore + 1, (int) Db::table('ch_refund_attempt')
        ->where('tenant_id', (int) $tenantRow['id'])
        ->where('source_id', (string) $rejectPaid['registration_id'])->count());
    refundAssertSame(
        OrderContextState::REFUND_PROCESSING,
        (int) Db::table('ch_order_context')->where('id', $rejectPaid['context_id'])->value('refund_status')
    );

    // ---- 6. 管理端列表/详情：租户隔离、状态筛选、分页、校验 ----
    $listed = $service->listAttemptsForAdmin($tenant, []);
    refundAssertTrue($listed['total'] >= 5, 'expected seeded attempts');
    refundAssertSame(1, $listed['page']);
    refundAssertSame(20, $listed['limit']);
    foreach ($listed['items'] as $item) {
        refundAssertTrue(isset($item['id'], $item['refund_no'], $item['status']), 'attempt shape');
    }
    $manualOnly = $service->listAttemptsForAdmin($tenant, ['status' => 'manual']);
    refundAssertTrue($manualOnly['total'] >= 1, 'expected manual attempts');
    foreach ($manualOnly['items'] as $item) {
        refundAssertSame('manual', $item['status']);
    }
    $paged = $service->listAttemptsForAdmin($tenant, ['page' => 2, 'limit' => 2]);
    refundAssertSame(2, $paged['page']);
    refundAssertSame(2, $paged['limit']);
    refundAssertSame($listed['total'], $paged['total']);
    refundAssertTrue(count($paged['items']) <= 2, 'page size respected');
    $foreign = $service->listAttemptsForAdmin($otherTenantContext, []);
    refundAssertSame(0, $foreign['total']);
    refundExpectReason('request_validation_failed', 422, function () use ($service, $tenant): void {
        $service->listAttemptsForAdmin($tenant, ['status' => 'nope']);
    });
    refundExpectReason('request_validation_failed', 422, function () use ($service, $tenant): void {
        $service->listAttemptsForAdmin($tenant, ['limit' => 101]);
    });
    $detail = $service->attemptDetailForAdmin($tenant, $attemptId);
    refundAssertSame($attemptId, $detail['id']);
    refundAssertSame('manual', $detail['status']);
    refundExpectReason('refund_attempt_not_found', 404, function () use ($service, $otherTenantContext, $attemptId): void {
        $service->attemptDetailForAdmin($otherTenantContext, $attemptId);
    });
    refundExpectReason('request_validation_failed', 422, function () use ($service, $tenant): void {
        $service->attemptDetailForAdmin($tenant, 0);
    });

    // ---- 7. 终态保护：渠道查询与人工确认竞态不覆盖 MANUAL ----
    $racePaid = refundCreatePaidRegistration(
        $tenantRow, $channel, $memberId, $uid, $runId, $now, OrderContextState::REFUND_PROCESSING, 'W'
    );
    $raceAttempt = refundCreateAttempt(
        $tenantRow, $channel, $racePaid, $uid, $runId, $now, RefundAttemptState::PROCESSING, 'application_pending', 'H'
    );
    Db::table('ch_refund_attempt')->where('id', $raceAttempt)->update([
        'next_query_time' => $now - 1,
    ]);
    $raceService = new EventRegistrationRefundService(
        new EventService($clock),
        new EventIdempotency($clock),
        $store,
        $projection,
        new RacyRefundGateway($raceAttempt),
        $clock
    );
    $raceService->queryPending(10);
    refundAssertSame('manual', (string) refundAttemptRow($raceAttempt)['status']);
    refundAssertSame(0, (int) Db::table('ch_event_registration_effect')
        ->where('registration_id', $racePaid['registration_id'])->count());

    fwrite(STDOUT, sprintf(
        "Event refund admin database integration passed (%d assertions).\n",
        $assertions
    ));
} finally {
    Db::rollback();
}

function refundFixtureTenant(string $slug): array
{
    $row = Db::table('ch_tenant')->where('slug', $slug)->where('status', 1)->where('is_del', 0)->find();
    if (!is_array($row)) {
        throw new RuntimeException('Tenant fixture was not found: ' . $slug);
    }

    return $row;
}

function refundFixtureChannel(int $tenantId, string $code): array
{
    $row = Db::table('ch_channel')
        ->where('tenant_id', $tenantId)->where('code', $code)
        ->where('status', 1)->where('is_del', 0)->find();
    if (!is_array($row)) {
        throw new RuntimeException('Channel fixture was not found');
    }

    return $row;
}

function refundTenantContext(array $tenant, array $channel, string $source): TenantContext
{
    return new TenantContext(new TenantRecord(
        (int) $tenant['id'],
        (string) $tenant['slug'],
        (int) $channel['id'],
        (string) $channel['code'],
        true
    ), $source);
}

function refundCreateMember(int $tenantId, int $channelId, int $uid, int $now): int
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

/** @return array{registration_id:int,context_id:int,ticket_id:int} */
function refundCreatePaidRegistration(
    array $tenantRow,
    array $channel,
    int $memberId,
    int $uid,
    string $runId,
    int $now,
    int $contextRefundStatus,
    string $tag = 'A'
): array {
    global $sequence;
    $sequence++;
    $tenantId = (int) $tenantRow['id'];
    $channelId = (int) $channel['id'];
    $eventNo = substr('RFT' . strtoupper($runId) . $tag . $sequence, 0, 32);
    $eventId = (int) Db::table('ch_event')->insertGetId([
        'tenant_id' => $tenantId, 'channel_id' => $channelId,
        'event_no' => $eventNo,
        'event_type' => 'growth', 'title' => 'Refund admin fixture',
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
    $ticketId = (int) Db::table('ch_event_ticket')->insertGetId([
        'tenant_id' => $tenantId, 'event_id' => $eventId, 'name' => 'Refund fixture ticket',
        'price' => '10.00', 'integral_price' => 0,
        'product_id' => 0, 'product_attr_unique' => '',
        'capacity' => 10, 'reserved_count' => 0, 'paid_count' => 1,
        'min_tier' => 2, 'eligibility_json' => '{}', 'refund_policy_json' => '{}',
        'sale_start_time' => $now - 3600, 'sale_end_time' => $now + 3600,
        'status' => 1, 'sort' => 1, 'add_time' => $now, 'update_time' => $now, 'is_del' => 0,
    ]);
    $orderPk = 900000 + $sequence;
    $registrationId = (int) Db::table('ch_event_registration')->insertGetId([
        'tenant_id' => $tenantId, 'event_id' => $eventId, 'ticket_id' => $ticketId,
        'member_id' => $memberId, 'uid' => $uid,
        'registration_no' => 'RFR' . strtoupper($runId) . $tag . str_pad((string) $sequence, 4, '0', STR_PAD_LEFT),
        'order_pk' => $orderPk, 'order_no' => 'CRMEB-TEST-' . strtoupper($runId) . $tag . $sequence,
        'order_context_id' => 0, 'amount' => '10.00', 'integral_amount' => 0,
        'status' => 1, 'reserve_expire_time' => 0, 'paid_time' => $now,
        'cancel_time' => 0, 'refund_time' => 0,
        'add_time' => $now, 'update_time' => $now,
    ]);
    $contextId = (int) Db::table('ch_order_context')->insertGetId([
        'tenant_id' => $tenantId, 'channel_id' => $channelId,
        'member_id' => $memberId, 'uid' => $uid,
        'context_no' => 'RFCTX' . strtoupper($runId) . $tag . $sequence,
        'order_pk' => $orderPk, 'order_no' => 'CRMEB-TEST-' . strtoupper($runId) . $tag . $sequence,
        'business_type' => 'event_registration', 'business_id' => $registrationId,
        'currency' => 'CNY', 'list_amount' => '10.00', 'payable_amount' => '10.00',
        'paid_amount' => '10.00', 'refunded_amount' => '0.00', 'integral_amount' => '0.00',
        'price_snapshot_json' => json_encode([
            'event_id' => $eventId,
            'event_no' => $eventNo,
            'ticket_id' => $ticketId,
            'ticket_name' => 'Refund fixture ticket',
            'cash_amount' => '10.00',
            'integral_amount' => 0,
            'currency' => 'CNY',
            'quantity' => 1,
        ]),
        'entitlement_snapshot_json' => '{}',
        'refund_policy_snapshot_json' => json_encode([
            'mode' => 'full_before_deadline',
            'deadline_time' => $now + 3600,
            'percent' => 100,
            'description' => 'fixture',
        ]),
        'settlement_snapshot_json' => json_encode([
            'product_id' => 1,
            'product_attr_unique' => 'rftsku0001',
        ]),
        'pay_status' => OrderContextState::PAY_COMPLETED,
        'completion_kind' => OrderContextState::COMPLETION_PAID,
        'refund_status' => $contextRefundStatus,
        'paid_time' => $now, 'version' => 1,
        'add_time' => $now, 'update_time' => $now,
    ]);
    Db::table('ch_event_registration')->where('id', $registrationId)->update([
        'order_context_id' => $contextId,
    ]);

    return ['registration_id' => $registrationId, 'context_id' => $contextId, 'ticket_id' => $ticketId];
}

function refundCreateAttempt(
    array $tenantRow,
    array $channel,
    array $paid,
    int $uid,
    string $runId,
    int $now,
    string $status,
    string $providerStatus,
    string $tag = 'A'
): int {
    global $sequence;
    $sequence++;
    $tenantId = (int) $tenantRow['id'];

    return (int) Db::table('ch_refund_attempt')->insertGetId([
        'tenant_id' => $tenantId, 'channel_id' => (int) $channel['id'],
        'refund_no' => 'RFA' . strtoupper($runId) . $tag . str_pad((string) $sequence, 4, '0', STR_PAD_LEFT),
        'idempotency_record_id' => 0, 'commerce_event_id' => 0,
        'source_type' => 'event_registration', 'source_id' => (string) $paid['registration_id'],
        'order_context_id' => $paid['context_id'], 'requester_uid' => $uid,
        'crmeb_order_id' => 0, 'crmeb_order_no' => 'CRMEB-TEST-' . strtoupper($runId) . $tag . $sequence,
        'crmeb_refund_id' => 0, 'provider' => 'balance',
        'provider_trade_no' => 'STUB-TRADE-' . $sequence,
        'provider_refund_no' => 'RFP' . strtoupper($runId) . $tag . str_pad((string) $sequence, 4, '0', STR_PAD_LEFT),
        'provider_refund_id' => '', 'provider_status' => $providerStatus,
        'currency' => 'CNY', 'amount' => '10.00', 'paid_amount' => '10.00',
        'cumulative_before' => '0.00', 'cumulative_after' => '10.00',
        'status' => $status,
        'request_hash' => hash('sha256', 'refund-admin-fixture:' . $runId . $tag . $sequence),
        'reason_hash' => hash('sha256', 'fixture-reason'),
        'last_response_hash' => '', 'query_retry_count' => 0,
        'next_query_time' => 0, 'last_query_time' => 0,
        'lease_token' => '', 'lease_expire_time' => 0, 'version' => 1,
        'final_confirmed' => 0, 'final_confirm_source' => '', 'final_confirm_time' => 0,
        'failure_code' => '', 'manual_operator_id' => 0, 'manual_reference' => '',
        'request_time' => $now, 'processing_time' => 0,
        'add_time' => $now, 'update_time' => $now,
    ]);
}

function refundAttemptRow(int $attemptId): array
{
    $row = Db::table('ch_refund_attempt')->where('id', $attemptId)->find();
    if (!is_array($row)) {
        throw new RuntimeException('Refund attempt fixture was not found');
    }

    return $row;
}

function refundExpectReason(string $reason, int $status, callable $callback): void
{
    try {
        $callback();
    } catch (MemberTransactionException $exception) {
        refundAssertSame($reason, $exception->reason());
        refundAssertSame($status, $exception->httpStatus());
        return;
    }

    throw new RuntimeException('Expected member transaction exception: ' . $reason);
}

function refundAssertTrue(bool $actual, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$actual) {
        throw new RuntimeException($message);
    }
}

function refundAssertSame($expected, $actual): void
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
