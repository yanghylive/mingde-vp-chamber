<?php

declare(strict_types=1);

use app\chamber\activity\EventEligibility;
use app\chamber\activity\EventListQuery;
use app\chamber\exceptions\MemberTransactionException;
use app\chamber\identity\AuthenticatedAdminContext;
use app\chamber\services\EventAdminService;
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
    $primaryTenant = adminReadTenant('local-primary');
    $secondaryTenant = adminReadTenant('local-secondary');
    $primaryChannel = adminReadChannel((int) $primaryTenant['id'], 'default');
    $secondaryChannel = adminReadChannel((int) $secondaryTenant['id'], 'default');
    $otherChannelId = (int) Db::table('ch_channel')->insertGetId([
        'tenant_id' => (int) $primaryTenant['id'],
        'name' => 'Admin read isolation ' . $runId,
        'code' => 'admin-read-' . $runId,
        'entry_key' => md5('admin-read-' . $runId),
        'status' => 1,
        'sort' => 0,
        'add_time' => $now,
        'update_time' => $now,
        'is_del' => 0,
    ]);

    $draftId = createAdminReadEvent(
        (int) $primaryTenant['id'],
        (int) $primaryChannel['id'],
        'AD' . strtoupper($runId),
        'industry',
        ['AI', '草稿'],
        EventEligibility::EVENT_DRAFT,
        $now
    );
    createAdminReadTicket((int) $primaryTenant['id'], $draftId, $now);
    $endedId = createAdminReadEvent(
        (int) $primaryTenant['id'],
        (int) $primaryChannel['id'],
        'AE' . strtoupper($runId),
        'growth',
        ['历史'],
        EventEligibility::EVENT_ENDED,
        $now - 1
    );
    createAdminReadEvent(
        (int) $primaryTenant['id'],
        $otherChannelId,
        'AC' . strtoupper($runId),
        'industry',
        ['AI'],
        EventEligibility::EVENT_DRAFT,
        $now + 2
    );
    $otherTenantEventId = createAdminReadEvent(
        (int) $secondaryTenant['id'],
        (int) $secondaryChannel['id'],
        'AT' . strtoupper($runId),
        'industry',
        ['AI'],
        EventEligibility::EVENT_DRAFT,
        $now + 3
    );
    $deletedId = createAdminReadEvent(
        (int) $primaryTenant['id'],
        (int) $primaryChannel['id'],
        'AX' . strtoupper($runId),
        'industry',
        ['AI'],
        EventEligibility::EVENT_DRAFT,
        $now + 4
    );
    Db::table('ch_event')->where('id', $deletedId)->update(['is_del' => 1]);

    $tenant = adminReadContext($primaryTenant, $primaryChannel);
    $service = new EventAdminService();
    $admin = new AuthenticatedAdminContext(990001, true, []);
    $deniedAdmin = new AuthenticatedAdminContext(990002, false, []);
    $scopedNonSuperAdmin = new AuthenticatedAdminContext(990003, false, ['chamber.event.manage']);

    $page = $service->listForAdmin($tenant, $admin, EventListQuery::fromArray([
        'page' => 1,
        'limit' => 1,
    ]));
    adminReadAssertSame(2, $page['page']['total']);
    adminReadAssertSame(2, $page['page']['total_pages']);
    adminReadAssertSame(true, $page['page']['has_more']);
    adminReadAssertSame(1, count($page['items']));
    adminReadAssertSame($draftId, $page['items'][0]['id']);
    adminReadAssertSame(EventEligibility::EVENT_DRAFT, $page['items'][0]['status']);
    adminReadAssertSame(1, count($page['items'][0]['tickets']));

    $filtered = $service->listForAdmin($tenant, $admin, EventListQuery::fromArray([
        'status' => 'ended',
        'tag' => '历史',
    ]));
    adminReadAssertSame(1, $filtered['page']['total']);
    adminReadAssertSame($endedId, $filtered['items'][0]['id']);

    $detail = $service->detailForAdmin($tenant, $admin, $draftId);
    adminReadAssertSame($draftId, $detail['id']);
    adminReadAssertSame('Admin read activity', $detail['title']);
    adminReadAssertSame('Admin ticket', $detail['tickets'][0]['name']);

    adminReadExpectReason('permission_denied', 403, function () use ($service, $tenant, $deniedAdmin): void {
        $service->listForAdmin($tenant, $deniedAdmin, EventListQuery::fromArray([]));
    });
    adminReadExpectReason('permission_denied', 403, function () use ($service, $tenant, $deniedAdmin, $draftId): void {
        $service->detailForAdmin($tenant, $deniedAdmin, $draftId);
    });
    adminReadExpectReason('permission_denied', 403, function () use ($service, $tenant, $scopedNonSuperAdmin): void {
        $service->listForAdmin($tenant, $scopedNonSuperAdmin, EventListQuery::fromArray([]));
    });
    adminReadExpectReason('permission_denied', 403, function () use ($service, $tenant, $scopedNonSuperAdmin, $draftId): void {
        $service->detailForAdmin($tenant, $scopedNonSuperAdmin, $draftId);
    });
    adminReadExpectReason('event_not_found', 404, function () use ($service, $tenant, $admin, $otherTenantEventId): void {
        $service->detailForAdmin($tenant, $admin, $otherTenantEventId);
    });
    adminReadExpectReason('event_not_found', 404, function () use ($service, $tenant, $admin, $otherChannelId): void {
        $eventId = (int) Db::table('ch_event')->where('channel_id', $otherChannelId)->value('id');
        $service->detailForAdmin($tenant, $admin, $eventId);
    });

    fwrite(STDOUT, sprintf("Activity admin read integration passed (%d assertions).\n", $assertions));
} finally {
    Db::rollback();
}

function adminReadTenant(string $slug): array
{
    $row = Db::table('ch_tenant')->where('slug', $slug)->where('status', 1)->where('is_del', 0)->find();
    if (!is_array($row)) {
        throw new RuntimeException('Tenant fixture was not found: ' . $slug);
    }

    return $row;
}

function adminReadChannel(int $tenantId, string $code): array
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

function adminReadContext(array $tenant, array $channel): TenantContext
{
    return new TenantContext(new TenantRecord(
        (int) $tenant['id'],
        (string) $tenant['slug'],
        (int) $channel['id'],
        (string) $channel['code'],
        true
    ), 'admin-read-db-test');
}

function createAdminReadEvent(
    int $tenantId,
    int $channelId,
    string $eventNo,
    string $eventType,
    array $tags,
    int $status,
    int $addTime
): int {
    return (int) Db::table('ch_event')->insertGetId([
        'tenant_id' => $tenantId,
        'channel_id' => $channelId,
        'event_no' => $eventNo,
        'event_type' => $eventType,
        'title' => 'Admin read activity',
        'cover_image' => '',
        'summary' => 'Tenant and channel scoped administration test',
        'detail' => '',
        'tags_json' => json_encode($tags, JSON_UNESCAPED_UNICODE),
        'speakers_json' => '[]',
        'start_time' => $addTime + 7200,
        'end_time' => $addTime + 10800,
        'signup_start_time' => $addTime - 3600,
        'signup_end_time' => $addTime + 3600,
        'location_name' => '',
        'address' => '',
        'longitude' => '0.000000',
        'latitude' => '0.000000',
        'min_tier' => 1,
        'eligibility_json' => '{}',
        'refund_policy_json' => '{}',
        'checkin_reward_points' => 0,
        'checkin_reward_contribution' => 0,
        'status' => $status,
        'created_admin_id' => 990001,
        'publish_time' => $status === EventEligibility::EVENT_DRAFT ? 0 : $addTime,
        'add_time' => $addTime,
        'update_time' => $addTime,
        'is_del' => 0,
    ]);
}

function createAdminReadTicket(int $tenantId, int $eventId, int $now): int
{
    return (int) Db::table('ch_event_ticket')->insertGetId([
        'tenant_id' => $tenantId,
        'event_id' => $eventId,
        'name' => 'Admin ticket',
        'price' => '0.00',
        'integral_price' => 0,
        'product_id' => 0,
        'product_attr_unique' => '',
        'capacity' => 20,
        'reserved_count' => 0,
        'paid_count' => 0,
        'min_tier' => 1,
        'eligibility_json' => '{}',
        'refund_policy_json' => '{}',
        'sale_start_time' => $now - 3600,
        'sale_end_time' => $now + 3600,
        'status' => 1,
        'sort' => 0,
        'add_time' => $now,
        'update_time' => $now,
        'is_del' => 0,
    ]);
}

function adminReadExpectReason(string $reason, int $status, callable $callback): void
{
    global $assertions;
    try {
        $callback();
    } catch (MemberTransactionException $exception) {
        adminReadAssertSame($reason, $exception->reason());
        adminReadAssertSame($status, $exception->httpStatus());
        return;
    }

    $assertions++;
    throw new RuntimeException('Expected member transaction exception: ' . $reason);
}

function adminReadAssertSame($expected, $actual): void
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
