<?php

declare(strict_types=1);

namespace app\chamber\services;

use app\chamber\exceptions\MemberTransactionException;
use app\chamber\membership\BootstrapIdempotency;
use app\chamber\tenancy\TenantContext;
use InvalidArgumentException;
use RuntimeException;
use think\facade\Db;

final class GraduateVerificationIdempotency
{
    private const LEASE_SECONDS = 30;
    private const RETENTION_SECONDS = 604800;

    /** @var callable */
    private $clock;

    /** @var callable */
    private $leaseTokenFactory;

    public function __construct(callable $clock = null, callable $leaseTokenFactory = null)
    {
        $this->clock = $clock ?: function (): int {
            return time();
        };
        $this->leaseTokenFactory = $leaseTokenFactory ?: function (): string {
            $bytes = random_bytes(16);
            $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
            $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
            $hex = bin2hex($bytes);

            return substr($hex, 0, 8) . '-'
                . substr($hex, 8, 4) . '-'
                . substr($hex, 12, 4) . '-'
                . substr($hex, 16, 4) . '-'
                . substr($hex, 20, 12);
        };
    }

    public function execute(
        TenantContext $tenant,
        string $operation,
        string $principalType,
        int $principalId,
        string $callerKey,
        array $normalizedRequest,
        int $successHttpStatus,
        callable $execution
    ): array {
        try {
            BootstrapIdempotency::assertCallerKey($callerKey);
        } catch (InvalidArgumentException $exception) {
            throw new MemberTransactionException(
                400,
                'request_validation_failed',
                'Idempotency-Key is invalid',
                [['field' => 'Idempotency-Key', 'code' => 'invalid_format']]
            );
        }
        if ($successHttpStatus < 200 || $successHttpStatus > 299) {
            throw new InvalidArgumentException('Idempotency success HTTP status must be a 2xx status');
        }

        $internalKey = BootstrapIdempotency::deriveInternalKey(
            $tenant->tenantId(),
            $operation,
            $principalType,
            $principalId,
            $callerKey
        );
        $requestHash = BootstrapIdempotency::requestHash($tenant->channelId(), $normalizedRequest);
        $leaseToken = call_user_func($this->leaseTokenFactory);
        $now = call_user_func($this->clock);
        if (!is_string($leaseToken) || strlen($leaseToken) !== 36 || !is_int($now) || $now <= 0) {
            throw new RuntimeException('Graduate verification idempotency runtime is invalid');
        }

        return Db::transaction(function () use (
            $tenant,
            $operation,
            $principalType,
            $principalId,
            $internalKey,
            $requestHash,
            $leaseToken,
            $now,
            $successHttpStatus,
            $execution
        ): array {
            $record = $this->lockRecord(
                $tenant->tenantId(),
                $operation,
                $internalKey,
                $requestHash,
                $leaseToken,
                $now
            );
            if ($record['replay'] !== null) {
                return $this->decodeResult(
                    $record['row'],
                    $internalKey,
                    $principalType,
                    $principalId,
                    $successHttpStatus
                );
            }

            $data = call_user_func($execution, $now);
            if (!is_array($data)) {
                throw new RuntimeException('Graduate verification idempotent execution must return an array');
            }
            $this->completeRecord(
                (int) $record['row']['id'],
                $leaseToken,
                $principalType,
                $principalId,
                $successHttpStatus,
                $data,
                $now
            );

            return $data;
        });
    }

    private function lockRecord(
        int $tenantId,
        string $operation,
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
                $operation,
                $requestHash,
                $leaseToken,
                $now + self::LEASE_SECONDS,
                $now + self::RETENTION_SECONDS,
                $now,
                $now,
            ]
        );

        $row = Db::table('ch_idempotency_record')
            ->where('tenant_id', $tenantId)
            ->where('idempotency_key', $internalKey)
            ->lock(true)
            ->find();
        if (!is_array($row) || (string) ($row['operation'] ?? '') !== $operation) {
            throw new RuntimeException('Graduate verification idempotency record is inconsistent');
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
            return ['row' => $row, 'replay' => true];
        }
        if (!in_array($status, ['processing', 'failed', 'unknown'], true)) {
            throw new RuntimeException('Graduate verification idempotency status is invalid');
        }
        if ($status === 'processing' && hash_equals((string) $row['lease_token'], $leaseToken)) {
            return ['row' => $row, 'replay' => null];
        }
        if ($status === 'processing' && (int) $row['lease_expire_time'] >= $now) {
            throw new MemberTransactionException(
                409,
                'idempotency_conflict',
                'Request with this Idempotency-Key is already processing'
            );
        }

        $updated = Db::table('ch_idempotency_record')
            ->where('id', (int) $row['id'])
            ->update([
                'status' => 'processing',
                'lease_token' => $leaseToken,
                'lease_expire_time' => $now + self::LEASE_SECONDS,
                'attempt_count' => (int) $row['attempt_count'] + 1,
                'result_http_status' => 0,
                'result_code' => '',
                'result_hash' => '',
                'result_json' => null,
                'completed_time' => 0,
                'expire_time' => $now + self::RETENTION_SECONDS,
                'update_time' => $now,
            ]);
        if ($updated !== 1) {
            throw new RuntimeException('Graduate verification idempotency lease could not be acquired');
        }

        $row['status'] = 'processing';
        $row['lease_token'] = $leaseToken;

        return ['row' => $row, 'replay' => null];
    }

    private function decodeResult(
        array $row,
        string $expectedInternalKey,
        string $expectedPrincipalType,
        int $expectedPrincipalId,
        int $expectedHttpStatus
    ): array {
        if ((int) $row['result_http_status'] !== $expectedHttpStatus || (string) $row['result_code'] !== 'ok'
            || !is_string($row['result_json']) || !is_string($row['result_hash'])) {
            throw new RuntimeException('Stored graduate verification idempotency result is incomplete');
        }
        $decoded = json_decode($row['result_json'], true);
        if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Stored graduate verification idempotency result is invalid JSON');
        }
        $computedHash = hash('sha256', BootstrapIdempotency::canonicalJson($decoded));
        if (!hash_equals($row['result_hash'], $computedHash)
            || !hash_equals((string) $row['idempotency_key'], $expectedInternalKey)
            || ($decoded['principal_type'] ?? null) !== $expectedPrincipalType
            || ($decoded['principal_id'] ?? null) !== $expectedPrincipalId
            || !isset($decoded['data']) || !is_array($decoded['data'])) {
            throw new RuntimeException('Stored graduate verification idempotency result identity is invalid');
        }

        return $decoded['data'];
    }

    private function completeRecord(
        int $recordId,
        string $leaseToken,
        string $principalType,
        int $principalId,
        int $httpStatus,
        array $data,
        int $now
    ): void {
        $result = [
            'principal_type' => $principalType,
            'principal_id' => $principalId,
            'data' => $data,
        ];
        $resultJson = BootstrapIdempotency::canonicalJson($result);
        $updated = Db::table('ch_idempotency_record')
            ->where('id', $recordId)
            ->where('status', 'processing')
            ->where('lease_token', $leaseToken)
            ->update([
                'status' => 'succeeded',
                'lease_token' => '',
                'lease_expire_time' => 0,
                'result_http_status' => $httpStatus,
                'result_code' => 'ok',
                'result_hash' => hash('sha256', $resultJson),
                'result_json' => $resultJson,
                'completed_time' => $now,
                'expire_time' => $now + self::RETENTION_SECONDS,
                'update_time' => $now,
            ]);
        if ($updated !== 1) {
            throw new RuntimeException('Graduate verification idempotency result could not be completed');
        }
    }
}
