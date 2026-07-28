<?php

declare(strict_types=1);

use app\chamber\activity\EventEligibility;
use app\chamber\activity\EventRegistrationRequest;
use app\chamber\exceptions\MemberTransactionException;
use app\chamber\identity\AuthenticatedUserContext;
use app\chamber\membership\BootstrapIdempotency;
use app\chamber\services\EventIdempotency;
use app\chamber\services\EventRegistrationService;
use app\chamber\services\EventService;
use app\chamber\tenancy\TenantContext;
use app\chamber\tenancy\TenantRecord;
use think\App;
use think\facade\Db;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

(new App())->initialize();

$now = time();
$runId = strtolower(bin2hex(random_bytes(6)));
$assertions = 0;
$registrationSequence = 0;

Db::startTrans();
try {
    $tenantRow = fixtureTenant('local-primary');
    $channel = fixtureChannel((int) $tenantRow['id'], 'default');
    $otherTenant = fixtureTenant('local-secondary');
    $otherChannel = fixtureChannel((int) $otherTenant['id'], 'default');
    $tenant = tenantContext($tenantRow, $channel);
    $uid = (int) Db::table('ch_tenant_member')->max('uid') + random_int(1000, 10000);
    $memberId = createEligibleMember((int) $tenantRow['id'], (int) $channel['id'], $uid, $now);
    createPointAccount((int) $tenantRow['id'], $memberId, $uid, 100, $now);
    grantMentorRole((int) $tenantRow['id'], $memberId, $uid, $now);
    $auth = new AuthenticatedUserContext($uid, true, 'api');

    $events = new EventService(function () use ($now): int {
        return $now;
    });
    $idempotency = new EventIdempotency(function () use ($now): int {
        return $now;
    });
    $service = new EventRegistrationService(
        $events,
        $idempotency,
        function () use (&$registrationSequence): string {
            $registrationSequence++;

            return 'REG' . str_pad((string) $registrationSequence, 29, '0', STR_PAD_LEFT);
        }
    );

    $freeEvent = createRegistrationEvent(
        (int) $tenantRow['id'],
        (int) $channel['id'],
        'RF' . strtoupper($runId),
        $now
    );
    $freeTicket = createRegistrationTicket(
        (int) $tenantRow['id'],
        $freeEvent,
        (int) $channel['id'],
        '0.00',
        0,
        1,
        0,
        $now
    );
    $freeRequest = EventRegistrationRequest::fromArray([
        'ticket_id' => $freeTicket,
        'expected_amount' => '0.00',
        'expected_integral' => 0,
    ]);
    $freeKey = 'event-registration-free-' . $runId;
    $free = $service->register($tenant, $auth, $freeEvent, $freeRequest, $freeKey);
    assertSame('registered', $free['status']);
    assertSame('0.00', $free['amount']);
    assertSame(0, $free['integral_amount']);
    assertSame(false, $free['payment_required']);
    assertSame(1, (int) Db::table('ch_event_ticket')->where('id', $freeTicket)->value('paid_count'));
    assertSame(1, (int) Db::table('ch_event_registration')->where('event_id', $freeEvent)->count());

    $freeReplay = $service->register($tenant, $auth, $freeEvent, $freeRequest, $freeKey);
    assertSame(
        BootstrapIdempotency::canonicalJson($free),
        BootstrapIdempotency::canonicalJson($freeReplay)
    );
    assertSame(1, (int) Db::table('ch_event_ticket')->where('id', $freeTicket)->value('paid_count'));
    expectReason('idempotency_conflict', 409, function () use (
        $service,
        $tenant,
        $auth,
        $freeEvent,
        $freeTicket,
        $freeKey
    ): void {
        $service->register(
            $tenant,
            $auth,
            $freeEvent,
            EventRegistrationRequest::fromArray([
                'ticket_id' => $freeTicket,
                'expected_amount' => '0.00',
                'expected_integral' => 1,
            ]),
            $freeKey
        );
    });
    expectReason('registration_already_exists', 409, function () use (
        $service,
        $tenant,
        $auth,
        $freeEvent,
        $freeRequest,
        $runId
    ): void {
        $service->register(
            $tenant,
            $auth,
            $freeEvent,
            $freeRequest,
            'event-registration-free-second-' . $runId
        );
    });

    $freeInternalKey = BootstrapIdempotency::deriveInternalKey(
        $tenant->tenantId(),
        'createEventRegistration',
        'crmeb_user',
        $uid,
        $freeKey
    );
    $freeRecord = Db::table('ch_idempotency_record')
        ->where('tenant_id', $tenant->tenantId())
        ->where('idempotency_key', $freeInternalKey)
        ->find();
    assertTrue(is_array($freeRecord), 'registration idempotency record must exist');
    assertSame('succeeded', (string) $freeRecord['status']);
    assertSame(false, strpos((string) $freeRecord['result_json'], (string) $free['registration_no']) !== false);

    Db::table('ch_tenant_member')->where('id', $memberId)->update(['status' => 0]);
    expectReason('member_disabled', 403, function () use (
        $service,
        $tenant,
        $auth,
        $freeEvent,
        $freeRequest,
        $freeKey
    ): void {
        $service->register($tenant, $auth, $freeEvent, $freeRequest, $freeKey);
    });
    Db::table('ch_tenant_member')->where('id', $memberId)->update(['status' => 1]);

    $pointsEvent = createRegistrationEvent(
        (int) $tenantRow['id'],
        (int) $channel['id'],
        'RP' . strtoupper($runId),
        $now
    );
    $pointsTicket = createRegistrationTicket(
        (int) $tenantRow['id'],
        $pointsEvent,
        (int) $channel['id'],
        '0.00',
        30,
        2,
        0,
        $now
    );
    $points = $service->register(
        $tenant,
        $auth,
        $pointsEvent,
        EventRegistrationRequest::fromArray([
            'ticket_id' => $pointsTicket,
            'expected_amount' => '0.00',
            'expected_integral' => 30,
        ]),
        'event-registration-points-' . $runId
    );
    assertSame('registered', $points['status']);
    assertSame(30, $points['integral_amount']);
    assertSame(70, (int) Db::table('ch_point_account')->where('member_id', $memberId)->value('balance'));
    $pointLedger = Db::table('ch_point_ledger')
        ->where('tenant_id', (int) $tenantRow['id'])
        ->where('source_type', 'event_registration')
        ->where('source_id', (string) $points['id'])
        ->find();
    assertTrue(is_array($pointLedger), 'registration point debit ledger must exist');
    assertSame(-30, (int) $pointLedger['delta']);
    assertSame(70, (int) $pointLedger['balance_after']);
    assertSame(1, (int) Db::table('ch_event_ticket')->where('id', $pointsTicket)->value('paid_count'));

    $mismatchEvent = createRegistrationEvent(
        (int) $tenantRow['id'],
        (int) $channel['id'],
        'RM' . strtoupper($runId),
        $now
    );
    $mismatchTicket = createRegistrationTicket(
        (int) $tenantRow['id'],
        $mismatchEvent,
        (int) $channel['id'],
        '0.00',
        20,
        1,
        0,
        $now
    );
    expectReason('request_validation_failed', 422, function () use (
        $service,
        $tenant,
        $auth,
        $mismatchEvent,
        $mismatchTicket,
        $runId
    ): void {
        $service->register(
            $tenant,
            $auth,
            $mismatchEvent,
            EventRegistrationRequest::fromArray([
                'ticket_id' => $mismatchTicket,
                'expected_integral' => 21,
            ]),
            'event-registration-mismatch-' . $runId
        );
    });
    assertSame(0, (int) Db::table('ch_event_ticket')->where('id', $mismatchTicket)->value('paid_count'));
    assertSame(0, (int) Db::table('ch_event_registration')->where('event_id', $mismatchEvent)->count());

    $insufficientEvent = createRegistrationEvent(
        (int) $tenantRow['id'],
        (int) $channel['id'],
        'RI' . strtoupper($runId),
        $now
    );
    $insufficientTicket = createRegistrationTicket(
        (int) $tenantRow['id'],
        $insufficientEvent,
        (int) $channel['id'],
        '0.00',
        80,
        1,
        0,
        $now
    );
    expectReason('points_required', 409, function () use (
        $service,
        $tenant,
        $auth,
        $insufficientEvent,
        $insufficientTicket,
        $runId
    ): void {
        $service->register(
            $tenant,
            $auth,
            $insufficientEvent,
            EventRegistrationRequest::fromArray(['ticket_id' => $insufficientTicket]),
            'event-registration-insufficient-' . $runId
        );
    });
    assertSame(70, (int) Db::table('ch_point_account')->where('member_id', $memberId)->value('balance'));
    assertSame(0, (int) Db::table('ch_event_registration')->where('event_id', $insufficientEvent)->count());

    $fullEvent = createRegistrationEvent(
        (int) $tenantRow['id'],
        (int) $channel['id'],
        'RX' . strtoupper($runId),
        $now
    );
    $fullTicket = createRegistrationTicket(
        (int) $tenantRow['id'],
        $fullEvent,
        (int) $channel['id'],
        '0.00',
        0,
        1,
        1,
        $now
    );
    expectReason('event_full', 409, function () use (
        $service,
        $tenant,
        $auth,
        $fullEvent,
        $fullTicket,
        $runId
    ): void {
        $service->register(
            $tenant,
            $auth,
            $fullEvent,
            EventRegistrationRequest::fromArray(['ticket_id' => $fullTicket]),
            'event-registration-full-' . $runId
        );
    });
    assertSame(0, (int) Db::table('ch_event_registration')->where('event_id', $fullEvent)->count());

    $cashEvent = createRegistrationEvent(
        (int) $tenantRow['id'],
        (int) $channel['id'],
        'RC' . strtoupper($runId),
        $now
    );
    $cashTicket = createRegistrationTicket(
        (int) $tenantRow['id'],
        $cashEvent,
        (int) $channel['id'],
        '10.00',
        0,
        1,
        0,
        $now
    );
    expectReason('event_payment_unavailable', 409, function () use (
        $service,
        $tenant,
        $auth,
        $cashEvent,
        $cashTicket,
        $runId
    ): void {
        $service->register(
            $tenant,
            $auth,
            $cashEvent,
            EventRegistrationRequest::fromArray([
                'ticket_id' => $cashTicket,
                'expected_amount' => '10.00',
            ]),
            'event-registration-cash-' . $runId
        );
    });
    assertSame(0, (int) Db::table('ch_event_ticket')->where('id', $cashTicket)->value('paid_count'));
    assertSame(0, (int) Db::table('ch_event_registration')->where('event_id', $cashEvent)->count());

    $foreignEvent = createRegistrationEvent(
        (int) $otherTenant['id'],
        (int) $otherChannel['id'],
        'RO' . strtoupper($runId),
        $now
    );
    $foreignTicket = createRegistrationTicket(
        (int) $otherTenant['id'],
        $foreignEvent,
        (int) $otherChannel['id'],
        '0.00',
        0,
        1,
        0,
        $now
    );
    expectReason('event_not_found', 404, function () use (
        $service,
        $tenant,
        $auth,
        $foreignEvent,
        $foreignTicket,
        $runId
    ): void {
        $service->register(
            $tenant,
            $auth,
            $foreignEvent,
            EventRegistrationRequest::fromArray(['ticket_id' => $foreignTicket]),
            'event-registration-foreign-' . $runId
        );
    });

    fwrite(STDOUT, sprintf(
        "Event registration database integration passed (%d assertions).\n",
        $assertions
    ));
} finally {
    Db::rollback();
}

function fixtureTenant(string $slug): array
{
    $row = Db::table('ch_tenant')->where('slug', $slug)->where('status', 1)->where('is_del', 0)->find();
    if (!is_array($row)) {
        throw new RuntimeException('Tenant fixture was not found: ' . $slug);
    }

    return $row;
}

function fixtureChannel(int $tenantId, string $code): array
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

function tenantContext(array $tenant, array $channel): TenantContext
{
    return new TenantContext(new TenantRecord(
        (int) $tenant['id'],
        (string) $tenant['slug'],
        (int) $channel['id'],
        (string) $channel['code'],
        true
    ), 'event-registration-db-test');
}

function createEligibleMember(int $tenantId, int $channelId, int $uid, int $now): int
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

function createPointAccount(int $tenantId, int $memberId, int $uid, int $balance, int $now): void
{
    Db::table('ch_point_account')->insert([
        'tenant_id' => $tenantId,
        'member_id' => $memberId,
        'uid' => $uid,
        'balance' => $balance,
        'version' => 1,
        'add_time' => $now,
        'update_time' => $now,
    ]);
}

function grantMentorRole(int $tenantId, int $memberId, int $uid, int $now): void
{
    $roleId = (int) Db::table('ch_persona_role')
        ->where('tenant_id', $tenantId)
        ->where('code', 'mentor')
        ->where('status', 1)
        ->where('is_del', 0)
        ->value('id');
    if ($roleId <= 0) {
        throw new RuntimeException('Mentor role fixture was not found');
    }
    Db::table('ch_member_role')->insert([
        'tenant_id' => $tenantId,
        'member_id' => $memberId,
        'uid' => $uid,
        'role_id' => $roleId,
        'is_primary' => 1,
        'grant_source' => 0,
        'source_application_id' => 0,
        'status' => 1,
        'effective_time' => $now - 10,
        'expire_time' => 0,
        'revoke_time' => 0,
        'add_time' => $now,
        'update_time' => $now,
    ]);
}

function createRegistrationEvent(int $tenantId, int $channelId, string $eventNo, int $now): int
{
    return (int) Db::table('ch_event')->insertGetId([
        'tenant_id' => $tenantId,
        'channel_id' => $channelId,
        'event_no' => $eventNo,
        'event_type' => 'industry',
        'title' => 'Registration database test',
        'cover_image' => '',
        'summary' => 'Registration transaction fixture',
        'tags_json' => '[]',
        'speakers_json' => '[]',
        'detail' => '',
        'start_time' => $now + 7200,
        'end_time' => $now + 10800,
        'signup_start_time' => $now - 3600,
        'signup_end_time' => $now + 3600,
        'location_name' => 'Test venue',
        'address' => '',
        'longitude' => '0.000000',
        'latitude' => '0.000000',
        'min_tier' => 2,
        'eligibility_json' => json_encode([
            'allowed_channel_ids' => [$channelId],
            'min_points' => 50,
            'required_roles' => ['mentor'],
        ]),
        'refund_policy_json' => '{}',
        'checkin_reward_points' => 0,
        'checkin_reward_contribution' => 0,
        'status' => EventEligibility::EVENT_PUBLISHED,
        'created_admin_id' => 1,
        'publish_time' => $now - 100,
        'add_time' => $now,
        'update_time' => $now,
        'is_del' => 0,
    ]);
}

function createRegistrationTicket(
    int $tenantId,
    int $eventId,
    int $channelId,
    string $price,
    int $integral,
    int $capacity,
    int $paidCount,
    int $now
): int {
    return (int) Db::table('ch_event_ticket')->insertGetId([
        'tenant_id' => $tenantId,
        'event_id' => $eventId,
        'name' => 'Registration test ticket',
        'price' => $price,
        'integral_price' => $integral,
        'product_id' => $price === '0.00' ? 0 : 1,
        'product_attr_unique' => '',
        'capacity' => $capacity,
        'reserved_count' => 0,
        'paid_count' => $paidCount,
        'min_tier' => 2,
        'eligibility_json' => json_encode(['allowed_channel_ids' => [$channelId]]),
        'refund_policy_json' => '{}',
        'sale_start_time' => $now - 3600,
        'sale_end_time' => $now + 3600,
        'status' => 1,
        'sort' => 1,
        'add_time' => $now,
        'update_time' => $now,
        'is_del' => 0,
    ]);
}

function expectReason(string $reason, int $status, callable $callback): void
{
    try {
        $callback();
    } catch (MemberTransactionException $exception) {
        assertSame($reason, $exception->reason());
        assertSame($status, $exception->httpStatus());
        return;
    }

    throw new RuntimeException('Expected member transaction exception: ' . $reason);
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
