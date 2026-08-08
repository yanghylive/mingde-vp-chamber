<?php

declare(strict_types=1);

use app\chamber\activity\EventEligibility;
use app\chamber\exceptions\MemberTransactionException;
use app\chamber\identity\AuthenticatedAdminContext;
use app\chamber\membership\BootstrapIdempotency;
use app\chamber\services\EventAdminService;
use app\chamber\services\EventIdempotency;
use app\chamber\services\EventRewardService;
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
    $primaryTenant = adminWriteTenant('local-primary');
    $secondaryTenant = adminWriteTenant('local-secondary');
    $primaryChannel = adminWriteChannel((int) $primaryTenant['id'], 'default');
    $secondaryChannel = adminWriteChannel((int) $secondaryTenant['id'], 'default');
    $tenant = adminWriteContext($primaryTenant, $primaryChannel);
    $secondaryContext = adminWriteContext($secondaryTenant, $secondaryChannel);
    $service = new EventAdminService(
        new EventRewardService(),
        new EventIdempotency(function () use ($now): int {
            return $now;
        })
    );
    $admin = new AuthenticatedAdminContext(980001, true, []);
    $denied = new AuthenticatedAdminContext(980002, false, []);
    $scopedNonSuper = new AuthenticatedAdminContext(980003, false, [
        'chamber.event.manage',
        'chamber.event.checkin',
    ]);
    $payload = adminWritePayload($now, $runId);

    adminWriteExpectReason('permission_denied', 403, function () use ($service, $tenant, $denied, $payload, $runId): void {
        $service->create($tenant, $denied, $payload, 'admin-write-denied-create-' . $runId);
    });

    $createKey = 'admin-write-create-' . $runId;
    $draft = $service->create($tenant, $admin, $payload, $createKey);
    $draftReplay = $service->create($tenant, $admin, $payload, $createKey);
    adminWriteAssertSame(
        BootstrapIdempotency::canonicalJson($draft),
        BootstrapIdempotency::canonicalJson($draftReplay)
    );
    adminWriteAssertSame((int) $primaryTenant['id'], (int) Db::table('ch_event')->where('id', (int) $draft['id'])->value('tenant_id'));
    adminWriteAssertSame((int) $primaryChannel['id'], (int) Db::table('ch_event')->where('id', (int) $draft['id'])->value('channel_id'));
    adminWriteAssertSame(1, (int) Db::table('ch_event')->where('id', (int) $draft['id'])->count());
    adminWriteExpectReason('idempotency_conflict', 409, function () use ($service, $tenant, $admin, $payload, $createKey): void {
        $changed = $payload;
        $changed['title'] = 'Different create payload';
        $service->create($tenant, $admin, $changed, $createKey);
    });

    $foreign = $service->create(
        $secondaryContext,
        $admin,
        $payload,
        'admin-write-foreign-' . $runId
    );
    $draftId = (int) $draft['id'];
    $foreignId = (int) $foreign['id'];
    adminWriteExpectReason('permission_denied', 403, function () use ($service, $tenant, $denied, $draftId, $runId): void {
        $service->update($tenant, $denied, $draftId, ['title' => 'Denied'], 'admin-write-denied-update-' . $runId);
    });
    adminWriteExpectReason('permission_denied', 403, function () use ($service, $tenant, $denied, $draftId, $runId): void {
        $service->publish($tenant, $denied, $draftId, 'admin-write-denied-publish-' . $runId);
    });
    adminWriteExpectReason('permission_denied', 403, function () use ($service, $tenant, $denied, $draftId, $runId): void {
        $service->cancel($tenant, $denied, $draftId, '', 'admin-write-denied-cancel-' . $runId);
    });
    adminWriteExpectReason('permission_denied', 403, function () use ($service, $tenant, $scopedNonSuper, $draftId, $runId): void {
        $service->issueCheckinToken($tenant, $scopedNonSuper, $draftId, 300, 'admin-write-denied-token-' . $runId);
    });
    adminWriteExpectReason('permission_denied', 403, function () use ($service, $tenant, $scopedNonSuper, $draftId, $runId): void {
        $service->manualCheckin($tenant, $scopedNonSuper, $draftId, 1, 'Denied', 'admin-write-denied-manual-' . $runId);
    });

    adminWriteExpectReason('event_not_found', 404, function () use ($service, $tenant, $admin, $foreignId, $runId): void {
        $service->update($tenant, $admin, $foreignId, ['title' => 'Cross tenant'], 'admin-write-foreign-update-' . $runId);
    });
    adminWriteExpectReason('event_not_found', 404, function () use ($service, $tenant, $admin, $foreignId, $runId): void {
        $service->publish($tenant, $admin, $foreignId, 'admin-write-foreign-publish-' . $runId);
    });
    adminWriteExpectReason('event_not_found', 404, function () use ($service, $tenant, $admin, $foreignId, $runId): void {
        $service->cancel($tenant, $admin, $foreignId, '', 'admin-write-foreign-cancel-' . $runId);
    });
    adminWriteExpectReason('event_not_found', 404, function () use ($service, $tenant, $admin, $foreignId, $runId): void {
        $service->issueCheckinToken($tenant, $admin, $foreignId, 300, 'admin-write-foreign-token-' . $runId);
    });
    adminWriteExpectReason('event_not_found', 404, function () use ($service, $tenant, $admin, $foreignId, $runId): void {
        $service->manualCheckin($tenant, $admin, $foreignId, 1, 'Cross tenant', 'admin-write-foreign-manual-' . $runId);
    });

    $noOp = $service->update(
        $tenant,
        $admin,
        $draftId,
        ['title' => $payload['title']],
        'admin-write-noop-' . $runId
    );
    adminWriteAssertSame($payload['title'], $noOp['title']);
    $updateKey = 'admin-write-update-' . $runId;
    $updated = $service->update($tenant, $admin, $draftId, ['title' => 'Updated event'], $updateKey);
    $updatedReplay = $service->update($tenant, $admin, $draftId, ['title' => 'Updated event'], $updateKey);
    adminWriteAssertSame('Updated event', $updated['title']);
    adminWriteAssertSame(
        BootstrapIdempotency::canonicalJson($updated),
        BootstrapIdempotency::canonicalJson($updatedReplay)
    );
    adminWriteExpectReason('idempotency_conflict', 409, function () use ($service, $tenant, $admin, $draftId, $updateKey): void {
        $service->update($tenant, $admin, $draftId, ['title' => 'Changed replay'], $updateKey);
    });

    adminWriteExpectReason('event_not_open', 409, function () use ($service, $tenant, $admin, $draftId, $runId): void {
        $service->issueCheckinToken($tenant, $admin, $draftId, 300, 'admin-write-draft-token-' . $runId);
    });
    $publishKey = 'admin-write-publish-' . $runId;
    $published = $service->publish($tenant, $admin, $draftId, $publishKey);
    $publishedReplay = $service->publish($tenant, $admin, $draftId, $publishKey);
    adminWriteAssertSame(EventEligibility::EVENT_PUBLISHED, $published['status']);
    adminWriteAssertSame(
        BootstrapIdempotency::canonicalJson($published),
        BootstrapIdempotency::canonicalJson($publishedReplay)
    );
    adminWriteExpectReason('event_publish_locked', 409, function () use ($service, $tenant, $admin, $draftId, $runId): void {
        $service->publish($tenant, $admin, $draftId, 'admin-write-publish-again-' . $runId);
    });
    adminWriteExpectReason('event_edit_locked', 409, function () use ($service, $tenant, $admin, $draftId, $runId): void {
        $service->update($tenant, $admin, $draftId, ['title' => 'Locked'], 'admin-write-update-published-' . $runId);
    });

    $tokenKey = 'admin-write-token-' . $runId;
    $token = $service->issueCheckinToken($tenant, $admin, $draftId, 300, $tokenKey);
    $tokenReplay = $service->issueCheckinToken($tenant, $admin, $draftId, 300, $tokenKey);
    adminWriteAssertSame((int) $token['token_id'], (int) $tokenReplay['token_id']);
    adminWriteAssertSame((string) $token['token'], (string) $tokenReplay['token']);
    adminWriteAssertSame(1, (int) Db::table('ch_event_checkin_token')->where('event_id', $draftId)->count());
    $tokenRecord = adminWriteIdempotencyRecord($tenant, $admin, 'issueEventCheckinTokenForAdmin', $tokenKey);
    adminWriteAssertSame(false, strpos((string) $tokenRecord['result_json'], (string) $token['token']) !== false);
    adminWriteExpectReason('idempotency_conflict', 409, function () use ($service, $tenant, $admin, $draftId, $tokenKey): void {
        $service->issueCheckinToken($tenant, $admin, $draftId, 600, $tokenKey);
    });

    $member = adminWriteMemberFixture((int) $primaryTenant['id'], (int) $primaryChannel['id'], $now, $runId);
    $registrationId = adminWriteRegistration(
        (int) $primaryTenant['id'],
        $draftId,
        (int) $published['tickets'][0]['id'],
        $member['member_id'],
        $member['uid'],
        $now,
        $runId
    );
    $manualKey = 'admin-write-manual-' . $runId;
    $manual = $service->manualCheckin(
        $tenant,
        $admin,
        $draftId,
        $registrationId,
        'Verified by operator',
        $manualKey
    );
    $manualReplay = $service->manualCheckin(
        $tenant,
        $admin,
        $draftId,
        $registrationId,
        'Verified by operator',
        $manualKey
    );
    adminWriteAssertSame((int) $manual['id'], (int) $manualReplay['id']);
    adminWriteAssertSame(1, (int) Db::table('ch_event_checkin')->where('registration_id', $registrationId)->count());
    adminWriteAssertSame(5, (int) Db::table('ch_event_registration')->where('id', $registrationId)->value('status'));
    adminWriteAssertSame(107, (int) Db::table('ch_point_account')->where('member_id', $member['member_id'])->value('balance'));
    adminWriteAssertSame(1, (int) Db::table('ch_event_reward')->where('registration_id', $registrationId)->count());
    adminWriteAssertSame(1, (int) Db::table('ch_contribution_ledger')->where('member_id', $member['member_id'])->count());
    adminWriteExpectReason('idempotency_conflict', 409, function () use ($service, $tenant, $admin, $draftId, $registrationId, $manualKey): void {
        $service->manualCheckin($tenant, $admin, $draftId, $registrationId, 'Changed reason', $manualKey);
    });
    adminWriteExpectReason('checkin_already_completed', 409, function () use ($service, $tenant, $admin, $draftId, $registrationId, $runId): void {
        $service->manualCheckin($tenant, $admin, $draftId, $registrationId, 'Verified again', 'admin-write-manual-again-' . $runId);
    });
    adminWriteExpectReason('event_cancel_has_registrations', 409, function () use ($service, $tenant, $admin, $draftId, $runId): void {
        $service->cancel($tenant, $admin, $draftId, 'Has attendee', 'admin-write-cancel-active-' . $runId);
    });

    $cancelDraft = $service->create($tenant, $admin, $payload, 'admin-write-cancel-create-' . $runId);
    $cancelId = (int) $cancelDraft['id'];
    $service->publish($tenant, $admin, $cancelId, 'admin-write-cancel-publish-' . $runId);
    $cancelKey = 'admin-write-cancel-' . $runId;
    $cancelled = $service->cancel($tenant, $admin, $cancelId, 'Cancelled by operator', $cancelKey);
    $cancelledReplay = $service->cancel($tenant, $admin, $cancelId, 'Cancelled by operator', $cancelKey);
    adminWriteAssertSame(EventEligibility::EVENT_CANCELLED, $cancelled['status']);
    adminWriteAssertSame(
        BootstrapIdempotency::canonicalJson($cancelled),
        BootstrapIdempotency::canonicalJson($cancelledReplay)
    );
    adminWriteAssertSame(1, (int) Db::table('ch_audit_record')
        ->where('business_type', 'event')->where('business_id', $cancelId)->where('action', 'cancel')->count());
    adminWriteExpectReason('event_not_open', 409, function () use ($service, $tenant, $admin, $cancelId, $runId): void {
        $service->issueCheckinToken($tenant, $admin, $cancelId, 300, 'admin-write-cancelled-token-' . $runId);
    });
    adminWriteExpectReason('event_not_open', 409, function () use ($service, $tenant, $admin, $cancelId, $registrationId, $runId): void {
        $service->manualCheckin($tenant, $admin, $cancelId, $registrationId, 'Cancelled event', 'admin-write-cancelled-manual-' . $runId);
    });

    $endedId = adminWriteRawEvent(
        (int) $primaryTenant['id'],
        (int) $primaryChannel['id'],
        EventEligibility::EVENT_ENDED,
        $now,
        $runId
    );
    adminWriteExpectReason('event_cancel_locked', 409, function () use ($service, $tenant, $admin, $endedId, $runId): void {
        $service->cancel($tenant, $admin, $endedId, 'Ended', 'admin-write-cancel-ended-' . $runId);
    });

    fwrite(STDOUT, sprintf("Activity admin write integration passed (%d assertions).\n", $assertions));
} finally {
    Db::rollback();
}

function adminWriteTenant(string $slug): array
{
    $row = Db::table('ch_tenant')->where('slug', $slug)->where('status', 1)->where('is_del', 0)->find();
    if (!is_array($row)) {
        throw new RuntimeException('Tenant fixture was not found: ' . $slug);
    }
    return $row;
}

function adminWriteChannel(int $tenantId, string $code): array
{
    $row = Db::table('ch_channel')->where('tenant_id', $tenantId)->where('code', $code)
        ->where('status', 1)->where('is_del', 0)->find();
    if (!is_array($row)) {
        throw new RuntimeException('Channel fixture was not found');
    }
    return $row;
}

function adminWriteContext(array $tenant, array $channel): TenantContext
{
    return new TenantContext(new TenantRecord(
        (int) $tenant['id'],
        (string) $tenant['slug'],
        (int) $channel['id'],
        (string) $channel['code'],
        true
    ), 'admin-write-db-test');
}

function adminWritePayload(int $now, string $runId): array
{
    return [
        'event_type' => 'industry',
        'title' => 'Admin write ' . $runId,
        'summary' => 'Admin write database acceptance',
        'tags' => ['AI', 'admin'],
        'start_time' => $now + 14400,
        'end_time' => $now + 18000,
        'signup_start_time' => $now - 600,
        'signup_end_time' => $now + 7200,
        'location_name' => 'Admin venue',
        'checkin_reward_points' => 7,
        'checkin_reward_contribution' => 2,
        'tickets' => [[
            'name' => 'Admin free ticket',
            'price' => '0.00',
            'integral_price' => 0,
            'capacity' => 20,
            'sale_start_time' => $now - 600,
            'sale_end_time' => $now + 7200,
            'status' => 1,
        ]],
    ];
}

function adminWriteMemberFixture(int $tenantId, int $channelId, int $now, string $runId): array
{
    $uid = (int) Db::table('ch_tenant_member')->max('uid') + random_int(100, 10000);
    $memberId = (int) Db::table('ch_tenant_member')->insertGetId([
        'tenant_id' => $tenantId, 'uid' => $uid,
        'first_channel_id' => $channelId, 'current_channel_id' => $channelId,
        'referrer_uid' => 0, 'invite_code' => 'AW' . strtoupper(substr($runId, 0, 14)),
        'attribution_locked_time' => $now, 'tier' => 2, 'verification_status' => 2,
        'current_verification_id' => 0, 'status' => 1, 'join_time' => $now,
        'certified_time' => $now, 'tier_expire_time' => 0,
        'current_membership_term_id' => 0, 'membership_version' => 1,
        'add_time' => $now, 'update_time' => $now, 'is_del' => 0,
    ]);
    Db::table('ch_point_account')->insert([
        'tenant_id' => $tenantId, 'member_id' => $memberId, 'uid' => $uid,
        'balance' => 100, 'frozen_balance' => 0, 'version' => 1,
        'add_time' => $now, 'update_time' => $now,
    ]);
    return ['uid' => $uid, 'member_id' => $memberId];
}

function adminWriteRegistration(
    int $tenantId,
    int $eventId,
    int $ticketId,
    int $memberId,
    int $uid,
    int $now,
    string $runId
): int {
    return (int) Db::table('ch_event_registration')->insertGetId([
        'tenant_id' => $tenantId, 'event_id' => $eventId, 'ticket_id' => $ticketId,
        'member_id' => $memberId, 'uid' => $uid,
        'registration_no' => 'AW' . strtoupper(substr($runId, 0, 20)),
        'order_pk' => 0, 'order_no' => '', 'order_context_id' => 0,
        'amount' => '0.00', 'integral_amount' => 0, 'status' => 1,
        'reserve_expire_time' => 0, 'paid_time' => $now,
        'cancel_time' => 0, 'refund_time' => 0,
        'add_time' => $now, 'update_time' => $now,
    ]);
}

function adminWriteRawEvent(int $tenantId, int $channelId, int $status, int $now, string $runId): int
{
    return (int) Db::table('ch_event')->insertGetId([
        'tenant_id' => $tenantId, 'channel_id' => $channelId,
        'event_no' => 'AWEND' . strtoupper(substr($runId, 0, 20)),
        'event_type' => 'growth', 'title' => 'Ended admin event',
        'cover_image' => '', 'summary' => '', 'detail' => '',
        'tags_json' => '[]', 'speakers_json' => '[]',
        'start_time' => $now - 7200, 'end_time' => $now - 3600,
        'signup_start_time' => $now - 14400, 'signup_end_time' => $now - 10800,
        'location_name' => '', 'address' => '',
        'longitude' => '0.000000', 'latitude' => '0.000000',
        'min_tier' => 1, 'eligibility_json' => '{}', 'refund_policy_json' => '{}',
        'checkin_reward_points' => 0, 'checkin_reward_contribution' => 0,
        'status' => $status, 'created_admin_id' => 980001, 'publish_time' => $now - 14400,
        'add_time' => $now, 'update_time' => $now, 'is_del' => 0,
    ]);
}

function adminWriteIdempotencyRecord(
    TenantContext $tenant,
    AuthenticatedAdminContext $admin,
    string $operation,
    string $callerKey
): array {
    $internalKey = BootstrapIdempotency::deriveInternalKey(
        $tenant->tenantId(),
        $operation,
        'crmeb_admin',
        $admin->adminId(),
        $callerKey
    );
    $row = Db::table('ch_idempotency_record')->where('tenant_id', $tenant->tenantId())
        ->where('idempotency_key', $internalKey)->find();
    if (!is_array($row)) {
        throw new RuntimeException('Idempotency record was not found: ' . $operation);
    }
    return $row;
}

function adminWriteExpectReason(string $reason, int $status, callable $callback): void
{
    global $assertions;
    try {
        $callback();
    } catch (MemberTransactionException $exception) {
        adminWriteAssertSame($reason, $exception->reason());
        adminWriteAssertSame($status, $exception->httpStatus());
        return;
    }
    $assertions++;
    throw new RuntimeException('Expected member transaction exception: ' . $reason);
}

function adminWriteAssertSame($expected, $actual): void
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
