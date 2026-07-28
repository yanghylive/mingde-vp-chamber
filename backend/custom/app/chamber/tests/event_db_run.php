<?php

declare(strict_types=1);

use app\chamber\activity\EventCheckinRequest;
use app\chamber\activity\EventCheckinToken;
use app\chamber\activity\EventEligibility;
use app\chamber\activity\EventListQuery;
use app\chamber\activity\EventRegistrationListQuery;
use app\chamber\exceptions\MemberTransactionException;
use app\chamber\identity\AuthenticatedUserContext;
use app\chamber\services\EventCheckinService;
use app\chamber\services\EventRewardService;
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

Db::startTrans();
try {
    $primaryTenant = tenant('local-primary');
    $secondaryTenant = tenant('local-secondary');
    $primaryChannel = channel((int) $primaryTenant['id'], 'default');
    $secondaryChannel = channel((int) $secondaryTenant['id'], 'default');
    $tenant = context($primaryTenant, $primaryChannel);
    $uid = (int) Db::table('ch_tenant_member')->max('uid') + random_int(100, 10000);

    $memberId = (int) Db::table('ch_tenant_member')->insertGetId([
        'tenant_id' => (int) $primaryTenant['id'],
        'uid' => $uid,
        'first_channel_id' => (int) $primaryChannel['id'],
        'current_channel_id' => (int) $primaryChannel['id'],
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
    Db::table('ch_point_account')->insert([
        'tenant_id' => (int) $primaryTenant['id'],
        'member_id' => $memberId,
        'uid' => $uid,
        'balance' => 100,
        'version' => 1,
        'add_time' => $now,
        'update_time' => $now,
    ]);
    $role = Db::table('ch_persona_role')
        ->where('tenant_id', (int) $primaryTenant['id'])
        ->where('code', 'mentor')
        ->where('status', 1)
        ->where('is_del', 0)
        ->find();
    assertTrue(is_array($role), 'mentor role fixture is required');
    Db::table('ch_member_role')->insert([
        'tenant_id' => (int) $primaryTenant['id'],
        'member_id' => $memberId,
        'uid' => $uid,
        'role_id' => (int) $role['id'],
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

    $eventId = createEvent(
        (int) $primaryTenant['id'],
        (int) $primaryChannel['id'],
        'EP' . strtoupper($runId),
        'industry',
        ['AI 应用', '闭门交流'],
        EventEligibility::EVENT_PUBLISHED,
        $now
    );
    createTicket((int) $primaryTenant['id'], $eventId, $primaryChannel, $now);
    $otherTenantEventId = createEvent(
        (int) $secondaryTenant['id'],
        (int) $secondaryChannel['id'],
        'ES' . strtoupper($runId),
        'industry',
        ['AI 应用'],
        EventEligibility::EVENT_PUBLISHED,
        $now
    );
    createEvent(
        (int) $primaryTenant['id'],
        (int) $primaryChannel['id'],
        'EE' . strtoupper($runId),
        'growth',
        ['历史活动'],
        EventEligibility::EVENT_ENDED,
        $now
    );
    $registrationId = (int) Db::table('ch_event_registration')->insertGetId([
        'tenant_id' => (int) $primaryTenant['id'],
        'event_id' => $eventId,
        'ticket_id' => (int) Db::table('ch_event_ticket')->where('event_id', $eventId)->value('id'),
        'member_id' => $memberId,
        'uid' => $uid,
        'registration_no' => 'R' . strtoupper($runId),
        'order_pk' => 0,
        'order_no' => '',
        'order_context_id' => 0,
        'amount' => '0.00',
        'integral_amount' => 0,
        'status' => 1,
        'reserve_expire_time' => 0,
        'paid_time' => $now,
        'cancel_time' => 0,
        'refund_time' => 0,
        'add_time' => $now,
        'update_time' => $now,
    ]);

    $service = new EventService(function () use ($now): int {
        return $now;
    });
    $auth = new AuthenticatedUserContext($uid, true, 'api');
    $list = $service->list($tenant, $auth, EventListQuery::fromArray([
        'event_type' => 'industry',
        'tag' => 'AI 应用',
        'page' => 1,
        'limit' => 20,
    ]));

    assertSame(1, $list['page']['total']);
    assertSame(1, count($list['items']));
    assertSame($eventId, $list['items'][0]['id']);
    assertSame(true, $list['items'][0]['registered']);
    assertSame('registered', $list['items'][0]['registration_status']);
    assertSame(true, $list['items'][0]['tickets'][0]['eligible']);
    assertSame(null, $list['items'][0]['tickets'][0]['ineligible_reason']);
    assertSame(['allowed_channel_ids' => [(int) $primaryChannel['id']], 'min_points' => 50, 'required_roles' => ['mentor']], $list['items'][0]['eligibility']);

    $detail = $service->detail($tenant, $auth, $eventId);
    assertSame($eventId, $detail['id']);
    assertSame('AI 活动数据库测试', $detail['title']);
    assertSame('none', $detail['refund_policy']['mode']);
    assertSame('registered', $detail['registration_status']);

    $registrations = $service->listRegistrations(
        $tenant,
        $auth,
        EventRegistrationListQuery::fromArray(['status' => 'registered'])
    );
    assertSame(1, $registrations['page']['total']);
    assertSame($registrationId, $registrations['items'][0]['id']);
    assertSame('registered', $registrations['items'][0]['status']);
    assertSame(null, $registrations['items'][0]['order_status']);
    assertSame(false, $registrations['items'][0]['payment_required']);
    assertSame(false, $registrations['items'][0]['checked_in']);
    $registrationDetail = $service->registrationDetail($tenant, $auth, $registrationId);
    assertSame($registrationId, $registrationDetail['id']);
    assertSame('0.00', $registrationDetail['amount']);

    $issued = EventCheckinToken::issue(
        (int) $primaryTenant['id'],
        $eventId,
        $now,
        300,
        (string) getenv('CHAMBER_TENANT_SIGNING_SECRET')
    );
    Db::table('ch_event_checkin_token')->insert([
        'tenant_id' => (int) $primaryTenant['id'],
        'event_id' => $eventId,
        'token_digest' => $issued['digest'],
        'issued_by_admin_id' => 1,
        'valid_from' => $issued['valid_from'],
        'expires_time' => $issued['expires_time'],
        'status' => 1,
        'add_time' => $now,
        'update_time' => $now,
    ]);
    $checkinService = new EventCheckinService(new EventRewardService(), function () use ($now): int {
        return $now;
    });
    $checkinRequest = EventCheckinRequest::fromArray([
        'token' => $issued['token'],
        'registration_id' => $registrationId,
    ]);
    $checkin = $checkinService->checkin(
        $tenant,
        $auth,
        $eventId,
        $checkinRequest,
        'event-checkin-db-' . $runId
    );
    assertSame(false, $checkin['replayed']);
    assertSame('scan', $checkin['checkin_type']);
    $replayedCheckin = $checkinService->checkin(
        $tenant,
        $auth,
        $eventId,
        $checkinRequest,
        'event-checkin-db-' . $runId
    );
    assertSame(true, $replayedCheckin['replayed']);
    assertSame($checkin['id'], $replayedCheckin['id']);
    assertSame(125, (int) Db::table('ch_point_account')->where('member_id', $memberId)->value('balance'));
    assertSame(1, (int) Db::table('ch_event_reward')->where('registration_id', $registrationId)->count());
    assertSame(1, (int) Db::table('ch_contribution_ledger')->where('member_id', $memberId)->count());
    assertSame(5, (int) Db::table('ch_event_registration')->where('id', $registrationId)->value('status'));
    assertSame(true, $service->registrationDetail($tenant, $auth, $registrationId)['checked_in']);

    $ended = $service->list($tenant, $auth, EventListQuery::fromArray(['status' => 'ended']));
    assertSame(0, $ended['page']['total']);
    expectReason('event_not_found', 404, function () use ($service, $tenant, $auth, $otherTenantEventId): void {
        $service->detail($tenant, $auth, $otherTenantEventId);
    });

    fwrite(STDOUT, sprintf("Activity database integration passed (%d assertions).\n", $assertions));
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
    ), 'db-test');
}

function createEvent(
    int $tenantId,
    int $channelId,
    string $eventNo,
    string $eventType,
    array $tags,
    int $status,
    int $now
): int {
    return (int) Db::table('ch_event')->insertGetId([
        'tenant_id' => $tenantId,
        'channel_id' => $channelId,
        'event_no' => $eventNo,
        'event_type' => $eventType,
        'title' => 'AI 活动数据库测试',
        'cover_image' => 'events/test-cover.jpg',
        'summary' => '租户隔离和资格投影测试',
        'tags_json' => json_encode($tags, JSON_UNESCAPED_UNICODE),
        'speakers_json' => '[]',
        'detail' => '<p>test</p>',
        'start_time' => $now + 7200,
        'end_time' => $now + 10800,
        'signup_start_time' => $now - 3600,
        'signup_end_time' => $now + 3600,
        'location_name' => '测试会场',
        'address' => '测试路 1 号',
        'longitude' => '123.400000',
        'latitude' => '41.800000',
        'min_tier' => 2,
        'eligibility_json' => json_encode([
            'allowed_channel_ids' => [$channelId],
            'min_points' => 50,
            'required_roles' => ['mentor'],
        ]),
        'refund_policy_json' => '{}',
        'checkin_reward_points' => 25,
        'checkin_reward_contribution' => 3,
        'status' => $status,
        'created_admin_id' => 1,
        'publish_time' => $now - 100,
        'add_time' => $now,
        'update_time' => $now,
        'is_del' => 0,
    ]);
}

function createTicket(int $tenantId, int $eventId, array $channel, int $now): int
{
    return (int) Db::table('ch_event_ticket')->insertGetId([
        'tenant_id' => $tenantId,
        'event_id' => $eventId,
        'name' => '会员票',
        'price' => '0.00',
        'integral_price' => 0,
        'product_id' => 0,
        'product_attr_unique' => '',
        'capacity' => 20,
        'reserved_count' => 0,
        'paid_count' => 1,
        'min_tier' => 2,
        'eligibility_json' => json_encode(['allowed_channel_ids' => [(int) $channel['id']]]),
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
    global $assertions;
    try {
        $callback();
    } catch (MemberTransactionException $exception) {
        assertSame($reason, $exception->reason());
        assertSame($status, $exception->httpStatus());
        return;
    }

    $assertions++;
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
