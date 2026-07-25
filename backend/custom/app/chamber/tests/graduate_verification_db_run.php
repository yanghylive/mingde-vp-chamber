<?php

declare(strict_types=1);

use app\chamber\assets\LocalPrivateAssetStorage;
use app\chamber\exceptions\MemberTransactionException;
use app\chamber\identity\AuthenticatedAdminContext;
use app\chamber\identity\AuthenticatedUserContext;
use app\chamber\membership\BootstrapIdempotency;
use app\chamber\membership\GraduateVerificationState;
use app\chamber\services\GraduateVerificationIdempotency;
use app\chamber\services\GraduateVerificationService;
use app\chamber\services\MemberAssetIdempotency;
use app\chamber\services\MemberAssetService;
use app\chamber\tenancy\TenantContext;
use app\chamber\tenancy\TenantRecord;
use app\chamber\verification\GraduateVerificationAdminQuery;
use app\chamber\verification\GraduateVerificationReviewRequest;
use app\chamber\verification\GraduateVerificationSubmission;
use think\App;
use think\facade\Db;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

(new App())->initialize();

$runId = strtolower(bin2hex(random_bytes(5)));
$accountPrefix = 'g1cdbtest' . $runId;
$idempotencyKeys = [];
$assertions = 0;

try {
    assertDatabasePrerequisites();
    $primary = tenantContext('local-primary');
    $secondary = tenantContext('local-secondary');
    $wrongChannel = new TenantContext(new TenantRecord(
        $primary->tenantId(),
        $primary->tenantSlug(),
        $secondary->channelId(),
        'wrong-channel',
        true
    ), 'graduate_verification_db_test');

    $l2Fixture = createMemberFixture($primary, $accountPrefix . '_l2', 'G1C L2 Member');
    $l3Fixture = createMemberFixture($primary, $accountPrefix . '_l3', 'G1C L3 Member');
    $l3TermId = createActiveMembershipTerm($primary, $l3Fixture, 3, $runId);
    $storage = new LocalPrivateAssetStorage();
    $l2FirstAsset = createReadyAsset(
        $primary,
        $l2Fixture,
        $runId . '01',
        'l2-first.jpg',
        $storage
    );
    $l3Asset = createReadyAsset(
        $primary,
        $l3Fixture,
        $runId . '03',
        'l3-proof.jpg',
        $storage
    );

    $assetService = new MemberAssetService(new MemberAssetIdempotency(), $storage);
    $service = new GraduateVerificationService(new GraduateVerificationIdempotency(), $assetService);
    $admin = new AuthenticatedAdminContext(900001, true, []);
    $l2Auth = new AuthenticatedUserContext($l2Fixture['uid'], true, 'api');
    $l3Auth = new AuthenticatedUserContext($l3Fixture['uid'], true, 'api');

    $firstSubmission = submission('2024 CEO Class', 2024, $l2FirstAsset['object_key']);
    $submitKey = callerKey($runId, 'l2-submit-1');
    rememberIdempotency(
        $idempotencyKeys,
        $primary,
        'submitGraduateVerification',
        'crmeb_user',
        $l2Fixture['uid'],
        $submitKey
    );
    $first = $service->submit($primary, $l2Auth, $firstSubmission, $submitKey, [
        'correlation_id' => 'g1c-db-' . $runId . '-submit-1',
    ]);
    assertSame(GraduateVerificationState::PENDING, $first['status']);
    assertSame(false, $first['can_resubmit']);
    assertApplicationAsset($first, $l2FirstAsset);
    assertConsumedAsset($l2FirstAsset['id'], $first['id']);
    $firstAssetUsedAt = (int) assetColumn($l2FirstAsset['id'], 'used_time');
    $assertions += 8;

    $replay = $service->submit($primary, $l2Auth, $firstSubmission, $submitKey);
    assertSame(
        BootstrapIdempotency::canonicalJson($first),
        BootstrapIdempotency::canonicalJson($replay)
    );
    assertSame(1, verificationCount($primary, $l2Fixture['member_id']));
    $assertions += 2;

    $changedPayload = submission('2024 Changed Class', 2024, $l2FirstAsset['object_key']);
    assertReason('idempotency_conflict', function () use (
        $service,
        $primary,
        $l2Auth,
        $changedPayload,
        $submitKey
    ): void {
        $service->submit($primary, $l2Auth, $changedPayload, $submitKey);
    });
    $assertions++;

    $pendingKey = callerKey($runId, 'l2-pending-duplicate');
    rememberIdempotency(
        $idempotencyKeys,
        $primary,
        'submitGraduateVerification',
        'crmeb_user',
        $l2Fixture['uid'],
        $pendingKey
    );
    assertReason('verification_already_pending', function () use (
        $service,
        $primary,
        $l2Auth,
        $changedPayload,
        $pendingKey
    ): void {
        $service->submit($primary, $l2Auth, $changedPayload, $pendingKey);
    });
    assertSame(1, verificationCount($primary, $l2Fixture['member_id']));
    $assertions += 2;

    $pendingDetail = $service->detailForAdmin($primary, $admin, $first['id']);
    assertSame(null, $pendingDetail['previous_application_id']);
    assertSame(null, $pendingDetail['reviewer_admin_id']);
    assertSame(true, $pendingDetail['is_current']);
    assertSame('G1C L2 Member', $pendingDetail['member_name']);
    assertApplicationAsset($pendingDetail, $l2FirstAsset);
    $assertions += 6;

    assertReason('verification_application_not_found', function () use (
        $service,
        $secondary,
        $admin,
        $first
    ): void {
        $service->detailForAdmin($secondary, $admin, $first['id']);
    });
    assertReason('verification_application_not_found', function () use (
        $service,
        $wrongChannel,
        $admin,
        $first
    ): void {
        $service->detailForAdmin($wrongChannel, $admin, $first['id']);
    });
    assertSame(0, $service->listForAdmin(
        $secondary,
        $admin,
        GraduateVerificationAdminQuery::fromArray(['keyword' => $first['application_no']])
    )['total']);
    assertSame(0, $service->listForAdmin(
        $wrongChannel,
        $admin,
        GraduateVerificationAdminQuery::fromArray(['keyword' => $first['application_no']])
    )['total']);
    $assertions += 4;

    $primaryList = $service->listForAdmin(
        $primary,
        $admin,
        GraduateVerificationAdminQuery::fromArray([
            'status' => 'pending',
            'keyword' => 'G1C L2 Member',
            'page' => 1,
            'per_page' => 10,
        ])
    );
    assertSame(1, $primaryList['total']);
    assertSame($first['id'], $primaryList['items'][0]['id']);
    $assertions += 2;

    $returnKey = callerKey($runId, 'l2-return');
    rememberIdempotency(
        $idempotencyKeys,
        $primary,
        'reviewGraduateVerification',
        'crmeb_admin',
        $admin->adminId(),
        $returnKey
    );
    $returned = $service->review(
        $primary,
        $admin,
        $first['id'],
        GraduateVerificationReviewRequest::fromArray([
            'action' => 'return',
            'note' => 'Please replace the proof image',
        ]),
        $returnKey,
        ['correlation_id' => 'g1c-db-' . $runId . '-return']
    );
    assertSame(GraduateVerificationState::RETURNED, $returned['status']);
    assertSame(true, $returned['can_resubmit']);
    assertSame(false, $returned['is_current']);
    assertMemberProjection($l2Fixture['member_id'], 3, 1, 0, 0);
    $assertions += 7;

    $secondSubmission = submission(
        '2024 CEO Class Corrected',
        2024,
        $l2FirstAsset['object_key'],
        $first['id']
    );
    $resubmitKey = callerKey($runId, 'l2-submit-2');
    rememberIdempotency(
        $idempotencyKeys,
        $primary,
        'submitGraduateVerification',
        'crmeb_user',
        $l2Fixture['uid'],
        $resubmitKey
    );
    $second = $service->submit($primary, $l2Auth, $secondSubmission, $resubmitKey, [
        'correlation_id' => 'g1c-db-' . $runId . '-submit-2',
    ]);
    assertSame(GraduateVerificationState::PENDING, $second['status']);
    assertSame(2, verificationCount($primary, $l2Fixture['member_id']));
    assertSame($first['id'], (int) applicationColumn($second['id'], 'previous_application_id'));
    assertSame(3, (int) latestAuditColumn($primary, $second['id'], 'submit', 'from_status'));
    assertSame(1, (int) latestAuditColumn($primary, $second['id'], 'submit', 'to_status'));
    assertSame(null, applicationColumn($first['id'], 'current_slot'));
    assertApplicationAsset($second, $l2FirstAsset);
    assertConsumedAsset($l2FirstAsset['id'], $first['id']);
    assertSame($firstAssetUsedAt, (int) assetColumn($l2FirstAsset['id'], 'used_time'));
    $reusedProofContent = $assetService->contentForAdmin(
        $primary,
        $admin,
        $l2FirstAsset['id'],
        $second['id']
    );
    assertSame(fixtureBytes($runId . '01'), file_get_contents($reusedProofContent->path()));
    assertSame(0, auditCount($primary, $first['id'], 'read_asset'));
    assertSame(1, auditCount($primary, $second['id'], 'read_asset'));
    assertSame(1, (int) latestAuditColumn($primary, $second['id'], 'read_asset', 'from_status'));
    $assertions += 17;

    $approveKey = callerKey($runId, 'l2-approve');
    rememberIdempotency(
        $idempotencyKeys,
        $primary,
        'reviewGraduateVerification',
        'crmeb_admin',
        $admin->adminId(),
        $approveKey
    );
    $approved = $service->review(
        $primary,
        $admin,
        $second['id'],
        GraduateVerificationReviewRequest::fromArray(['action' => 'approve']),
        $approveKey
    );
    assertSame(GraduateVerificationState::APPROVED, $approved['status']);
    assertSame($first['id'], $approved['previous_application_id']);
    assertSame($admin->adminId(), $approved['reviewer_admin_id']);
    assertMemberProjection($l2Fixture['member_id'], 2, 2, $second['id'], 0);
    $l2Query = $service->query($primary, $l2Auth);
    assertSame(GraduateVerificationState::APPROVED, $l2Query['current_status']);
    assertSame(false, $l2Query['can_submit']);
    $assertions += 9;

    setMemberStatus($l2Fixture['member_id'], 2);
    assertReason('member_disabled', function () use (
        $service,
        $primary,
        $admin,
        $second,
        $approveKey
    ): void {
        $service->review(
            $primary,
            $admin,
            $second['id'],
            GraduateVerificationReviewRequest::fromArray(['action' => 'approve']),
            $approveKey
        );
    });
    assertSame(1, auditCount($primary, $second['id'], 'approve'));
    setMemberStatus($l2Fixture['member_id'], 1);
    $assertions += 2;

    $secondApproveKey = callerKey($runId, 'l2-approve-again');
    rememberIdempotency(
        $idempotencyKeys,
        $primary,
        'reviewGraduateVerification',
        'crmeb_admin',
        $admin->adminId(),
        $secondApproveKey
    );
    assertReason('verification_transition_invalid', function () use (
        $service,
        $primary,
        $admin,
        $second,
        $secondApproveKey
    ): void {
        $service->review(
            $primary,
            $admin,
            $second['id'],
            GraduateVerificationReviewRequest::fromArray(['action' => 'approve']),
            $secondApproveKey
        );
    });
    assertSame(1, auditCount($primary, $second['id'], 'approve'));
    $assertions += 2;

    Db::table('ch_member_asset')
        ->where('id', $l2FirstAsset['id'])
        ->update(['status' => 3, 'update_time' => time()]);
    $unavailableDetail = $service->detailForAdmin($primary, $admin, $second['id']);
    $unavailableAsset = $l2FirstAsset;
    $unavailableAsset['available'] = false;
    assertApplicationAsset($unavailableDetail, $unavailableAsset);
    $unavailableList = $service->listForAdmin(
        $primary,
        $admin,
        GraduateVerificationAdminQuery::fromArray(['keyword' => $second['application_no']])
    );
    assertSame(1, $unavailableList['total']);
    assertSame($second['id'], $unavailableList['items'][0]['id']);
    $assertions += 4;

    $l3Submission = submission('2023 Executive Class', 2023, $l3Asset['object_key']);
    $l3SubmitKey = callerKey($runId, 'l3-submit');
    rememberIdempotency(
        $idempotencyKeys,
        $primary,
        'submitGraduateVerification',
        'crmeb_user',
        $l3Fixture['uid'],
        $l3SubmitKey
    );
    $l3Application = $service->submit($primary, $l3Auth, $l3Submission, $l3SubmitKey);
    assertApplicationAsset($l3Application, $l3Asset);
    assertConsumedAsset($l3Asset['id'], $l3Application['id']);
    assertMemberProjection($l3Fixture['member_id'], 1, 1, $l3Application['id'], 0);
    $assertions += 10;

    $l3ApproveKey = callerKey($runId, 'l3-approve');
    rememberIdempotency(
        $idempotencyKeys,
        $primary,
        'reviewGraduateVerification',
        'crmeb_admin',
        $admin->adminId(),
        $l3ApproveKey
    );
    assertSame(true, $storage->delete($l3Asset['object_key']));
    $missingProofFailure = assertReason('proof_asset_invalid', function () use (
        $service,
        $primary,
        $admin,
        $l3Application,
        $l3ApproveKey
    ): void {
        $service->review(
            $primary,
            $admin,
            $l3Application['id'],
            GraduateVerificationReviewRequest::fromArray(['action' => 'approve', 'note' => 'verified']),
            $l3ApproveKey
        );
    });
    assertSame(409, $missingProofFailure->httpStatus());
    assertSame(1, (int) applicationColumn($l3Application['id'], 'status'));
    assertSame(1, (int) applicationColumn($l3Application['id'], 'current_slot'));
    assertMemberProjection($l3Fixture['member_id'], 1, 1, $l3Application['id'], 0);
    assertSame(0, auditCount($primary, $l3Application['id'], 'approve'));
    assertSame(0, idempotencyCount($primary, $l3ApproveKey, $admin->adminId()));
    assertSame(3, (int) assetColumn($l3Asset['id'], 'status'));
    storeFixtureObject($storage, $l3Asset['object_key'], $runId . '03');
    setAssetStatus($l3Asset['id'], 2);

    $proofPath = $storage->pathForRead($l3Asset['object_key']);
    $sizeMismatchBytes = fixtureBytes($runId . '03') . 'size-mismatch';
    assertSame(strlen($sizeMismatchBytes), file_put_contents($proofPath, $sizeMismatchBytes));
    assertReason('proof_asset_invalid', function () use (
        $service,
        $primary,
        $admin,
        $l3Application,
        $l3ApproveKey
    ): void {
        $service->review(
            $primary,
            $admin,
            $l3Application['id'],
            GraduateVerificationReviewRequest::fromArray(['action' => 'approve', 'note' => 'verified']),
            $l3ApproveKey
        );
    });
    assertSame(1, (int) applicationColumn($l3Application['id'], 'status'));
    assertMemberProjection($l3Fixture['member_id'], 1, 1, $l3Application['id'], 0);
    assertSame(0, idempotencyCount($primary, $l3ApproveKey, $admin->adminId()));
    assertSame(3, (int) assetColumn($l3Asset['id'], 'status'));
    assertSame(true, $storage->delete($l3Asset['object_key']));
    storeFixtureObject($storage, $l3Asset['object_key'], $runId . '03');
    setAssetStatus($l3Asset['id'], 2);

    $proofPath = $storage->pathForRead($l3Asset['object_key']);
    $hashMismatchBytes = str_repeat('x', $l3Asset['size']);
    assertSame(strlen($hashMismatchBytes), file_put_contents($proofPath, $hashMismatchBytes));
    assertReason('proof_asset_invalid', function () use (
        $service,
        $primary,
        $admin,
        $l3Application,
        $l3ApproveKey
    ): void {
        $service->review(
            $primary,
            $admin,
            $l3Application['id'],
            GraduateVerificationReviewRequest::fromArray(['action' => 'approve', 'note' => 'verified']),
            $l3ApproveKey
        );
    });
    assertSame(1, (int) applicationColumn($l3Application['id'], 'status'));
    assertMemberProjection($l3Fixture['member_id'], 1, 1, $l3Application['id'], 0);
    assertSame(0, auditCount($primary, $l3Application['id'], 'approve'));
    assertSame(0, idempotencyCount($primary, $l3ApproveKey, $admin->adminId()));
    assertSame(3, (int) assetColumn($l3Asset['id'], 'status'));
    assertSame(true, $storage->delete($l3Asset['object_key']));
    storeFixtureObject($storage, $l3Asset['object_key'], $runId . '03');
    setAssetStatus($l3Asset['id'], 2);
    $assertions += 33;

    setMemberStatus($l3Fixture['member_id'], 2);
    assertReason('member_disabled', function () use (
        $service,
        $primary,
        $admin,
        $l3Application,
        $l3ApproveKey
    ): void {
        $service->review(
            $primary,
            $admin,
            $l3Application['id'],
            GraduateVerificationReviewRequest::fromArray(['action' => 'approve', 'note' => 'verified']),
            $l3ApproveKey
        );
    });
    assertSame(1, (int) applicationColumn($l3Application['id'], 'status'));
    assertSame(0, auditCount($primary, $l3Application['id'], 'approve'));
    setMemberStatus($l3Fixture['member_id'], 1);
    $assertions += 3;

    $service->review(
        $primary,
        $admin,
        $l3Application['id'],
        GraduateVerificationReviewRequest::fromArray(['action' => 'approve', 'note' => 'verified']),
        $l3ApproveKey
    );
    assertMemberProjection($l3Fixture['member_id'], 2, 3, $l3Application['id'], $l3TermId);
    assertSame(1, auditCount($primary, $l3Application['id'], 'approve'));
    setMemberStatus($l3Fixture['member_id'], 2);
    assertReason('member_disabled', function () use (
        $service,
        $primary,
        $admin,
        $l3Application,
        $l3ApproveKey
    ): void {
        $service->review(
            $primary,
            $admin,
            $l3Application['id'],
            GraduateVerificationReviewRequest::fromArray(['action' => 'approve', 'note' => 'verified']),
            $l3ApproveKey
        );
    });
    assertSame(1, auditCount($primary, $l3Application['id'], 'approve'));
    $assertions += 7;

    fwrite(STDOUT, sprintf(
        "PASS graduate verification database service (%d assertions)\n",
        $assertions
    ));
    exitCode(0, $accountPrefix, $idempotencyKeys, $storage ?? null);
} catch (Throwable $exception) {
    fwrite(STDERR, sprintf(
        "FAIL graduate verification database service after %d assertions: %s\n",
        $assertions,
        $exception->getMessage()
    ));
    exitCode(1, $accountPrefix, $idempotencyKeys, $storage ?? null);
}

function assertDatabasePrerequisites(): void
{
    foreach ([
        'ch_tenant',
        'ch_channel',
        'ch_tenant_member',
        'ch_member_profile',
        'ch_member_asset',
        'ch_graduate_verification',
        'ch_audit_record',
        'ch_idempotency_record',
        'ch_membership_term',
        'eb_user',
    ] as $table) {
        if (!Db::query("SHOW TABLES LIKE '" . $table . "'")) {
            throw new RuntimeException('Missing database prerequisite table: ' . $table);
        }
    }
    $secret = getenv('CHAMBER_IDEMPOTENCY_ENCRYPTION_KEY');
    if (!is_string($secret) || strlen($secret) < 32) {
        throw new RuntimeException('CHAMBER_IDEMPOTENCY_ENCRYPTION_KEY is required for the database test');
    }
}

function tenantContext(string $slug): TenantContext
{
    $row = Db::table('ch_tenant')
        ->alias('tenant')
        ->join(['ch_channel' => 'channel'], 'channel.tenant_id = tenant.id')
        ->where('tenant.slug', $slug)
        ->where('tenant.status', 1)
        ->where('tenant.is_del', 0)
        ->where('channel.code', 'default')
        ->where('channel.status', 1)
        ->where('channel.is_del', 0)
        ->field('tenant.id AS tenant_id,tenant.slug AS tenant_slug,'
            . 'channel.id AS channel_id,channel.code AS channel_code')
        ->find();
    if (!is_array($row)) {
        throw new RuntimeException('Local test tenant is unavailable: ' . $slug);
    }

    return new TenantContext(new TenantRecord(
        (int) $row['tenant_id'],
        (string) $row['tenant_slug'],
        (int) $row['channel_id'],
        (string) $row['channel_code'],
        true
    ), 'graduate_verification_db_test');
}

function createMemberFixture(TenantContext $tenant, string $account, string $realName): array
{
    $now = time();
    $uid = (int) Db::table('eb_user')->insertGetId([
        'account' => $account,
        'nickname' => $account,
        'phone' => '',
        'add_time' => $now,
        'status' => 1,
        'user_type' => 'h5',
        'is_del' => 0,
    ]);
    $memberId = (int) Db::table('ch_tenant_member')->insertGetId([
        'tenant_id' => $tenant->tenantId(),
        'uid' => $uid,
        'first_channel_id' => $tenant->channelId(),
        'current_channel_id' => $tenant->channelId(),
        'referrer_uid' => 0,
        'invite_code' => 'VT' . strtoupper(bin2hex(random_bytes(7))),
        'attribution_locked_time' => $now,
        'tier' => 1,
        'verification_status' => 0,
        'current_verification_id' => 0,
        'current_membership_term_id' => 0,
        'membership_version' => 0,
        'primary_role_id' => 0,
        'status' => 1,
        'join_time' => $now,
        'certified_time' => 0,
        'tier_expire_time' => 0,
        'add_time' => $now,
        'update_time' => $now,
        'is_del' => 0,
    ]);
    Db::table('ch_member_profile')->insert([
        'tenant_id' => $tenant->tenantId(),
        'member_id' => $memberId,
        'uid' => $uid,
        'real_name' => $realName,
        'resources_json' => '[]',
        'needs_json' => '[]',
        'interests_json' => '[]',
        'expertise_json' => '[]',
        'privacy_json' => '{}',
        'profile_status' => 1,
        'add_time' => $now,
        'update_time' => $now,
        'is_del' => 0,
    ]);

    return ['uid' => $uid, 'member_id' => $memberId];
}

function createActiveMembershipTerm(
    TenantContext $tenant,
    array $member,
    int $tier,
    string $runId
): int {
    $now = time();

    return (int) Db::table('ch_membership_term')->insertGetId([
        'tenant_id' => $tenant->tenantId(),
        'channel_id' => $tenant->channelId(),
        'member_id' => $member['member_id'],
        'uid' => $member['uid'],
        'term_no' => substr(hash('sha256', 'term:' . $runId), 0, 32),
        'plan_id' => 0,
        'plan_code' => 'g1c_test_l' . $tier,
        'tier' => $tier,
        'order_context_id' => (int) (time() . substr((string) getmypid(), -3)),
        'order_pk' => 0,
        'order_no' => 'G1C-' . strtoupper($runId),
        'source_type' => 'grant',
        'currency' => 'CNY',
        'paid_amount' => '0.00',
        'refunded_amount' => '0.00',
        'original_start_time' => $now - 3600,
        'original_end_time' => $now + 86400,
        'effective_start_time' => $now - 3600,
        'effective_end_time' => $now + 86400,
        'state' => 1,
        'grant_event_id' => hash('sha256', 'grant:' . $runId),
        'plan_snapshot_json' => '{}',
        'benefits_snapshot_json' => '{}',
        'refund_policy_snapshot_json' => '{}',
        'version' => 1,
        'add_time' => $now,
        'update_time' => $now,
    ]);
}

function createReadyAsset(
    TenantContext $tenant,
    array $member,
    string $seed,
    string $originalName,
    LocalPrivateAssetStorage $storage
): array
{
    $now = time();
    $objectKey = sprintf(
        'member-assets/v1/t%d/%s.jpg',
        $tenant->tenantId(),
        substr(hash('sha256', 'asset:' . $seed), 0, 32)
    );
    $stored = storeFixtureObject($storage, $objectKey, $seed);
    $size = $stored['size'];
    $id = (int) Db::table('ch_member_asset')->insertGetId([
        'tenant_id' => $tenant->tenantId(),
        'channel_id' => $tenant->channelId(),
        'member_id' => $member['member_id'],
        'uid' => $member['uid'],
        'purpose' => 'graduate_verification_proof',
        'object_key' => $objectKey,
        'storage_driver' => 'local',
        'original_name' => $originalName,
        'mime_type' => 'image/jpeg',
        'byte_size' => $size,
        'sha256' => $stored['sha256'],
        'status' => 1,
        'used_business_type' => '',
        'used_business_id' => 0,
        'used_time' => 0,
        'last_access_time' => 0,
        'add_time' => $now,
        'update_time' => $now,
    ]);

    return [
        'id' => $id,
        'object_key' => $objectKey,
        'original_name' => $originalName,
        'mime_type' => 'image/jpeg',
        'size' => $size,
        'available' => true,
    ];
}

function fixtureBytes(string $seed): string
{
    return str_repeat(hash('sha256', 'fixture-bytes:' . $seed), 16);
}

function storeFixtureObject(
    LocalPrivateAssetStorage $storage,
    string $objectKey,
    string $seed
): array {
    $temporaryPath = tempnam(sys_get_temp_dir(), 'g1c-proof-');
    if (!is_string($temporaryPath)) {
        throw new RuntimeException('Graduate verification proof fixture could not be created');
    }
    try {
        $bytes = fixtureBytes($seed);
        if (file_put_contents($temporaryPath, $bytes) !== strlen($bytes)) {
            throw new RuntimeException('Graduate verification proof fixture could not be written');
        }
        $stored = $storage->store($temporaryPath, $objectKey);

        return ['size' => $stored->size(), 'sha256' => $stored->sha256()];
    } finally {
        @unlink($temporaryPath);
    }
}

function submission(string $className, int $year, string $proofKey, int $supersedesId = 0): GraduateVerificationSubmission
{
    $payload = [
        'class_name' => $className,
        'graduation_year' => $year,
        'graduation_at' => 0,
        'proof_object_keys' => [$proofKey],
    ];
    if ($supersedesId > 0) {
        $payload['supersedes_id'] = $supersedesId;
    }

    return GraduateVerificationSubmission::fromArray($payload);
}

function callerKey(string $runId, string $suffix): string
{
    return 'g1c-db-' . $runId . '-' . $suffix;
}

function rememberIdempotency(
    array &$keys,
    TenantContext $tenant,
    string $operation,
    string $principalType,
    int $principalId,
    string $callerKey
): void {
    $keys[] = BootstrapIdempotency::deriveInternalKey(
        $tenant->tenantId(),
        $operation,
        $principalType,
        $principalId,
        $callerKey
    );
}

function verificationCount(TenantContext $tenant, int $memberId): int
{
    return (int) Db::table('ch_graduate_verification')
        ->where('tenant_id', $tenant->tenantId())
        ->where('member_id', $memberId)
        ->count();
}

function applicationColumn(int $applicationId, string $column)
{
    return Db::table('ch_graduate_verification')->where('id', $applicationId)->value($column);
}

function assetColumn(int $assetId, string $column)
{
    return Db::table('ch_member_asset')->where('id', $assetId)->value($column);
}

function setMemberStatus(int $memberId, int $status): void
{
    $updated = Db::table('ch_tenant_member')
        ->where('id', $memberId)
        ->update(['status' => $status, 'update_time' => time()]);
    if ($updated !== 1) {
        throw new RuntimeException('Fixture member status was not updated');
    }
}

function setAssetStatus(int $assetId, int $status): void
{
    $updated = Db::table('ch_member_asset')
        ->where('id', $assetId)
        ->update(['status' => $status, 'update_time' => time()]);
    if ($updated !== 1) {
        throw new RuntimeException('Fixture member asset status was not updated');
    }
}

function latestAuditColumn(TenantContext $tenant, int $applicationId, string $action, string $column)
{
    return Db::table('ch_audit_record')
        ->where('tenant_id', $tenant->tenantId())
        ->where('business_type', 'graduate_verification')
        ->where('business_id', $applicationId)
        ->where('action', $action)
        ->order('id', 'desc')
        ->value($column);
}

function auditCount(TenantContext $tenant, int $applicationId, string $action): int
{
    return (int) Db::table('ch_audit_record')
        ->where('tenant_id', $tenant->tenantId())
        ->where('business_type', 'graduate_verification')
        ->where('business_id', $applicationId)
        ->where('action', $action)
        ->count();
}

function idempotencyCount(TenantContext $tenant, string $callerKey, int $adminId): int
{
    $internalKey = BootstrapIdempotency::deriveInternalKey(
        $tenant->tenantId(),
        'reviewGraduateVerification',
        'crmeb_admin',
        $adminId,
        $callerKey
    );

    return (int) Db::table('ch_idempotency_record')
        ->where('tenant_id', $tenant->tenantId())
        ->where('idempotency_key', $internalKey)
        ->count();
}

function assertApplicationAsset(array $application, array $expected): void
{
    if (!isset($application['proof_assets']) || !is_array($application['proof_assets'])) {
        throw new RuntimeException('Graduate verification response is missing proof_assets');
    }
    assertSame(1, count($application['proof_assets']));
    assertSame($expected, $application['proof_assets'][0]);
}

function assertConsumedAsset(int $assetId, int $applicationId): void
{
    $row = Db::table('ch_member_asset')->where('id', $assetId)->find();
    if (!is_array($row)) {
        throw new RuntimeException('Fixture member asset disappeared');
    }
    assertSame(2, (int) $row['status']);
    assertSame('graduate_verification', (string) $row['used_business_type']);
    assertSame($applicationId, (int) $row['used_business_id']);
    assertSame(true, (int) $row['used_time'] > 0);
}

function assertMemberProjection(
    int $memberId,
    int $verificationStatus,
    int $tier,
    int $verificationId,
    int $termId
): void {
    $row = Db::table('ch_tenant_member')->where('id', $memberId)->find();
    if (!is_array($row)) {
        throw new RuntimeException('Fixture member disappeared');
    }
    assertSame($verificationStatus, (int) $row['verification_status']);
    assertSame($tier, (int) $row['tier']);
    assertSame($verificationId, (int) $row['current_verification_id']);
    assertSame($termId, (int) $row['current_membership_term_id']);
}

function assertReason(string $reason, callable $callback): MemberTransactionException
{
    try {
        $callback();
    } catch (MemberTransactionException $exception) {
        assertSame($reason, $exception->reason());

        return $exception;
    }

    throw new RuntimeException('Expected member transaction reason: ' . $reason);
}

function assertSame($expected, $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf(
            'Expected %s, got %s',
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
}

function exitCode(
    int $code,
    string $accountPrefix,
    array $idempotencyKeys,
    ?LocalPrivateAssetStorage $storage
): void
{
    try {
        cleanupFixtures($accountPrefix, $idempotencyKeys, $storage);
    } catch (Throwable $cleanupException) {
        fwrite(STDERR, 'Fixture cleanup failed: ' . $cleanupException->getMessage() . "\n");
        $code = 1;
    }
    exit($code);
}

function cleanupFixtures(
    string $accountPrefix,
    array $idempotencyKeys,
    ?LocalPrivateAssetStorage $storage
): void
{
    $uids = array_map('intval', Db::table('eb_user')
        ->whereLike('account', $accountPrefix . '%')
        ->column('uid'));
    if ($uids !== []) {
        $applicationIds = array_map('intval', Db::table('ch_graduate_verification')
            ->whereIn('uid', $uids)
            ->column('id'));
        if ($applicationIds !== []) {
            Db::table('ch_audit_record')
                ->where('business_type', 'graduate_verification')
                ->whereIn('business_id', $applicationIds)
                ->delete();
        }
        $assetKeys = Db::table('ch_member_asset')->whereIn('uid', $uids)->column('object_key');
        if ($storage !== null) {
            foreach ($assetKeys as $objectKey) {
                if (is_string($objectKey) && $objectKey !== '') {
                    $storage->delete($objectKey);
                }
            }
        }
        Db::table('ch_member_asset')->whereIn('uid', $uids)->delete();
        Db::table('ch_graduate_verification')->whereIn('uid', $uids)->delete();
        Db::table('ch_membership_term')->whereIn('uid', $uids)->delete();
        Db::table('ch_member_profile')->whereIn('uid', $uids)->delete();
        Db::table('ch_tenant_member')->whereIn('uid', $uids)->delete();
        Db::table('eb_user')->whereIn('uid', $uids)->delete();
    }
    $idempotencyKeys = array_values(array_unique($idempotencyKeys));
    if ($idempotencyKeys !== []) {
        Db::table('ch_idempotency_record')->whereIn('idempotency_key', $idempotencyKeys)->delete();
    }
}
