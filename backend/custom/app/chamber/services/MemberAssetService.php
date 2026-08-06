<?php

declare(strict_types=1);

namespace app\chamber\services;

use app\chamber\assets\LocalPrivateAssetStorage;
use app\chamber\assets\MemberAssetContent;
use app\chamber\assets\MemberAssetPurpose;
use app\chamber\assets\MemberAssetRecord;
use app\chamber\assets\MemberAssetUpload;
use app\chamber\exceptions\MemberTransactionException;
use app\chamber\identity\AuthenticatedAdminContext;
use app\chamber\identity\AuthenticatedUserContext;
use app\chamber\membership\BootstrapIdempotency;
use app\chamber\membership\GraduateVerificationState;
use app\chamber\tenancy\TenantContext;
use InvalidArgumentException;
use RuntimeException;
use think\facade\Db;
use think\file\UploadedFile;

final class MemberAssetService
{
    public const ADMIN_READ_PERMISSION = 'chamber.graduate_verification.review';
    public const MAX_READY_ASSETS_PER_MEMBER = 20;
    public const MAX_READY_BYTES_PER_MEMBER = 104857600;
    public const MAX_TOTAL_ASSETS_PER_MEMBER = 200;
    public const MAX_TOTAL_BYTES_PER_MEMBER = 536870912;

    /** @var LocalPrivateAssetStorage */
    private $storage;

    /** @var MemberAssetIdempotency */
    private $idempotency;

    public function __construct(
        MemberAssetIdempotency $idempotency,
        ?LocalPrivateAssetStorage $storage = null
    )
    {
        $driver = getenv('CHAMBER_PRIVATE_STORAGE_DRIVER');
        $driver = is_string($driver) && trim($driver) !== '' ? trim($driver) : LocalPrivateAssetStorage::DRIVER;
        if ($driver !== LocalPrivateAssetStorage::DRIVER) {
            throw new RuntimeException(
                'Only local private member asset storage is implemented; configure an OSS adapter before selecting another driver'
            );
        }

        $this->storage = $storage ?: new LocalPrivateAssetStorage();
        $this->idempotency = $idempotency;
        if ($this->storage->driver() !== $driver) {
            throw new RuntimeException('Private member asset storage driver is inconsistent');
        }
    }

    public function upload(
        TenantContext $tenant,
        AuthenticatedUserContext $auth,
        UploadedFile $file,
        $purpose,
        string $callerIdempotencyKey
    ): array {
        $this->activeMember($tenant, $auth, false);
        $upload = MemberAssetUpload::fromUploadedFile($file, $purpose);
        $storedObjectKey = null;
        $metadata = $this->idempotency->execute(
            $tenant,
            $auth->uid(),
            $callerIdempotencyKey,
            $upload->toIdempotencyArray(),
            function (int $now) use (
                $tenant,
                $auth,
                $upload,
                &$storedObjectKey
            ): array {
                $member = $this->activeMember($tenant, $auth, true);
                $this->assertUploadQuota($tenant, $member['id'], $auth->uid(), $upload->size());
                $objectKey = $this->storage->generateObjectKey(
                    $tenant->tenantId(),
                    $upload->extension()
                );
                $stored = $this->storage->store($upload->temporaryPath(), $objectKey);
                $storedObjectKey = $objectKey;
                if ($stored->size() !== $upload->size()
                    || !hash_equals($upload->sha256(), $stored->sha256())) {
                    throw new MemberTransactionException(
                        422,
                        'asset_upload_invalid',
                        'uploaded file changed while it was being stored',
                        [['field' => 'file', 'code' => 'invalid_value']]
                    );
                }

                $assetId = (int) Db::table('ch_member_asset')->insertGetId([
                    'tenant_id' => $tenant->tenantId(),
                    'channel_id' => $tenant->channelId(),
                    'member_id' => $member['id'],
                    'uid' => $auth->uid(),
                    'purpose' => $upload->purpose(),
                    'object_key' => $objectKey,
                    'storage_driver' => $this->storage->driver(),
                    'original_name' => $upload->originalName(),
                    'mime_type' => $upload->mimeType(),
                    'byte_size' => $upload->size(),
                    'sha256' => $upload->sha256(),
                    'status' => MemberAssetRecord::STATUS_READY,
                    'used_business_type' => '',
                    'used_business_id' => 0,
                    'used_time' => 0,
                    'last_access_time' => 0,
                    'add_time' => $now,
                    'update_time' => $now,
                ]);
                if ($assetId <= 0) {
                    throw new RuntimeException('Private member asset metadata was not persisted');
                }

                $row = Db::table('ch_member_asset')
                    ->where('id', $assetId)
                    ->where('tenant_id', $tenant->tenantId())
                    ->where('channel_id', $tenant->channelId())
                    ->find();
                if (!is_array($row)) {
                    throw new RuntimeException('Private member asset metadata is unavailable');
                }

                return MemberAssetRecord::fromDatabaseRow($row)->publicMetadata();
            },
            function () use (&$storedObjectKey): void {
                if (is_string($storedObjectKey) && $storedObjectKey !== ''
                    && !$this->storage->delete($storedObjectKey)) {
                    throw new RuntimeException('Private member asset object could not be cleaned up');
                }
            },
            function () use ($tenant, $auth): void {
                $this->activeMember($tenant, $auth, true);
            }
        );

        return $this->validatePublicMetadata($tenant, $metadata);
    }

    /**
     * Validates that every key is a reusable proof owned by the authenticated member in this tenant/channel.
     * A consumed proof remains reusable for immutable resubmissions; its first-use binding is preserved.
     * The returned list preserves caller order and contains API-safe metadata only.
     */
    public function assertOwnedProofKeys(
        TenantContext $tenant,
        AuthenticatedUserContext $auth,
        array $objectKeys
    ): array {
        $member = $this->activeMember($tenant, $auth, false);
        $keys = $this->normalizeProofKeys($tenant, $objectKeys);
        $records = $this->ownedRecords($tenant, $auth, $member['id'], $keys, true);

        foreach ($records as $record) {
            if (!$record->isReusableProof()) {
                throw $this->invalidProofAssets();
            }
        }

        return $this->orderedMetadata($keys, $records);
    }

    /**
     * Marks proof objects as bound to an immutable graduate-verification application.
     * READY objects receive their immutable first-use binding. A previously CONSUMED proof may be
     * referenced by a resubmission and keeps its original business binding unchanged.
     */
    public function consume(
        TenantContext $tenant,
        AuthenticatedUserContext $auth,
        array $objectKeys,
        int $applicationId,
        ?int $usedAt = null
    ): void {
        if ($applicationId <= 0) {
            throw new InvalidArgumentException('Graduate verification application ID must be positive');
        }
        $keys = $this->normalizeProofKeys($tenant, $objectKeys);
        $usedAt = $usedAt === null ? time() : $usedAt;
        if ($usedAt <= 0 || $usedAt > 4294967295) {
            throw new InvalidArgumentException('Member asset use timestamp is invalid');
        }

        Db::transaction(function () use ($tenant, $auth, $keys, $applicationId, $usedAt): void {
            $member = $this->activeMember($tenant, $auth, true);
            $records = $this->ownedRecords($tenant, $auth, $member['id'], $keys, true);

            foreach ($records as $record) {
                if ($record->status() === MemberAssetRecord::STATUS_CONSUMED) {
                    continue;
                }
                if ($record->status() !== MemberAssetRecord::STATUS_READY) {
                    throw new MemberTransactionException(
                        409,
                        'proof_asset_invalid',
                        'A proof asset is not available for this application'
                    );
                }

                $updated = Db::table('ch_member_asset')
                    ->where('id', $record->id())
                    ->where('tenant_id', $tenant->tenantId())
                    ->where('channel_id', $tenant->channelId())
                    ->where('member_id', $member['id'])
                    ->where('uid', $auth->uid())
                    ->where('status', MemberAssetRecord::STATUS_READY)
                    ->update([
                        'status' => MemberAssetRecord::STATUS_CONSUMED,
                        'used_business_type' => 'graduate_verification',
                        'used_business_id' => $applicationId,
                        'used_time' => $usedAt,
                        'update_time' => $usedAt,
                    ]);
                if ($updated !== 1) {
                    throw new MemberTransactionException(
                        409,
                        'asset_already_consumed',
                        'A proof asset changed before it could be attached to the application'
                    );
                }
            }
        });
    }

    /**
     * Resolves metadata for application responses without exposing storage paths or hashes.
     */
    public function metadataForObjectKeys(
        TenantContext $tenant,
        array $objectKeys,
        int $memberId,
        int $uid,
        ?int $channelId = null
    ): array {
        if ($memberId <= 0 || $uid <= 0) {
            throw new InvalidArgumentException('Member asset metadata owner identity must be positive');
        }
        $channelId = $channelId === null ? $tenant->channelId() : $channelId;
        if ($channelId <= 0) {
            throw new InvalidArgumentException('Member asset metadata channel identity must be positive');
        }
        $keys = $this->normalizeProofKeys($tenant, $objectKeys);
        $rows = Db::table('ch_member_asset')
            ->where('tenant_id', $tenant->tenantId())
            ->where('channel_id', $channelId)
            ->where('member_id', $memberId)
            ->where('uid', $uid)
            ->where('purpose', MemberAssetPurpose::GRADUATE_VERIFICATION_PROOF)
            ->where('storage_driver', $this->storage->driver())
            ->whereIn('status', [
                MemberAssetRecord::STATUS_READY,
                MemberAssetRecord::STATUS_CONSUMED,
                MemberAssetRecord::STATUS_UNAVAILABLE,
            ])
            ->whereIn('object_key', $keys)
            ->select()
            ->toArray();
        $records = $this->recordsFromRows($rows);
        if (count($records) !== count($keys)) {
            throw new MemberTransactionException(404, 'asset_not_found', 'Private proof asset was not found');
        }

        return $this->orderedMetadata($keys, $records);
    }

    public function assertAvailableProofKeysForReview(
        TenantContext $tenant,
        array $objectKeys,
        int $memberId,
        int $uid,
        int $channelId
    ): void {
        $keys = $this->normalizeProofKeys($tenant, $objectKeys);
        $records = $this->reviewProofRecords($tenant, $keys, $memberId, $uid, $channelId, true);
        if (count($records) !== count($keys)) {
            throw $this->unavailableProofAssetsForReview();
        }
        foreach ($records as $record) {
            $this->assertStoredProofIntegrity($record);
        }
    }

    public function markUnavailableProofKeysForReview(
        TenantContext $tenant,
        array $objectKeys,
        int $memberId,
        int $uid,
        int $channelId
    ): void {
        $keys = $this->normalizeProofKeys($tenant, $objectKeys);
        $records = $this->reviewProofRecords($tenant, $keys, $memberId, $uid, $channelId, false);
        foreach ($records as $record) {
            if (!$this->storedProofIntegrityIsValid($record)) {
                $this->markUnavailable($tenant, $record);
            }
        }
    }

    public function contentForOwner(
        TenantContext $tenant,
        AuthenticatedUserContext $auth,
        int $assetId
    ): MemberAssetContent {
        $this->assertPositiveAssetId($assetId);
        $member = $this->activeMember($tenant, $auth, false);
        $row = Db::table('ch_member_asset')
            ->where('id', $assetId)
            ->where('tenant_id', $tenant->tenantId())
            ->where('channel_id', $tenant->channelId())
            ->where('member_id', $member['id'])
            ->where('uid', $auth->uid())
            ->find();

        return $this->contentFromRow($tenant, $row);
    }

    public function contentForAdmin(
        TenantContext $tenant,
        AuthenticatedAdminContext $admin,
        int $assetId,
        int $applicationId
    ): MemberAssetContent {
        $this->assertPositiveAssetId($assetId);
        $this->assertPositiveApplicationId($applicationId);
        if (!$admin->isSuperAdministrator()) {
            throw new MemberTransactionException(
                403,
                'permission_denied',
                'Only a super administrator may read private member assets'
            );
        }
        $admin->assertPermission(self::ADMIN_READ_PERMISSION);
        $application = Db::table('ch_graduate_verification')
            ->where('id', $applicationId)
            ->where('tenant_id', $tenant->tenantId())
            ->where('channel_id', $tenant->channelId())
            ->field('id,member_id,uid,status,proof_json')
            ->find();
        if (!is_array($application)) {
            throw new MemberTransactionException(404, 'asset_not_found', 'Private member asset was not found');
        }
        $memberId = $this->databaseInteger($application['member_id'] ?? null, 'verification_member_id');
        $uid = $this->databaseInteger($application['uid'] ?? null, 'verification_uid');
        $applicationStatusCode = $this->databaseInteger(
            $application['status'] ?? null,
            'verification_status'
        );
        try {
            $applicationStatus = GraduateVerificationState::toDatabase(
                GraduateVerificationState::fromDatabase($applicationStatusCode)
            );
        } catch (InvalidArgumentException $exception) {
            throw new RuntimeException('Graduate verification application status is invalid', 0, $exception);
        }
        if ($memberId <= 0 || $uid <= 0) {
            throw new RuntimeException('Graduate verification application identity is invalid');
        }
        $proofKeys = $this->proofKeysFromApplication($tenant, $application);

        $row = Db::table('ch_member_asset')
            ->alias('asset')
            ->join(
                ['ch_graduate_verification' => 'first_verification'],
                'first_verification.tenant_id = asset.tenant_id '
                . 'AND first_verification.channel_id = asset.channel_id '
                . 'AND first_verification.id = asset.used_business_id '
                . 'AND first_verification.member_id = asset.member_id '
                . 'AND first_verification.uid = asset.uid'
            )
            ->where('asset.id', $assetId)
            ->where('asset.tenant_id', $tenant->tenantId())
            ->where('asset.channel_id', $tenant->channelId())
            ->where('asset.member_id', $memberId)
            ->where('asset.uid', $uid)
            ->where('asset.purpose', MemberAssetPurpose::GRADUATE_VERIFICATION_PROOF)
            ->where('asset.storage_driver', $this->storage->driver())
            ->where('asset.status', MemberAssetRecord::STATUS_CONSUMED)
            ->where('asset.used_business_type', 'graduate_verification')
            ->where('asset.used_business_id', '>', 0)
            ->field('asset.*')
            ->find();
        if (!is_array($row) || !in_array((string) ($row['object_key'] ?? ''), $proofKeys, true)) {
            throw new MemberTransactionException(404, 'asset_not_found', 'Private member asset was not found');
        }
        $content = $this->contentFromRow($tenant, $row);
        $this->appendAdminReadAudit(
            $tenant,
            $admin,
            $row,
            $applicationId,
            $applicationStatus
        );

        return $content;
    }

    private function contentFromRow(TenantContext $tenant, $row): MemberAssetContent
    {
        if (!is_array($row)) {
            throw new MemberTransactionException(404, 'asset_not_found', 'Private member asset was not found');
        }
        try {
            $record = MemberAssetRecord::fromDatabaseRow($row);
        } catch (InvalidArgumentException $exception) {
            throw new RuntimeException('Stored private member asset metadata is invalid', 0, $exception);
        }
        if ($record->tenantId() !== $tenant->tenantId()
            || $record->channelId() !== $tenant->channelId()
            || $record->status() === MemberAssetRecord::STATUS_UNAVAILABLE) {
            throw new MemberTransactionException(404, 'asset_not_found', 'Private member asset was not found');
        }

        try {
            $path = $this->storage->pathForRead($record->objectKey());
        } catch (RuntimeException $exception) {
            $this->markUnavailable($tenant, $record);
            throw new MemberTransactionException(404, 'asset_not_found', 'Private member asset content was not found');
        }
        clearstatcache(true, $path);
        $size = filesize($path);
        $sha256 = hash_file('sha256', $path);
        if (!is_int($size) || $size !== $record->size() || !is_string($sha256)
            || !hash_equals($record->sha256(), $sha256)) {
            $this->markUnavailable($tenant, $record);
            throw new MemberTransactionException(
                409,
                'asset_integrity_failed',
                'Private member asset failed its integrity check'
            );
        }

        $now = time();
        Db::table('ch_member_asset')
            ->where('id', $record->id())
            ->where('tenant_id', $tenant->tenantId())
            ->where('channel_id', $tenant->channelId())
            ->update(['last_access_time' => $now, 'update_time' => $now]);

        return new MemberAssetContent($path, $record->originalName(), $record->mimeType());
    }

    private function activeMember(
        TenantContext $tenant,
        AuthenticatedUserContext $auth,
        bool $lock
    ): array {
        $query = Db::table('ch_tenant_member')
            ->where('tenant_id', $tenant->tenantId())
            ->where('uid', $auth->uid());
        if ($lock) {
            $query->lock(true);
        }
        $row = $query->find();
        if (!is_array($row)) {
            throw new MemberTransactionException(404, 'member_not_found', 'Member account was not initialized');
        }
        foreach (['id', 'tenant_id', 'uid', 'current_channel_id', 'status', 'is_del'] as $field) {
            $row[$field] = $this->databaseInteger($row[$field] ?? null, $field);
        }
        if ($row['tenant_id'] !== $tenant->tenantId() || $row['uid'] !== $auth->uid()
            || $row['current_channel_id'] !== $tenant->channelId()) {
            throw new MemberTransactionException(
                403,
                'tenant_scope_denied',
                'Member does not belong to this tenant channel'
            );
        }
        if ($row['is_del'] !== 0 || $row['status'] !== 1) {
            throw new MemberTransactionException(403, 'member_disabled', 'Member account is not active');
        }
        if ($row['id'] <= 0) {
            throw new RuntimeException('Stored member identity is invalid');
        }

        return $row;
    }

    private function assertUploadQuota(
        TenantContext $tenant,
        int $memberId,
        int $uid,
        int $incomingBytes
    ): void {
        $usage = Db::table('ch_member_asset')
            ->where('tenant_id', $tenant->tenantId())
            ->where('channel_id', $tenant->channelId())
            ->where('member_id', $memberId)
            ->where('uid', $uid)
            ->field(
                'COUNT(*) AS total_count,COALESCE(SUM(byte_size),0) AS total_bytes,'
                . 'COALESCE(SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END),0) AS ready_count,'
                . 'COALESCE(SUM(CASE WHEN status = 1 THEN byte_size ELSE 0 END),0) AS ready_bytes'
            )
            ->find();
        if (!is_array($usage)) {
            throw new RuntimeException('Member asset quota could not be calculated');
        }
        $totalCount = $this->databaseInteger($usage['total_count'] ?? null, 'asset_total_count');
        $totalBytes = $this->databaseInteger($usage['total_bytes'] ?? null, 'asset_total_bytes');
        $readyCount = $this->databaseInteger($usage['ready_count'] ?? null, 'asset_ready_count');
        $readyBytes = $this->databaseInteger($usage['ready_bytes'] ?? null, 'asset_ready_bytes');
        if ($totalCount >= self::MAX_TOTAL_ASSETS_PER_MEMBER
            || $readyCount >= self::MAX_READY_ASSETS_PER_MEMBER
            || $totalBytes + $incomingBytes > self::MAX_TOTAL_BYTES_PER_MEMBER
            || $readyBytes + $incomingBytes > self::MAX_READY_BYTES_PER_MEMBER) {
            throw new MemberTransactionException(
                409,
                'asset_quota_exceeded',
                'Private member asset quota has been reached',
                [['field' => 'file', 'code' => 'quota_exceeded']]
            );
        }
    }

    private function appendAdminReadAudit(
        TenantContext $tenant,
        AuthenticatedAdminContext $admin,
        array $row,
        int $applicationId,
        int $applicationStatus
    ): void {
        $inserted = Db::table('ch_audit_record')->insert([
            'tenant_id' => $tenant->tenantId(),
            'business_type' => 'graduate_verification',
            'business_id' => $applicationId,
            'action' => 'read_asset',
            'from_status' => $applicationStatus,
            'to_status' => $applicationStatus,
            'operator_type' => 2,
            'operator_id' => $admin->adminId(),
            'opinion' => '',
            'extra_json' => BootstrapIdempotency::canonicalJson([
                'asset_id' => $this->databaseInteger($row['id'] ?? null, 'asset_id'),
                'channel_id' => $tenant->channelId(),
            ]),
            'add_time' => time(),
        ]);
        if ($inserted !== 1) {
            throw new RuntimeException('Private member asset access audit was not appended');
        }
    }

    private function proofKeysFromApplication(TenantContext $tenant, array $application): array
    {
        $proofKeys = json_decode((string) ($application['proof_json'] ?? ''), true);
        if (!is_array($proofKeys) || json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Graduate verification proof snapshot is invalid');
        }
        try {
            return $this->normalizeProofKeys($tenant, $proofKeys);
        } catch (MemberTransactionException $exception) {
            throw new RuntimeException('Graduate verification proof snapshot is invalid', 0, $exception);
        }
    }

    /** @return MemberAssetRecord[] */
    private function ownedRecords(
        TenantContext $tenant,
        AuthenticatedUserContext $auth,
        int $memberId,
        array $keys,
        bool $lock
    ): array {
        $query = Db::table('ch_member_asset')
            ->where('tenant_id', $tenant->tenantId())
            ->where('channel_id', $tenant->channelId())
            ->where('member_id', $memberId)
            ->where('uid', $auth->uid())
            ->where('purpose', MemberAssetPurpose::GRADUATE_VERIFICATION_PROOF)
            ->where('storage_driver', $this->storage->driver())
            ->whereIn('object_key', $keys);
        if ($lock) {
            $query->lock(true);
        }
        $records = $this->recordsFromRows($query->select()->toArray());
        if (count($records) !== count($keys)) {
            throw $this->invalidProofAssets();
        }

        return $records;
    }

    /** @return MemberAssetRecord[] */
    private function recordsFromRows(array $rows): array
    {
        $records = [];
        try {
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    throw new InvalidArgumentException('Member asset query returned an invalid row');
                }
                $records[] = MemberAssetRecord::fromDatabaseRow($row);
            }
        } catch (InvalidArgumentException $exception) {
            throw new RuntimeException('Stored private member asset metadata is invalid', 0, $exception);
        }

        return $records;
    }

    private function orderedMetadata(array $keys, array $records): array
    {
        $byKey = [];
        foreach ($records as $record) {
            $byKey[$record->objectKey()] = $record;
        }
        $metadata = [];
        foreach ($keys as $key) {
            if (!isset($byKey[$key])) {
                throw new MemberTransactionException(404, 'asset_not_found', 'Private proof asset was not found');
            }
            $metadata[] = $byKey[$key]->publicMetadata();
        }

        return $metadata;
    }

    private function normalizeProofKeys(TenantContext $tenant, array $objectKeys): array
    {
        if (count($objectKeys) < 1 || count($objectKeys) > 10 || $objectKeys !== array_values($objectKeys)) {
            throw $this->invalidProofAssets();
        }
        $keys = [];
        foreach ($objectKeys as $key) {
            try {
                $key = LocalPrivateAssetStorage::assertObjectKey($key, $tenant->tenantId());
            } catch (InvalidArgumentException $exception) {
                throw $this->invalidProofAssets();
            }
            if (isset($keys[$key])) {
                throw $this->invalidProofAssets();
            }
            $keys[$key] = true;
        }

        return array_keys($keys);
    }

    private function invalidProofAssets(): MemberTransactionException
    {
        return new MemberTransactionException(
            422,
            'proof_asset_invalid',
            'Proof assets must be reusable private uploads owned by this member and tenant channel',
            [['field' => 'proof_object_keys', 'code' => 'invalid_value']]
        );
    }

    private function assertStoredProofIntegrity(MemberAssetRecord $record): void
    {
        if (!$this->storedProofIntegrityIsValid($record)) {
            throw $this->unavailableProofAssetsForReview();
        }
    }

    private function storedProofIntegrityIsValid(MemberAssetRecord $record): bool
    {
        try {
            $path = $this->storage->pathForRead($record->objectKey());
        } catch (RuntimeException $exception) {
            return false;
        }

        clearstatcache(true, $path);
        $size = @filesize($path);
        $sha256 = @hash_file('sha256', $path);
        return is_int($size) && $size === $record->size() && is_string($sha256)
            && hash_equals($record->sha256(), $sha256);
    }

    /** @return MemberAssetRecord[] */
    private function reviewProofRecords(
        TenantContext $tenant,
        array $keys,
        int $memberId,
        int $uid,
        int $channelId,
        bool $lock
    ): array {
        if ($memberId <= 0 || $uid <= 0 || $channelId <= 0) {
            throw new InvalidArgumentException('Graduate verification proof identity must be positive');
        }
        $query = Db::table('ch_member_asset')
            ->alias('asset')
            ->join(
                ['ch_graduate_verification' => 'verification'],
                'verification.tenant_id = asset.tenant_id '
                . 'AND verification.channel_id = asset.channel_id '
                . 'AND verification.id = asset.used_business_id '
                . 'AND verification.member_id = asset.member_id '
                . 'AND verification.uid = asset.uid'
            )
            ->where('asset.tenant_id', $tenant->tenantId())
            ->where('asset.channel_id', $channelId)
            ->where('asset.member_id', $memberId)
            ->where('asset.uid', $uid)
            ->where('asset.purpose', MemberAssetPurpose::GRADUATE_VERIFICATION_PROOF)
            ->where('asset.storage_driver', $this->storage->driver())
            ->where('asset.status', MemberAssetRecord::STATUS_CONSUMED)
            ->where('asset.used_business_type', 'graduate_verification')
            ->where('asset.used_business_id', '>', 0)
            ->whereIn('asset.object_key', $keys)
            ->field('asset.*');
        if ($lock) {
            $query->lock(true);
        }

        return $this->recordsFromRows($query->select()->toArray());
    }

    private function markUnavailable(TenantContext $tenant, MemberAssetRecord $record): void
    {
        Db::table('ch_member_asset')
            ->where('id', $record->id())
            ->where('tenant_id', $tenant->tenantId())
            ->where('channel_id', $tenant->channelId())
            ->where('status', '<>', MemberAssetRecord::STATUS_UNAVAILABLE)
            ->update([
                'status' => MemberAssetRecord::STATUS_UNAVAILABLE,
                'update_time' => time(),
            ]);
    }

    private function unavailableProofAssetsForReview(): MemberTransactionException
    {
        return new MemberTransactionException(
            409,
            'proof_asset_invalid',
            'Graduate verification cannot be approved with unavailable proof assets'
        );
    }

    private function assertPositiveAssetId(int $assetId): void
    {
        if ($assetId <= 0) {
            throw new MemberTransactionException(
                422,
                'request_validation_failed',
                'asset_id must be a positive integer',
                [['field' => 'asset_id', 'code' => 'invalid_value']]
            );
        }
    }

    private function assertPositiveApplicationId(int $applicationId): void
    {
        if ($applicationId <= 0) {
            throw new MemberTransactionException(
                422,
                'request_validation_failed',
                'application_id must be a positive integer',
                [['field' => 'application_id', 'code' => 'invalid_value']]
            );
        }
    }

    private function databaseInteger($value, string $field): int
    {
        if (is_string($value) && preg_match('/^(0|[1-9][0-9]*)$/D', $value) === 1) {
            $integer = (int) $value;
            if ((string) $integer === $value) {
                $value = $integer;
            }
        }
        if (!is_int($value) || $value < 0) {
            throw new RuntimeException(sprintf('Stored member field %s is invalid', $field));
        }

        return $value;
    }

    private function validatePublicMetadata(TenantContext $tenant, array $metadata): array
    {
        $keys = array_keys($metadata);
        sort($keys, SORT_STRING);
        if ($keys !== ['available', 'id', 'mime_type', 'object_key', 'original_name', 'size']
            || !is_int($metadata['id']) || $metadata['id'] <= 0
            || !is_int($metadata['size']) || $metadata['size'] < 1
            || $metadata['size'] > MemberAssetUpload::MAX_BYTES
            || !is_bool($metadata['available'])
            || !is_string($metadata['object_key'])
            || !is_string($metadata['original_name'])
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,175}\.(jpg|png|pdf)$/D', $metadata['original_name']) !== 1
            || !is_string($metadata['mime_type'])
            || !in_array($metadata['mime_type'], ['image/jpeg', 'image/png', 'application/pdf'], true)) {
            throw new RuntimeException('Member asset idempotency result metadata is invalid');
        }
        try {
            LocalPrivateAssetStorage::assertObjectKey($metadata['object_key'], $tenant->tenantId());
        } catch (InvalidArgumentException $exception) {
            throw new RuntimeException('Member asset idempotency result object key is invalid', 0, $exception);
        }

        return $metadata;
    }
}
