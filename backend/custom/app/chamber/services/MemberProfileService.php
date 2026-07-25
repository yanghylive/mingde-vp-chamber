<?php

declare(strict_types=1);

namespace app\chamber\services;

use app\chamber\exceptions\MemberTransactionException;
use app\chamber\identity\AuthenticatedUserContext;
use app\chamber\membership\BootstrapIdempotency;
use app\chamber\membership\EncryptedIdempotencyResult;
use app\chamber\membership\MemberContext;
use app\chamber\membership\MemberProfilePatch;
use app\chamber\membership\MemberProfileSnapshot;
use app\chamber\tenancy\TenantContext;
use InvalidArgumentException;
use RuntimeException;
use think\facade\Db;

final class MemberProfileService
{
    private const OPERATION = 'updateChamberMemberProfile';
    private const PRINCIPAL_TYPE = 'crmeb_user';
    private const IDEMPOTENCY_LEASE_SECONDS = 30;
    private const IDEMPOTENCY_RETENTION_SECONDS = 604800;

    public function getProfile(TenantContext $tenant, AuthenticatedUserContext $auth): array
    {
        return Db::transaction(function () use ($tenant, $auth): array {
            $state = $this->loadProfile($tenant, $auth, false);

            return $state['snapshot']->toArray();
        });
    }

    public function updateProfile(
        TenantContext $tenant,
        AuthenticatedUserContext $auth,
        MemberProfilePatch $patch,
        string $callerIdempotencyKey
    ): array {
        try {
            BootstrapIdempotency::assertCallerKey($callerIdempotencyKey);
        } catch (InvalidArgumentException $exception) {
            throw new MemberTransactionException(
                400,
                'request_validation_failed',
                'Idempotency-Key is invalid',
                [['field' => 'Idempotency-Key', 'code' => 'invalid_format']]
            );
        }

        $internalKey = BootstrapIdempotency::deriveInternalKey(
            $tenant->tenantId(),
            self::OPERATION,
            self::PRINCIPAL_TYPE,
            $auth->uid(),
            $callerIdempotencyKey
        );
        $requestHash = BootstrapIdempotency::requestHash(
            $tenant->channelId(),
            $patch->toCanonicalArray()
        );
        $leaseToken = $this->uuid();
        $now = time();

        return Db::transaction(function () use (
            $tenant,
            $auth,
            $patch,
            $internalKey,
            $requestHash,
            $leaseToken,
            $now
        ): array {
            $idempotency = $this->lockIdempotencyRecord(
                $tenant->tenantId(),
                $auth->uid(),
                $internalKey,
                $requestHash,
                $leaseToken,
                $now
            );
            $state = $this->loadProfile($tenant, $auth, true);
            if ($idempotency['replay_row'] !== null) {
                return $this->decodeIdempotencyResult(
                    $idempotency['replay_row'],
                    $internalKey,
                    $auth->uid()
                );
            }

            $updated = $state['snapshot']->apply($patch, $now);
            $changes = $updated->databaseChangesFrom($state['snapshot']);
            if ($changes !== []) {
                $affected = Db::table('ch_member_profile')
                    ->where('id', $updated->profileId())
                    ->where('tenant_id', $tenant->tenantId())
                    ->where('member_id', $updated->memberId())
                    ->where('uid', $auth->uid())
                    ->where('is_del', 0)
                    ->update($changes);
                if ($affected !== 1) {
                    throw new MemberTransactionException(409, 'profile_invalid', 'Member profile could not be updated');
                }
            }

            $this->completeIdempotencyRecord(
                (int) $idempotency['id'],
                $leaseToken,
                $auth->uid(),
                $updated->profileId(),
                $updated->toArray(),
                $internalKey,
                $now
            );

            return $updated->toArray();
        });
    }

    private function loadProfile(
        TenantContext $tenant,
        AuthenticatedUserContext $auth,
        bool $lock
    ): array {
        $memberQuery = Db::table('ch_tenant_member')
            ->where('tenant_id', $tenant->tenantId())
            ->where('uid', $auth->uid());
        if ($lock) {
            $memberQuery->lock(true);
        }
        $memberRow = $memberQuery->find();
        if (!is_array($memberRow)) {
            throw new MemberTransactionException(404, 'member_not_found', 'Member account was not initialized');
        }

        $memberRow = $this->normalizeIntegerFields($memberRow, [
            'id', 'tenant_id', 'uid', 'first_channel_id', 'current_channel_id', 'referrer_uid',
            'attribution_locked_time', 'tier', 'verification_status', 'current_verification_id',
            'primary_role_id', 'status', 'join_time', 'certified_time', 'tier_expire_time',
            'current_membership_term_id', 'membership_version', 'add_time', 'update_time', 'is_del',
        ]);

        try {
            $member = MemberContext::fromRow($memberRow);
        } catch (InvalidArgumentException $exception) {
            throw new RuntimeException('Stored member context is invalid', 0, $exception);
        }
        $tenant->assertTenant($member->tenantId());
        if ($member->uid() !== $auth->uid()) {
            throw new RuntimeException('Stored member identity is inconsistent');
        }
        if (!$member->isActive()) {
            throw new MemberTransactionException(403, 'member_disabled', 'Member account is not active');
        }

        $profileQuery = Db::table('ch_member_profile')
            ->where('tenant_id', $tenant->tenantId())
            ->where('member_id', $member->memberId());
        if ($lock) {
            $profileQuery->lock(true);
        }
        $profileRow = $profileQuery->find();
        if (!is_array($profileRow)) {
            throw new MemberTransactionException(409, 'profile_invalid', 'Member profile was not initialized');
        }

        $profileRow = $this->normalizeIntegerFields($profileRow, [
            'id', 'tenant_id', 'member_id', 'uid', 'graduation_year', 'profile_status',
            'add_time', 'update_time', 'is_del',
        ]);
        if ($profileRow['is_del'] !== 0) {
            throw new MemberTransactionException(409, 'profile_invalid', 'Member profile is unavailable');
        }

        try {
            $member = $member->withProfile($profileRow);
            $snapshot = MemberProfileSnapshot::fromRow($member, $profileRow);
        } catch (InvalidArgumentException $exception) {
            throw new MemberTransactionException(409, 'profile_invalid', 'Member profile is invalid');
        }
        if ($snapshot->tenantId() !== $tenant->tenantId()
            || $snapshot->uid() !== $auth->uid()
            || $snapshot->profileComplete() !== $member->profileComplete()) {
            throw new MemberTransactionException(409, 'profile_invalid', 'Member profile identity is invalid');
        }

        return ['member' => $member, 'snapshot' => $snapshot];
    }

    private function lockIdempotencyRecord(
        int $tenantId,
        int $principalId,
        string $internalKey,
        string $requestHash,
        string $leaseToken,
        int $now
    ): array {
        Db::execute(
            'INSERT INTO `ch_idempotency_record` '
            . '(`tenant_id`,`idempotency_key`,`operation`,`request_hash`,`status`,`lease_token`,'
            . '`lease_expire_time`,`attempt_count`,`result_http_status`,`result_code`,`result_hash`,'
            . '`completed_time`,`expire_time`,`add_time`,`update_time`) '
            . 'VALUES (?,?,?,?,\'processing\',?,?,1,0,\'\',\'\',0,?,?,?) '
            . 'ON DUPLICATE KEY UPDATE `id`=`id`',
            [
                $tenantId,
                $internalKey,
                self::OPERATION,
                $requestHash,
                $leaseToken,
                $now + self::IDEMPOTENCY_LEASE_SECONDS,
                $now + self::IDEMPOTENCY_RETENTION_SECONDS,
                $now,
                $now,
            ]
        );

        $row = Db::table('ch_idempotency_record')
            ->where('tenant_id', $tenantId)
            ->where('idempotency_key', $internalKey)
            ->lock(true)
            ->find();
        if (!is_array($row)) {
            throw new RuntimeException('Profile idempotency record was not persisted');
        }
        if ((string) $row['operation'] !== self::OPERATION) {
            throw new RuntimeException('Profile idempotency operation identity is inconsistent');
        }
        if (!is_string($row['request_hash']) || !hash_equals($row['request_hash'], $requestHash)) {
            throw new MemberTransactionException(
                409,
                'idempotency_conflict',
                'Idempotency-Key was already used with a different request'
            );
        }

        $status = (string) $row['status'];
        if ($status === 'succeeded') {
            return [
                'id' => (int) $row['id'],
                'replay_row' => $row,
            ];
        }
        if (!in_array($status, ['processing', 'failed', 'unknown'], true)) {
            throw new RuntimeException('Stored profile idempotency status is invalid');
        }
        if ($status === 'processing' && hash_equals((string) $row['lease_token'], $leaseToken)) {
            return ['id' => (int) $row['id'], 'replay_row' => null];
        }
        if ($status === 'processing' && (int) $row['lease_expire_time'] >= $now) {
            throw new MemberTransactionException(
                409,
                'idempotency_conflict',
                'Request with this Idempotency-Key is already processing'
            );
        }

        $affected = Db::table('ch_idempotency_record')
            ->where('id', (int) $row['id'])
            ->update([
                'status' => 'processing',
                'lease_token' => $leaseToken,
                'lease_expire_time' => $now + self::IDEMPOTENCY_LEASE_SECONDS,
                'attempt_count' => (int) $row['attempt_count'] + 1,
                'result_http_status' => 0,
                'result_code' => '',
                'result_hash' => '',
                'result_json' => null,
                'completed_time' => 0,
                'expire_time' => $now + self::IDEMPOTENCY_RETENTION_SECONDS,
                'update_time' => $now,
            ]);
        if ($affected !== 1) {
            throw new RuntimeException('Profile idempotency execution lease could not be acquired');
        }

        return ['id' => (int) $row['id'], 'replay_row' => null];
    }

    private function decodeIdempotencyResult(
        array $row,
        string $expectedInternalKey,
        int $expectedPrincipalId
    ): array {
        if ((int) $row['result_http_status'] !== 200 || (string) $row['result_code'] !== 'ok') {
            throw new RuntimeException('Stored profile idempotency result metadata is inconsistent');
        }
        if (!is_string($row['result_json']) || !is_string($row['result_hash'])) {
            throw new RuntimeException('Stored profile idempotency result is incomplete');
        }
        $decoded = json_decode($row['result_json'], true);
        if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Stored profile idempotency result is invalid JSON');
        }
        $computedHash = hash('sha256', BootstrapIdempotency::canonicalJson($decoded));
        if (!hash_equals($row['result_hash'], $computedHash)) {
            throw new RuntimeException('Stored profile idempotency result hash is invalid');
        }
        if (!isset($decoded['principal_id'])
            || !is_int($decoded['principal_id'])
            || !isset($decoded['profile_id'])
            || !is_int($decoded['profile_id'])
            || $decoded['profile_id'] <= 0
            || !isset($decoded['sealed'])
            || !is_array($decoded['sealed'])) {
            throw new RuntimeException('Stored profile idempotency result identity is invalid');
        }
        if (!hash_equals((string) $row['idempotency_key'], $expectedInternalKey)
            || $decoded['principal_id'] !== $expectedPrincipalId) {
            throw new RuntimeException('Stored profile idempotency principal is inconsistent');
        }

        return EncryptedIdempotencyResult::open(
            $decoded['sealed'],
            $this->idempotencyAssociatedData($expectedInternalKey, $expectedPrincipalId, 200)
        );
    }

    private function completeIdempotencyRecord(
        int $recordId,
        string $leaseToken,
        int $principalId,
        int $profileId,
        array $data,
        string $internalKey,
        int $now
    ): void {
        $result = [
            'principal_id' => $principalId,
            'profile_id' => $profileId,
            'sealed' => EncryptedIdempotencyResult::seal(
                $data,
                $this->idempotencyAssociatedData($internalKey, $principalId, 200)
            ),
        ];
        $resultJson = BootstrapIdempotency::canonicalJson($result);
        $affected = Db::table('ch_idempotency_record')
            ->where('id', $recordId)
            ->where('status', 'processing')
            ->where('lease_token', $leaseToken)
            ->update([
                'status' => 'succeeded',
                'lease_token' => '',
                'lease_expire_time' => 0,
                'result_http_status' => 200,
                'result_code' => 'ok',
                'result_hash' => hash('sha256', $resultJson),
                'result_json' => $resultJson,
                'completed_time' => $now,
                'expire_time' => $now + self::IDEMPOTENCY_RETENTION_SECONDS,
                'update_time' => $now,
            ]);
        if ($affected !== 1) {
            throw new RuntimeException('Profile idempotency result could not be completed');
        }
    }

    private function idempotencyAssociatedData(
        string $internalKey,
        int $principalId,
        int $httpStatus
    ): string {
        return BootstrapIdempotency::canonicalJson([
            'operation' => self::OPERATION,
            'internal_key' => $internalKey,
            'principal_type' => self::PRINCIPAL_TYPE,
            'principal_id' => $principalId,
            'http_status' => $httpStatus,
        ]);
    }

    private function normalizeIntegerFields(array $row, array $fields): array
    {
        foreach ($fields as $field) {
            if (!array_key_exists($field, $row)) {
                throw new RuntimeException(sprintf('Stored row is missing integer field %s', $field));
            }
            $value = $row[$field];
            if (is_string($value) && preg_match('/^(0|[1-9][0-9]*)$/D', $value)) {
                $integer = (int) $value;
                if ($integer < 0 || (string) $integer !== $value) {
                    throw new RuntimeException(sprintf('Stored row integer field %s is invalid', $field));
                }
                $value = $integer;
            }
            if (!is_int($value) || $value < 0) {
                throw new RuntimeException(sprintf('Stored row integer field %s is invalid', $field));
            }
            $row[$field] = $value;
        }

        return $row;
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return substr($hex, 0, 8) . '-'
            . substr($hex, 8, 4) . '-'
            . substr($hex, 12, 4) . '-'
            . substr($hex, 16, 4) . '-'
            . substr($hex, 20, 12);
    }
}
