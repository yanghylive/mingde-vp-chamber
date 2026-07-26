<?php

declare(strict_types=1);

use app\chamber\assets\LocalPrivateAssetStorage;
use app\chamber\assets\MemberAssetPurpose;
use app\chamber\exceptions\MemberTransactionException;
use app\chamber\identity\AuthenticatedAdminContext;
use app\chamber\identity\AuthenticatedUserContext;
use app\chamber\membership\BootstrapIdempotency;
use app\chamber\services\MemberAssetIdempotency;
use app\chamber\services\MemberAssetService;
use app\chamber\tenancy\TenantContext;
use app\chamber\tenancy\TenantRecord;
use think\App;
use think\facade\Db;
use think\file\UploadedFile;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

(new App())->initialize();

$runId = strtolower(bin2hex(random_bytes(6)));
$account = 'assetdbtest' . $runId;
$storageRoot = root_path('runtime/chamber-private-test/' . $runId);
$fixtureRoot = root_path('runtime/chamber-private-test-input/' . $runId);
$idempotencyKeys = [];
$assetKeys = [];
$uid = 0;
$memberId = 0;
$assertions = 0;

try {
    assertDatabasePrerequisites();
    $tenant = tenantContext('local-primary');
    [$uid, $memberId] = createMemberFixture($tenant, $account);
    $auth = new AuthenticatedUserContext($uid, true, 'api');
    $storage = new LocalPrivateAssetStorage($storageRoot);
    $service = new MemberAssetService(new MemberAssetIdempotency(), $storage);

    ensureDirectory($fixtureRoot);
    $firstPath = $fixtureRoot . '/first.png';
    $changedPath = $fixtureRoot . '/changed.png';
    file_put_contents($firstPath, pngFixture(1));
    file_put_contents($changedPath, pngFixture(2));

    $callerKey = 'asset-db-' . $runId . '-upload';
    $internalKey = BootstrapIdempotency::deriveInternalKey(
        $tenant->tenantId(),
        'uploadMemberAsset',
        'crmeb_user',
        $uid,
        $callerKey
    );
    $idempotencyKeys[] = $internalKey;
    $first = $service->upload(
        $tenant,
        $auth,
        uploadedFile($firstPath, 'graduation-proof.png'),
        MemberAssetPurpose::GRADUATE_VERIFICATION_PROOF,
        $callerKey
    );
    $assetKeys[] = $first['object_key'];
    assertSame(['id', 'object_key', 'original_name', 'mime_type', 'size', 'available'], array_keys($first));
    assertSame(true, $first['available']);
    assertSame('image/png', $first['mime_type']);
    assertSame('graduation-proof.png', $first['original_name']);
    assertSame(1, assetCount($tenant, $memberId));
    assertSame(1, storedFileCount($storageRoot));
    $assertions += 7;

    $replay = $service->upload(
        $tenant,
        $auth,
        uploadedFile($firstPath, 'graduation-proof.png'),
        MemberAssetPurpose::GRADUATE_VERIFICATION_PROOF,
        $callerKey
    );
    assertSame(
        BootstrapIdempotency::canonicalJson($first),
        BootstrapIdempotency::canonicalJson($replay)
    );
    assertSame(1, assetCount($tenant, $memberId));
    assertSame(1, storedFileCount($storageRoot));
    $assertions += 3;

    $idempotency = Db::table('ch_idempotency_record')
        ->where('tenant_id', $tenant->tenantId())
        ->where('idempotency_key', $internalKey)
        ->find();
    if (!is_array($idempotency)) {
        throw new RuntimeException('Member asset idempotency row is unavailable');
    }
    assertSame('succeeded', (string) $idempotency['status']);
    assertSame(201, (int) $idempotency['result_http_status']);
    assertSame(false, strpos((string) $idempotency['result_json'], $first['object_key']) !== false);
    assertSame(false, strpos((string) $idempotency['result_json'], $first['original_name']) !== false);
    assertSame(false, strpos((string) $idempotency['result_json'], hash_file('sha256', $firstPath)) !== false);
    $resultEnvelope = json_decode((string) $idempotency['result_json'], true);
    assertSame(['sealed'], array_keys($resultEnvelope));
    $assertions += 6;

    assertReason('idempotency_conflict', function () use (
        $service,
        $tenant,
        $auth,
        $changedPath,
        $callerKey
    ): void {
        $service->upload(
            $tenant,
            $auth,
            uploadedFile($changedPath, 'graduation-proof.png'),
            MemberAssetPurpose::GRADUATE_VERIFICATION_PROOF,
            $callerKey
        );
    });
    assertSame(1, assetCount($tenant, $memberId));
    assertSame(1, storedFileCount($storageRoot));
    $assertions += 3;

    $admin = new AuthenticatedAdminContext(900001, true, []);
    $applicationId = createVerificationApplication($tenant, $uid, $memberId, $first['object_key'], $runId);
    assertReason('asset_not_found', function () use (
        $service,
        $tenant,
        $admin,
        $first,
        $applicationId
    ): void {
        $service->contentForAdmin($tenant, $admin, $first['id'], $applicationId);
    });
    assertSame([$first], $service->assertOwnedProofKeys($tenant, $auth, [$first['object_key']]));
    $service->consume($tenant, $auth, [$first['object_key']], $applicationId, time());
    assertAssetUse($first['id'], $applicationId);
    assertSame([$first], $service->assertOwnedProofKeys($tenant, $auth, [$first['object_key']]));
    $service->consume($tenant, $auth, [$first['object_key']], 700002, time());
    assertAssetUse($first['id'], $applicationId);
    assertSame([$first], $service->metadataForObjectKeys(
        $tenant,
        [$first['object_key']],
        $memberId,
        $uid
    ));
    assertReason('asset_not_found', function () use ($service, $tenant, $first, $memberId, $uid): void {
        $service->metadataForObjectKeys($tenant, [$first['object_key']], $memberId + 1, $uid);
    });
    $assertions += 11;

    $ownerContent = $service->contentForOwner($tenant, $auth, $first['id']);
    assertSame('graduation-proof.png', $ownerContent->originalName());
    assertSame('image/png', $ownerContent->mimeType());
    assertSame(file_get_contents($firstPath), file_get_contents($ownerContent->path()));
    assertSame(
        $ownerContent->path(),
        $service->contentForAdmin($tenant, $admin, $first['id'], $applicationId)->path()
    );
    assertSame(1, accessAuditCount($tenant, $applicationId, $first['id'], $admin->adminId()));
    $otherProofKey = sprintf(
        'member-assets/v1/t%d/%s.png',
        $tenant->tenantId(),
        substr(hash('sha256', 'other-application:' . $runId), 0, 32)
    );
    $otherApplicationId = createVerificationApplication(
        $tenant,
        $uid,
        $memberId,
        $otherProofKey,
        $runId . '-other',
        4,
        false
    );
    assertReason('asset_not_found', function () use (
        $service,
        $tenant,
        $admin,
        $first,
        $otherApplicationId
    ): void {
        $service->contentForAdmin($tenant, $admin, $first['id'], $otherApplicationId);
    });
    assertSame(1, accessAuditCount($tenant, $applicationId, $first['id'], $admin->adminId()));
    assertSame(0, accessAuditCount($tenant, $otherApplicationId, $first['id'], $admin->adminId()));
    assertReason('permission_denied', function () use ($service, $tenant, $first, $applicationId): void {
        $service->contentForAdmin(
            $tenant,
            new AuthenticatedAdminContext(900002, false, [MemberAssetService::ADMIN_READ_PERMISSION]),
            $first['id'],
            $applicationId
        );
    });
    $assertions += 9;

    $secondary = tenantContext('local-secondary');
    assertReason('asset_not_found', function () use (
        $service,
        $secondary,
        $admin,
        $first,
        $applicationId
    ): void {
        $service->contentForAdmin($secondary, $admin, $first['id'], $applicationId);
    });
    assertReason('proof_asset_invalid', function () use ($service, $tenant, $auth, $first): void {
        $foreignKey = preg_replace('/\/t[1-9][0-9]*\//', '/t999999/', $first['object_key'], 1);
        $service->assertOwnedProofKeys($tenant, $auth, [(string) $foreignKey]);
    });
    $assertions += 2;

    createReadyQuotaFixtures(
        $tenant,
        $uid,
        $memberId,
        MemberAssetService::MAX_READY_ASSETS_PER_MEMBER,
        $runId
    );
    $quotaKey = 'asset-db-' . $runId . '-quota';
    $idempotencyKeys[] = BootstrapIdempotency::deriveInternalKey(
        $tenant->tenantId(),
        'uploadMemberAsset',
        'crmeb_user',
        $uid,
        $quotaKey
    );
    assertReason('asset_quota_exceeded', function () use (
        $service,
        $tenant,
        $auth,
        $changedPath,
        $quotaKey
    ): void {
        $service->upload(
            $tenant,
            $auth,
            uploadedFile($changedPath, 'quota-proof.png'),
            MemberAssetPurpose::GRADUATE_VERIFICATION_PROOF,
            $quotaKey
        );
    });
    assertSame(1, storedFileCount($storageRoot));
    assertSame(MemberAssetService::MAX_READY_ASSETS_PER_MEMBER + 1, assetCount($tenant, $memberId));
    $assertions += 3;

    fwrite(STDOUT, sprintf("PASS member asset database service (%d assertions)\n", $assertions));
    cleanupFixtures($uid, $memberId, $idempotencyKeys, $assetKeys, $storage, $storageRoot, $fixtureRoot);
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, sprintf(
        "FAIL member asset database service after %d assertions: %s\n",
        $assertions,
        $exception->getMessage()
    ));
    if (isset($storage) && $storage instanceof LocalPrivateAssetStorage) {
        cleanupFixtures($uid, $memberId, $idempotencyKeys, $assetKeys, $storage, $storageRoot, $fixtureRoot);
    }
    exit(1);
}

function assertDatabasePrerequisites(): void
{
    foreach (['ch_tenant', 'ch_channel', 'ch_tenant_member', 'ch_member_profile', 'ch_member_asset', 'ch_graduate_verification', 'ch_audit_record', 'ch_idempotency_record', 'eb_user'] as $table) {
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
    ), 'member_asset_db_test');
}

function createMemberFixture(TenantContext $tenant, string $account): array
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
        'invite_code' => 'AS' . strtoupper(bin2hex(random_bytes(7))),
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
        'real_name' => 'Asset Test Member',
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

    return [$uid, $memberId];
}

function uploadedFile(string $path, string $name): UploadedFile
{
    return new UploadedFile($path, $name, 'application/octet-stream', UPLOAD_ERR_OK, true);
}

function pngFixture(int $variant): string
{
    $fixtures = [
        1 => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
        2 => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8zwAAAgEBAScY42YAAAAASUVORK5CYII=',
    ];
    if (!isset($fixtures[$variant])) {
        throw new InvalidArgumentException('Unknown PNG fixture variant');
    }
    $bytes = base64_decode($fixtures[$variant], true);
    if (!is_string($bytes)) {
        throw new RuntimeException('Could not decode PNG fixture');
    }

    return $bytes;
}

function createVerificationApplication(
    TenantContext $tenant,
    int $uid,
    int $memberId,
    string $objectKey,
    string $runId,
    int $status = 1,
    bool $current = true
): int {
    $now = time();

    return (int) Db::table('ch_graduate_verification')->insertGetId([
        'tenant_id' => $tenant->tenantId(),
        'member_id' => $memberId,
        'uid' => $uid,
        'channel_id' => $tenant->channelId(),
        'apply_no' => substr(hash('sha256', 'asset-db-application:' . $runId), 0, 32),
        'previous_application_id' => 0,
        'class_name' => 'Asset DB Test Class',
        'graduation_year' => 2024,
        'graduation_time' => 0,
        'proof_json' => BootstrapIdempotency::canonicalJson([$objectKey]),
        'status' => $status,
        'current_slot' => $current ? 1 : null,
        'reviewer_admin_id' => 0,
        'review_note' => '',
        'submit_time' => $now,
        'review_time' => 0,
        'add_time' => $now,
        'update_time' => $now,
    ]);
}

function createReadyQuotaFixtures(
    TenantContext $tenant,
    int $uid,
    int $memberId,
    int $count,
    string $runId
): void {
    $now = time();
    for ($index = 0; $index < $count; $index++) {
        $seed = hash('sha256', sprintf('asset-quota:%s:%d', $runId, $index));
        Db::table('ch_member_asset')->insert([
            'tenant_id' => $tenant->tenantId(),
            'channel_id' => $tenant->channelId(),
            'member_id' => $memberId,
            'uid' => $uid,
            'purpose' => MemberAssetPurpose::GRADUATE_VERIFICATION_PROOF,
            'object_key' => sprintf('member-assets/v1/t%d/%s.png', $tenant->tenantId(), substr($seed, 0, 32)),
            'storage_driver' => 'local',
            'original_name' => sprintf('quota-%02d.png', $index),
            'mime_type' => 'image/png',
            'byte_size' => 1,
            'sha256' => $seed,
            'status' => 1,
            'used_business_type' => '',
            'used_business_id' => 0,
            'used_time' => 0,
            'last_access_time' => 0,
            'add_time' => $now,
            'update_time' => $now,
        ]);
    }
}

function accessAuditCount(
    TenantContext $tenant,
    int $applicationId,
    int $assetId,
    int $adminId
): int {
    return (int) Db::table('ch_audit_record')
        ->where('tenant_id', $tenant->tenantId())
        ->where('business_type', 'graduate_verification')
        ->where('business_id', $applicationId)
        ->where('action', 'read_asset')
        ->where('operator_type', 2)
        ->where('operator_id', $adminId)
        ->whereLike('extra_json', '%"asset_id":' . $assetId . '%')
        ->count();
}

function assetCount(TenantContext $tenant, int $memberId): int
{
    return (int) Db::table('ch_member_asset')
        ->where('tenant_id', $tenant->tenantId())
        ->where('member_id', $memberId)
        ->count();
}

function assertAssetUse(int $assetId, int $applicationId): void
{
    $row = Db::table('ch_member_asset')->where('id', $assetId)->find();
    if (!is_array($row)) {
        throw new RuntimeException('Member asset fixture disappeared');
    }
    assertSame(2, (int) $row['status']);
    assertSame('graduate_verification', (string) $row['used_business_type']);
    assertSame($applicationId, (int) $row['used_business_id']);
    assertSame(true, (int) $row['used_time'] > 0);
}

function storedFileCount(string $root): int
{
    if (!is_dir($root)) {
        return 0;
    }
    $count = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if ($file->isFile() && !$file->isLink()) {
            $count++;
        }
    }

    return $count;
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

function ensureDirectory(string $directory): void
{
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Could not create member asset database fixture directory');
    }
}

function cleanupFixtures(
    int $uid,
    int $memberId,
    array $idempotencyKeys,
    array $assetKeys,
    LocalPrivateAssetStorage $storage,
    string $storageRoot,
    string $fixtureRoot
): void {
    foreach ($assetKeys as $objectKey) {
        if (is_string($objectKey)) {
            $storage->delete($objectKey);
        }
    }
    foreach ($idempotencyKeys as $internalKey) {
        Db::table('ch_idempotency_record')->where('idempotency_key', $internalKey)->delete();
    }
    if ($memberId > 0) {
        $applicationIds = array_map('intval', Db::table('ch_graduate_verification')
            ->where('member_id', $memberId)
            ->column('id'));
        if ($applicationIds !== []) {
            Db::table('ch_audit_record')
                ->where('business_type', 'graduate_verification')
                ->whereIn('business_id', $applicationIds)
                ->delete();
        }
        Db::table('ch_member_asset')->where('member_id', $memberId)->delete();
        Db::table('ch_graduate_verification')->where('member_id', $memberId)->delete();
        Db::table('ch_member_profile')->where('member_id', $memberId)->delete();
        Db::table('ch_tenant_member')->where('id', $memberId)->delete();
    }
    if ($uid > 0) {
        Db::table('eb_user')->where('uid', $uid)->delete();
    }
    removeDirectory($storageRoot);
    removeDirectory($fixtureRoot);
}

function removeDirectory(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }
    $items = scandir($directory);
    if (!is_array($items)) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $directory . DIRECTORY_SEPARATOR . $item;
        if (is_link($path) || is_file($path)) {
            @unlink($path);
        } elseif (is_dir($path)) {
            removeDirectory($path);
        }
    }
    @rmdir($directory);
}
