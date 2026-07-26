<?php

declare(strict_types=1);

use app\chamber\contracts\MembershipOrderGatewayInterface;
use app\chamber\exceptions\MemberTransactionException;
use app\chamber\identity\AuthenticatedUserContext;
use app\chamber\membership\GraduateVerificationState;
use app\chamber\membership\MembershipCheckoutIdempotency;
use app\chamber\membership\MembershipCheckoutRequest;
use app\chamber\membership\MembershipPlanSnapshot;
use app\chamber\services\MembershipCheckoutService;
use app\chamber\tenancy\TenantContext;
use app\chamber\tenancy\TenantRecord;
use think\App;
use think\facade\Db;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$run = function (): void {
global $assertions;

(new App())->initialize();

$runId = strtolower(bin2hex(random_bytes(6)));
$now = time();
$assertions = 0;
$fixture = [
    'channel_ids' => [],
    'member_ids' => [],
    'plan_ids' => [],
    'idempotency_keys' => [],
];
$failure = null;

try {
    assertDatabasePrerequisites($now);
    $primaryTenant = tenantRow('local-primary');
    $secondaryTenant = tenantRow('local-secondary');

    $primaryChannelId = createChannelFixture((int) $primaryTenant['id'], $runId . '-primary', $fixture);
    $primaryOtherChannelId = createChannelFixture(
        (int) $primaryTenant['id'],
        $runId . '-primary-other',
        $fixture
    );
    $secondaryChannelId = createChannelFixture((int) $secondaryTenant['id'], $runId . '-secondary', $fixture);
    $primary = tenantContext($primaryTenant, $primaryChannelId, 'db-' . $runId . '-primary');
    $primaryOther = tenantContext(
        $primaryTenant,
        $primaryOtherChannelId,
        'db-' . $runId . '-primary-other'
    );
    $secondary = tenantContext($secondaryTenant, $secondaryChannelId, 'db-' . $runId . '-secondary');

    $uid = unusedUid($runId);
    $unverifiedUid = $uid + 1;
    $l4Uid = $uid + 2;
    $primaryMemberId = createMemberFixture($primary, $uid, 2, true, $now, $fixture);
    createMemberFixture($secondary, $uid, 2, true, $now, $fixture);
    createMemberFixture($primary, $unverifiedUid, 1, false, $now, $fixture);
    createMemberFixture($primary, $l4Uid, 4, true, $now, $fixture);

    $planCode = 'DB3_' . strtoupper($runId);
    $l4PlanCode = 'DB4_' . strtoupper($runId);
    $primaryL3 = createPlanFixture(
        $primary,
        $planCode,
        3,
        '1000.00',
        1100000000 + random_int(1, 999999),
        'db3' . substr($runId, 0, 12),
        $now,
        $fixture
    );
    $primaryL4 = createPlanFixture(
        $primary,
        $l4PlanCode,
        4,
        '5000.00',
        1200000000 + random_int(1, 999999),
        'db4' . substr($runId, 0, 12),
        $now,
        $fixture
    );
    $secondaryL3 = createPlanFixture(
        $secondary,
        $planCode,
        3,
        '1200.00',
        1300000000 + random_int(1, 999999),
        'dbs' . substr($runId, 0, 12),
        $now,
        $fixture
    );

    $gateway = new FakeMembershipOrderGateway([
        productKey($primaryL3),
        productKey($primaryL4),
        productKey($secondaryL3),
    ], unusedOrderPk(), $runId);
    $leaseSequence = 0;
    $clock = function () use ($now): int {
        return $now;
    };
    $leaseFactory = function () use (&$leaseSequence): string {
        $leaseSequence++;

        return sprintf('00000000-0000-4000-8000-%012x', $leaseSequence);
    };
    $service = new MembershipCheckoutService($gateway, $clock, $leaseFactory);
    $auth = new AuthenticatedUserContext($uid, true, 'api');

    $primaryPlans = $service->listPlans($primary, $auth);
    assertSame(['plans'], array_keys($primaryPlans));
    assertSame(2, count($primaryPlans['plans']));
    assertSame([$planCode, $l4PlanCode], array_column($primaryPlans['plans'], 'code'));
    assertSame([true, true], array_column($primaryPlans['plans'], 'eligible'));
    assertSame(false, array_key_exists('product_id', $primaryPlans['plans'][0]));
    assertSame(false, array_key_exists('product_attr_unique', $primaryPlans['plans'][0]));

    $secondaryPlans = $service->listPlans($secondary, $auth);
    assertSame(1, count($secondaryPlans['plans']));
    assertSame($planCode, $secondaryPlans['plans'][0]['code']);
    assertSame('1200.00', $secondaryPlans['plans'][0]['price']);
    expectReason('tenant_scope_denied', 403, function () use ($service, $primaryOther, $auth): void {
        $service->listPlans($primaryOther, $auth);
    });

    $primaryL3Request = checkoutRequest($planCode, '1000.00');
    $primaryL4Request = checkoutRequest($l4PlanCode, '5000.00');
    $secondaryL3Request = checkoutRequest($planCode, '1200.00');
    $successCallerKey = 'checkout-db-' . $runId . '-success';
    registerKey($fixture, $primary, $uid, $successCallerKey);
    $created = $service->checkout(
        $primary,
        $auth,
        $primaryL3Request,
        $successCallerKey,
        ['uid' => $uid, 'nickname' => 'DB fixture']
    );
    assertSame(false, $created['replayed']);
    assertSame('1000.00', $created['payable_amount']);
    assertSame('CNY', $created['currency']);
    assertSame(true, $created['payment_required']);
    assertSame(1, $gateway->createCount());

    $successRecord = recordForKey($primary, $uid, $successCallerKey);
    $successContext = contextForRecord((int) $successRecord['id']);
    assertSame('succeeded', (string) $successRecord['status']);
    assertSame(201, (int) $successRecord['result_http_status']);
    assertSame($primary->tenantId(), (int) $successContext['tenant_id']);
    assertSame($primary->channelId(), (int) $successContext['channel_id']);
    assertSame($primaryMemberId, (int) $successContext['member_id']);
    assertSame($uid, (int) $successContext['uid']);
    assertSame($created['context_no'], (string) $successContext['context_no']);
    assertSame($created['order_no'], (string) $successContext['order_no']);
    assertSame('1000.00', databaseAmount($successContext['payable_amount']));

    $replay = $service->checkout(
        $primary,
        $auth,
        $primaryL3Request,
        $successCallerKey,
        ['uid' => $uid]
    );
    assertSame(true, $replay['replayed']);
    foreach (['context_no', 'order_no', 'order_status', 'payable_amount', 'currency', 'payment_required'] as $field) {
        assertSame($created[$field], $replay[$field]);
    }
    assertSame(1, $gateway->createCount());

    expectReason('idempotency_conflict', 409, function () use (
        $service,
        $primary,
        $auth,
        $primaryL4Request,
        $successCallerKey,
        $uid
    ): void {
        $service->checkout($primary, $auth, $primaryL4Request, $successCallerKey, ['uid' => $uid]);
    });
    assertSame(1, $gateway->createCount());

    registerKey($fixture, $secondary, $uid, $successCallerKey);
    $secondaryCreated = $service->checkout(
        $secondary,
        $auth,
        $secondaryL3Request,
        $successCallerKey,
        ['uid' => $uid]
    );
    assertSame('1200.00', $secondaryCreated['payable_amount']);
    assertSame(2, $gateway->createCount());
    $secondaryRecord = recordForKey($secondary, $uid, $successCallerKey);
    $secondaryContext = contextForRecord((int) $secondaryRecord['id']);
    assertSame($secondary->tenantId(), (int) $secondaryContext['tenant_id']);
    assertSame($secondary->channelId(), (int) $secondaryContext['channel_id']);
    assertNotSame((int) $successRecord['id'], (int) $secondaryRecord['id']);
    assertNotSame((string) $successContext['context_no'], (string) $secondaryContext['context_no']);

    $unverifiedAuth = new AuthenticatedUserContext($unverifiedUid, true, 'api');
    $unverifiedKey = 'checkout-db-' . $runId . '-unverified';
    registerKey($fixture, $primary, $unverifiedUid, $unverifiedKey);
    expectReason('membership_verification_required', 403, function () use (
        $service,
        $primary,
        $unverifiedAuth,
        $primaryL3Request,
        $unverifiedKey,
        $unverifiedUid
    ): void {
        $service->checkout(
            $primary,
            $unverifiedAuth,
            $primaryL3Request,
            $unverifiedKey,
            ['uid' => $unverifiedUid]
        );
    });
    assertSame(null, optionalRecordForKey($primary, $unverifiedUid, $unverifiedKey));
    assertSame(2, $gateway->createCount());

    $l4Auth = new AuthenticatedUserContext($l4Uid, true, 'api');
    $downgradeKey = 'checkout-db-' . $runId . '-downgrade';
    registerKey($fixture, $primary, $l4Uid, $downgradeKey);
    expectReason('membership_downgrade_not_allowed', 409, function () use (
        $service,
        $primary,
        $l4Auth,
        $primaryL3Request,
        $downgradeKey,
        $l4Uid
    ): void {
        $service->checkout($primary, $l4Auth, $primaryL3Request, $downgradeKey, ['uid' => $l4Uid]);
    });
    assertSame(null, optionalRecordForKey($primary, $l4Uid, $downgradeKey));
    assertSame(2, $gateway->createCount());

    $afterOrderCommitted = function (): void {
        throw new RuntimeException('simulated post-order commit failure');
    };
    $failingService = new MembershipCheckoutService(
        $gateway,
        $clock,
        $leaseFactory,
        $afterOrderCommitted
    );
    $repairableKey = 'checkout-db-' . $runId . '-repairable';
    registerKey($fixture, $primary, $uid, $repairableKey);
    expectMessage('simulated post-order commit failure', function () use (
        $failingService,
        $primary,
        $auth,
        $primaryL3Request,
        $repairableKey,
        $uid
    ): void {
        $failingService->checkout($primary, $auth, $primaryL3Request, $repairableKey, ['uid' => $uid]);
    });
    $repairableRecord = recordForKey($primary, $uid, $repairableKey);
    $repairableContext = contextForRecord((int) $repairableRecord['id']);
    assertSame('unknown', (string) $repairableRecord['status']);
    assertSame(null, $repairableContext['order_pk']);
    assertSame(null, $repairableContext['order_no']);
    assertSame(true, $gateway->hasOrder($uid, (string) $repairableContext['context_no']));

    $missingKey = 'checkout-db-' . $runId . '-missing';
    registerKey($fixture, $primary, $uid, $missingKey);
    expectMessage('simulated post-order commit failure', function () use (
        $failingService,
        $primary,
        $auth,
        $primaryL3Request,
        $missingKey,
        $uid
    ): void {
        $failingService->checkout($primary, $auth, $primaryL3Request, $missingKey, ['uid' => $uid]);
    });
    $missingRecord = recordForKey($primary, $uid, $missingKey);
    $missingContext = contextForRecord((int) $missingRecord['id']);
    $gateway->forgetOrder($uid, (string) $missingContext['context_no']);
    assertSame('unknown', (string) $missingRecord['status']);
    assertSame(false, $gateway->hasOrder($uid, (string) $missingContext['context_no']));
    assertSame(4, $gateway->createCount());
    assertSame(2, reconcilableCount($now));

    $lateRepairableKey = 'checkout-db-' . $runId . '-late-repairable';
    registerKey($fixture, $primary, $uid, $lateRepairableKey);
    expectMessage('simulated post-order commit failure', function () use (
        $failingService,
        $primary,
        $auth,
        $primaryL3Request,
        $lateRepairableKey,
        $uid
    ): void {
        $failingService->checkout($primary, $auth, $primaryL3Request, $lateRepairableKey, ['uid' => $uid]);
    });
    $lateRecord = recordForKey($primary, $uid, $lateRepairableKey);
    $lateContext = contextForRecord((int) $lateRecord['id']);
    assertSame(true, $gateway->hasOrder($uid, (string) $lateContext['context_no']));
    Db::table('ch_idempotency_record')->where('id', (int) $missingRecord['id'])->update([
        'update_time' => $now - 200,
        'attempt_count' => 10,
    ]);
    Db::table('ch_idempotency_record')->where('id', (int) $lateRecord['id'])->update([
        'update_time' => $now - 100,
    ]);
    assertSame(5, $gateway->createCount());
    assertSame(3, reconcilableCount($now));

    $createCountBeforeReconcile = $gateway->createCount();
    $reconciler = new MembershipCheckoutService($gateway, $clock, $leaseFactory);
    $reconcile = $reconciler->reconcilePending(1);
    assertSame([
        'scanned' => 1,
        'repaired' => 0,
        'order_missing' => 1,
        'failed' => 0,
    ], $reconcile);
    assertSame($createCountBeforeReconcile, $gateway->createCount());

    $reconcile = $reconciler->reconcilePending(1);
    assertSame([
        'scanned' => 1,
        'repaired' => 1,
        'order_missing' => 0,
        'failed' => 0,
    ], $reconcile);

    $reconcile = $reconciler->reconcilePending(50);
    assertSame([
        'scanned' => 2,
        'repaired' => 1,
        'order_missing' => 1,
        'failed' => 0,
    ], $reconcile);

    $repairableRecord = recordForKey($primary, $uid, $repairableKey);
    $repairableContext = contextForRecord((int) $repairableRecord['id']);
    assertSame('succeeded', (string) $repairableRecord['status']);
    assertSame(false, $repairableContext['order_pk'] === null);
    assertSame(false, $repairableContext['order_no'] === null);
    $missingRecord = recordForKey($primary, $uid, $missingKey);
    $missingContext = contextForRecord((int) $missingRecord['id']);
    assertSame('unknown', (string) $missingRecord['status']);
    assertSame(null, $missingContext['order_pk']);
    assertSame(null, $missingContext['order_no']);

    $lateRecord = recordForKey($primary, $uid, $lateRepairableKey);
    $lateContext = contextForRecord((int) $lateRecord['id']);
    assertSame('succeeded', (string) $lateRecord['status']);
    assertSame(false, $lateContext['order_pk'] === null);

    $staleKey = 'checkout-db-' . $runId . '-stale-plan';
    registerKey($fixture, $primary, $uid, $staleKey);
    $staleFailure = function ($order, array $context) use ($gateway, $uid): void {
        $gateway->forgetOrder($uid, (string) $context['context_no']);
        throw new RuntimeException('simulated stale-plan gap');
    };
    $staleService = new MembershipCheckoutService($gateway, $clock, $leaseFactory, $staleFailure);
    expectMessage('simulated stale-plan gap', function () use (
        $staleService,
        $primary,
        $auth,
        $primaryL3Request,
        $staleKey,
        $uid
    ): void {
        $staleService->checkout($primary, $auth, $primaryL3Request, $staleKey, ['uid' => $uid]);
    });
    $staleRecord = recordForKey($primary, $uid, $staleKey);
    assertSame('unknown', (string) $staleRecord['status']);
    Db::table('ch_membership_plan')->where('id', (int) $primaryL3['id'])->update([
        'purchase_enabled' => 0,
        'update_time' => $now,
    ]);
    $createCountBeforeStaleRetry = $gateway->createCount();
    expectReason('membership_plan_unavailable', 409, function () use (
        $service,
        $primary,
        $auth,
        $primaryL3Request,
        $staleKey,
        $uid
    ): void {
        $service->checkout($primary, $auth, $primaryL3Request, $staleKey, ['uid' => $uid]);
    });
    assertSame($createCountBeforeStaleRetry, $gateway->createCount());
    Db::table('ch_membership_plan')->where('id', (int) $primaryL3['id'])->update([
        'purchase_enabled' => 1,
        'update_time' => $now,
    ]);

    $amountMismatchKey = 'checkout-db-' . $runId . '-amount-mismatch';
    registerKey($fixture, $primary, $uid, $amountMismatchKey);
    $gateway->mutateNextCreatedOrder('amount');
    expectReason('membership_order_inconsistent', 503, function () use (
        $service,
        $primary,
        $auth,
        $primaryL3Request,
        $amountMismatchKey,
        $uid
    ): void {
        $service->checkout($primary, $auth, $primaryL3Request, $amountMismatchKey, ['uid' => $uid]);
    });
    assertUnknownUnbound($primary, $uid, $amountMismatchKey);

    $productMismatchKey = 'checkout-db-' . $runId . '-product-mismatch';
    registerKey($fixture, $primary, $uid, $productMismatchKey);
    $gateway->mutateNextCreatedOrder('product');
    expectReason('membership_order_inconsistent', 503, function () use (
        $service,
        $primary,
        $auth,
        $primaryL3Request,
        $productMismatchKey,
        $uid
    ): void {
        $service->checkout($primary, $auth, $primaryL3Request, $productMismatchKey, ['uid' => $uid]);
    });
    assertUnknownUnbound($primary, $uid, $productMismatchKey);
    assertSame(8, $gateway->createCount());
} catch (Throwable $exception) {
    $failure = $exception;
}

try {
    cleanupFixtures($fixture);
    assertFixturesRemoved($fixture);
} catch (Throwable $cleanupException) {
    if ($failure === null) {
        $failure = $cleanupException;
    } else {
        $failure = new RuntimeException(
            $failure->getMessage() . '; cleanup failed: ' . $cleanupException->getMessage(),
            0,
            $failure
        );
    }
}

if ($failure !== null) {
    fwrite(STDERR, sprintf(
        "FAIL membership checkout database service after %d assertions: %s\n",
        $assertions,
        $failure->getMessage()
    ));
    exit(1);
}

fwrite(STDOUT, sprintf(
    "PASS membership checkout database service (%d assertions; fixtures removed)\n",
    $assertions
));
};

function assertDatabasePrerequisites(int $now): void
{
    if (!interface_exists(MembershipOrderGatewayInterface::class)) {
        throw new RuntimeException('Membership order gateway interface is unavailable');
    }
    foreach (['ch_tenant', 'ch_channel', 'ch_tenant_member', 'ch_membership_plan', 'ch_order_context', 'ch_idempotency_record'] as $table) {
        $rows = Db::query(
            'SELECT COUNT(*) AS aggregate FROM information_schema.`TABLES` '
            . 'WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = ?',
            [$table]
        );
        if ((int) ($rows[0]['aggregate'] ?? 0) !== 1) {
            throw new RuntimeException(sprintf('Required database table %s is unavailable', $table));
        }
    }
    $column = Db::query(
        'SELECT COUNT(*) AS aggregate FROM information_schema.`COLUMNS` '
        . 'WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = ? AND `COLUMN_NAME` = ?',
        ['ch_order_context', 'idempotency_record_id']
    );
    if ((int) ($column[0]['aggregate'] ?? 0) !== 1) {
        throw new RuntimeException('G1-01D idempotency link migration is unavailable');
    }
    $pending = reconcilableCount($now);
    if ($pending !== 0) {
        throw new RuntimeException(sprintf(
            'Database contains %d pre-existing membership checkout records eligible for reconciliation',
            $pending
        ));
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

function createChannelFixture(int $tenantId, string $suffix, array &$fixture): int
{
    $now = time();
    $id = (int) Db::table('ch_channel')->insertGetId([
        'tenant_id' => $tenantId,
        'name' => 'Membership checkout DB fixture',
        'code' => 'checkout-' . $suffix,
        'entry_key' => substr(hash('sha256', 'checkout-channel:' . $suffix), 0, 32),
        'status' => 1,
        'sort' => 0,
        'add_time' => $now,
        'update_time' => $now,
        'is_del' => 0,
    ]);
    if ($id <= 0) {
        throw new RuntimeException('Membership checkout channel fixture was not created');
    }
    $fixture['channel_ids'][] = $id;

    return $id;
}

function tenantContext(array $tenant, int $channelId, string $channelCode): TenantContext
{
    return new TenantContext(new TenantRecord(
        (int) $tenant['id'],
        (string) $tenant['slug'],
        $channelId,
        $channelCode,
        true
    ), 'database-test');
}

function unusedUid(string $runId): int
{
    $candidate = 1500000000 + (hexdec(substr($runId, 0, 6)) % 100000000);
    while (Db::table('ch_tenant_member')->whereIn('uid', [$candidate, $candidate + 1, $candidate + 2])->count() > 0) {
        $candidate += 10;
    }

    return $candidate;
}

function createMemberFixture(
    TenantContext $tenant,
    int $uid,
    int $tier,
    bool $approved,
    int $now,
    array &$fixture
): int {
    $id = (int) Db::table('ch_tenant_member')->insertGetId([
        'tenant_id' => $tenant->tenantId(),
        'uid' => $uid,
        'first_channel_id' => $tenant->channelId(),
        'current_channel_id' => $tenant->channelId(),
        'referrer_uid' => 0,
        'invite_code' => null,
        'attribution_locked_time' => $now,
        'tier' => $tier,
        'verification_status' => $approved
            ? GraduateVerificationState::toDatabase(GraduateVerificationState::APPROVED)
            : GraduateVerificationState::toDatabase(GraduateVerificationState::DRAFT),
        'current_verification_id' => 0,
        'primary_role_id' => 0,
        'status' => 1,
        'join_time' => $now,
        'certified_time' => $approved ? $now : 0,
        'tier_expire_time' => 0,
        'current_membership_term_id' => 0,
        'membership_version' => 0,
        'add_time' => $now,
        'update_time' => $now,
        'is_del' => 0,
    ]);
    if ($id <= 0) {
        throw new RuntimeException('Membership checkout member fixture was not created');
    }
    $fixture['member_ids'][] = $id;

    return $id;
}

function createPlanFixture(
    TenantContext $tenant,
    string $code,
    int $tier,
    string $price,
    int $productId,
    string $attrUnique,
    int $now,
    array &$fixture
): array {
    $row = [
        'tenant_id' => $tenant->tenantId(),
        'channel_id' => $tenant->channelId(),
        'plan_code' => $code,
        'name' => sprintf('Fixture %s', $code),
        'tier' => $tier,
        'purchase_enabled' => 1,
        'price' => $price,
        'currency' => 'CNY',
        'term_months' => 12,
        'product_id' => $productId,
        'product_attr_unique' => $attrUnique,
        'benefits_json' => '["Database fixture benefit"]',
        'renewal_policy_json' => '{"mode":"fixture"}',
        'upgrade_policy_json' => '{"mode":"fixture"}',
        'refund_policy_json' => '{"mode":"fixture"}',
        'config_version' => 1,
        'status' => 1,
        'effective_time' => $now - 60,
        'end_time' => 0,
        'add_time' => $now,
        'update_time' => $now,
    ];
    $id = (int) Db::table('ch_membership_plan')->insertGetId($row);
    if ($id <= 0) {
        throw new RuntimeException('Membership checkout plan fixture was not created');
    }
    $fixture['plan_ids'][] = $id;
    $row['id'] = $id;

    return $row;
}

function productKey(array $plan): string
{
    return (int) $plan['product_id'] . ':' . (string) $plan['product_attr_unique'];
}

function checkoutRequest(string $planCode, string $amount): MembershipCheckoutRequest
{
    return MembershipCheckoutRequest::fromArray([
        'plan_code' => $planCode,
        'plan_version' => 1,
        'expected_amount' => $amount,
        'currency' => 'CNY',
    ]);
}

function registerKey(array &$fixture, TenantContext $tenant, int $uid, string $callerKey): void
{
    $fixture['idempotency_keys'][] = MembershipCheckoutIdempotency::deriveInternalKey(
        $tenant->tenantId(),
        $uid,
        $callerKey
    );
}

function optionalRecordForKey(TenantContext $tenant, int $uid, string $callerKey): ?array
{
    $key = MembershipCheckoutIdempotency::deriveInternalKey($tenant->tenantId(), $uid, $callerKey);
    $row = Db::table('ch_idempotency_record')
        ->where('tenant_id', $tenant->tenantId())
        ->where('idempotency_key', $key)
        ->find();

    return is_array($row) ? $row : null;
}

function recordForKey(TenantContext $tenant, int $uid, string $callerKey): array
{
    $row = optionalRecordForKey($tenant, $uid, $callerKey);
    if ($row === null) {
        throw new RuntimeException('Membership checkout idempotency fixture is unavailable');
    }

    return $row;
}

function contextForRecord(int $recordId): array
{
    $row = Db::table('ch_order_context')->where('idempotency_record_id', $recordId)->find();
    if (!is_array($row)) {
        throw new RuntimeException('Membership checkout order context fixture is unavailable');
    }

    return $row;
}

function assertUnknownUnbound(
    TenantContext $tenant,
    int $uid,
    string $callerKey
): void {
    $record = recordForKey($tenant, $uid, $callerKey);
    $context = contextForRecord((int) $record['id']);
    assertSame('unknown', (string) $record['status']);
    assertSame(null, $context['order_pk']);
    assertSame(null, $context['order_no']);
}

function reconcilableCount(int $now): int
{
    $rows = Db::query(
        'SELECT COUNT(*) AS aggregate FROM `ch_order_context` AS context '
        . 'INNER JOIN `ch_idempotency_record` AS record '
        . 'ON record.`id` = context.`idempotency_record_id` '
        . 'WHERE context.`business_type` = ? AND context.`order_pk` IS NULL '
        . 'AND context.`order_no` IS NULL AND record.`operation` = ? '
        . 'AND (record.`status` IN (?, ?) '
        . 'OR (record.`status` = ? AND record.`lease_expire_time` < ?))',
        ['membership', MembershipCheckoutIdempotency::OPERATION, 'failed', 'unknown', 'processing', $now]
    );

    return (int) ($rows[0]['aggregate'] ?? 0);
}

function unusedOrderPk(): int
{
    $candidate = 1700000000 + random_int(1, 999999);
    while (Db::table('ch_order_context')->whereBetween('order_pk', [$candidate, $candidate + 20])->count() > 0) {
        $candidate += 100;
    }

    return $candidate;
}

function databaseAmount($amount): string
{
    return number_format((float) $amount, 2, '.', '');
}

function cleanupFixtures(array $fixture): void
{
    $keys = array_values(array_unique($fixture['idempotency_keys']));
    $memberIds = array_values(array_unique($fixture['member_ids']));
    $recordIds = [];
    if ($keys !== []) {
        $recordIds = array_map('intval', Db::table('ch_idempotency_record')
            ->whereIn('idempotency_key', $keys)
            ->column('id'));
    }
    if ($recordIds !== []) {
        Db::table('ch_order_context')->whereIn('idempotency_record_id', $recordIds)->delete();
    }
    if ($memberIds !== []) {
        Db::table('ch_order_context')->whereIn('member_id', $memberIds)->delete();
    }
    if ($keys !== []) {
        Db::table('ch_idempotency_record')->whereIn('idempotency_key', $keys)->delete();
    }
    if ($fixture['plan_ids'] !== []) {
        Db::table('ch_membership_plan')->whereIn('id', $fixture['plan_ids'])->delete();
    }
    if ($memberIds !== []) {
        Db::table('ch_tenant_member')->whereIn('id', $memberIds)->delete();
    }
    if ($fixture['channel_ids'] !== []) {
        Db::table('ch_channel')->whereIn('id', $fixture['channel_ids'])->delete();
    }
}

function assertFixturesRemoved(array $fixture): void
{
    foreach ([
        ['ch_channel', 'id', $fixture['channel_ids']],
        ['ch_tenant_member', 'id', $fixture['member_ids']],
        ['ch_membership_plan', 'id', $fixture['plan_ids']],
        ['ch_idempotency_record', 'idempotency_key', array_values(array_unique($fixture['idempotency_keys']))],
    ] as $check) {
        if ($check[2] !== [] && Db::table($check[0])->whereIn($check[1], $check[2])->count() !== 0) {
            throw new RuntimeException(sprintf('Fixture cleanup left rows in %s', $check[0]));
        }
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

function assertNotSame($unexpected, $actual): void
{
    global $assertions;
    $assertions++;
    if ($unexpected === $actual) {
        throw new RuntimeException(sprintf('Did not expect %s', var_export($actual, true)));
    }
}

function expectReason(string $reason, int $status, callable $callback): MemberTransactionException
{
    global $assertions;
    try {
        $callback();
    } catch (Throwable $exception) {
        if (!$exception instanceof MemberTransactionException) {
            throw $exception;
        }
        if ($exception->reason() !== $reason || $exception->httpStatus() !== $status) {
            throw new RuntimeException(sprintf(
                'Expected %d/%s, got %d/%s',
                $status,
                $reason,
                $exception->httpStatus(),
                $exception->reason()
            ));
        }
        $assertions++;

        return $exception;
    }

    throw new RuntimeException(sprintf('Expected member transaction error %s', $reason));
}

function expectMessage(string $message, callable $callback): Throwable
{
    global $assertions;
    try {
        $callback();
    } catch (Throwable $exception) {
        if ($exception->getMessage() !== $message) {
            throw new RuntimeException(sprintf(
                'Expected exception message %s, got %s',
                $message,
                $exception->getMessage()
            ));
        }
        $assertions++;

        return $exception;
    }

    throw new RuntimeException(sprintf('Expected exception message %s', $message));
}

final class FakeMembershipOrderGateway implements MembershipOrderGatewayInterface
{
    /** @var array<string,bool> */
    private $availableProducts = [];

    /** @var array<string,array> */
    private $orders = [];

    /** @var int */
    private $nextOrderPk;

    /** @var string */
    private $runId;

    /** @var int */
    private $createCount = 0;

    /** @var string|null */
    private $nextMutation;

    public function __construct(array $availableProducts, int $nextOrderPk, string $runId)
    {
        foreach ($availableProducts as $key) {
            $this->availableProducts[$key] = true;
        }
        $this->nextOrderPk = $nextOrderPk;
        $this->runId = strtoupper($runId);
    }

    public function assertPlanProduct(MembershipPlanSnapshot $plan): void
    {
        if (!isset($this->availableProducts[$plan->productId() . ':' . $plan->productAttrUnique()])) {
            throw new MemberTransactionException(
                409,
                'membership_plan_unavailable',
                'Fake CRMEB product is unavailable'
            );
        }
    }

    public function findByCheckoutKey(int $uid, string $checkoutKey): ?array
    {
        return $this->orders[$this->orderKey($uid, $checkoutKey)] ?? null;
    }

    public function create(
        array $authenticatedUser,
        MembershipPlanSnapshot $plan,
        string $checkoutKey
    ): array {
        $uid = $authenticatedUser['uid'] ?? null;
        if (!is_int($uid) || $uid <= 0) {
            throw new RuntimeException('Fake gateway requires the authenticated CRMEB uid');
        }
        $this->assertPlanProduct($plan);
        $this->createCount++;
        $order = [
            'order_pk' => $this->nextOrderPk++,
            'order_no' => sprintf('FDB%s%04d', $this->runId, $this->createCount),
            'uid' => $uid,
            'checkout_key' => $checkoutKey,
            'product_id' => $plan->productId(),
            'product_attr_unique' => $plan->productAttrUnique(),
            'quantity' => 1,
            'payable_amount' => $plan->price(),
            'currency' => $plan->currency(),
            'order_status' => 'unpaid',
            'payment_required' => true,
        ];
        if ($this->nextMutation === 'amount') {
            $order['payable_amount'] = '999.99';
        } elseif ($this->nextMutation === 'product') {
            $order['product_id'] = $plan->productId() + 1;
        }
        $this->nextMutation = null;
        $this->orders[$this->orderKey($uid, $checkoutKey)] = $order;

        return $order;
    }

    public function assertOrderMatches(
        array $order,
        MembershipPlanSnapshot $plan,
        int $uid,
        string $checkoutKey
    ): array {
        $matches = ($order['uid'] ?? null) === $uid
            && ($order['checkout_key'] ?? null) === $checkoutKey
            && ($order['product_id'] ?? null) === $plan->productId()
            && ($order['product_attr_unique'] ?? null) === $plan->productAttrUnique()
            && ($order['quantity'] ?? null) === 1
            && ($order['payable_amount'] ?? null) === $plan->price()
            && ($order['currency'] ?? null) === $plan->currency();
        if (!$matches) {
            throw new MemberTransactionException(
                503,
                'membership_order_inconsistent',
                'Fake CRMEB order does not match the membership checkout'
            );
        }

        return $order;
    }

    public function mutateNextCreatedOrder(string $mutation): void
    {
        if (!in_array($mutation, ['amount', 'product'], true)) {
            throw new InvalidArgumentException('Unknown fake order mutation');
        }
        $this->nextMutation = $mutation;
    }

    public function hasOrder(int $uid, string $checkoutKey): bool
    {
        return isset($this->orders[$this->orderKey($uid, $checkoutKey)]);
    }

    public function forgetOrder(int $uid, string $checkoutKey): void
    {
        unset($this->orders[$this->orderKey($uid, $checkoutKey)]);
    }

    public function createCount(): int
    {
        return $this->createCount;
    }

    private function orderKey(int $uid, string $checkoutKey): string
    {
        return $uid . ':' . $checkoutKey;
    }
}

$run();
