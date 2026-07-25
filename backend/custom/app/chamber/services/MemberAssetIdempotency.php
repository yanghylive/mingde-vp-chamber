<?php

declare(strict_types=1);

namespace app\chamber\services;

use app\chamber\exceptions\MemberTransactionException;
use app\chamber\membership\BootstrapIdempotency;
use app\chamber\membership\EncryptedIdempotencyResult;
use app\chamber\tenancy\TenantContext;
use InvalidArgumentException;
use RuntimeException;
use think\facade\Db;
use Throwable;

final class MemberAssetIdempotency
{
    private const OPERATION = 'uploadMemberAsset';
    private const PRINCIPAL_TYPE = 'crmeb_user';
    private const HTTP_STATUS = 201;
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

    /**
     * The rollback callback removes any file created by execution when the database transaction fails.
     */
    public function execute(
        TenantContext $tenant,
        int $principalId,
        string $callerKey,
        array $normalizedRequest,
        callable $execution,
        callable $rollback,
        callable $authorization = null
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
        if ($principalId <= 0) {
            throw new InvalidArgumentException('Member asset idempotency principal must be positive');
        }

        $internalKey = BootstrapIdempotency::deriveInternalKey(
            $tenant->tenantId(),
            self::OPERATION,
            self::PRINCIPAL_TYPE,
            $principalId,
            $callerKey
        );
        $requestHash = BootstrapIdempotency::requestHash($tenant->channelId(), $normalizedRequest);
        $leaseToken = call_user_func($this->leaseTokenFactory);
        $now = call_user_func($this->clock);
        if (!is_string($leaseToken) || strlen($leaseToken) !== 36 || !is_int($now) || $now <= 0) {
            throw new RuntimeException('Member asset idempotency runtime is invalid');
        }

        $executionStarted = false;
        try {
            return Db::transaction(function () use (
                $tenant,
                $principalId,
                $internalKey,
                $requestHash,
                $leaseToken,
                $now,
                $execution,
                $authorization,
                &$executionStarted
            ): array {
                $record = $this->lockRecord(
                    $tenant->tenantId(),
                    $internalKey,
                    $requestHash,
                    $leaseToken,
                    $now
                );
                if ($authorization !== null) {
                    call_user_func($authorization);
                }
                if ($record['replay']) {
                    return $this->decodeResult($record['row'], $internalKey, $principalId);
                }

                $executionStarted = true;
                $data = call_user_func($execution, $now);
                if (!is_array($data)) {
                    throw new RuntimeException('Member asset idempotent execution must return an array');
                }
                $this->completeRecord(
                    (int) $record['row']['id'],
                    $leaseToken,
                    $internalKey,
                    $principalId,
                    $data,
                    $now
                );

                return $data;
            });
        } catch (Throwable $exception) {
            if ($executionStarted) {
                try {
                    call_user_func($rollback);
                } catch (Throwable $cleanupException) {
                    throw new RuntimeException(
                        'Member asset transaction failed and stored object cleanup also failed',
                        0,
                        $exception
                    );
                }
            }
            throw $exception;
        }
    }

    private function lockRecord(
        int $tenantId,
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
        if (!is_array($row) || (string) ($row['operation'] ?? '') !== self::OPERATION) {
            throw new RuntimeException('Member asset idempotency record is inconsistent');
        }
        if (!is_string($row['request_hash']) || !hash_equals($row['request_hash'], $requestHash)) {
            throw new MemberTransactionException(
                409,
                'idempotency_conflict',
                'Idempotency-Key was already used with a different file or purpose'
            );
        }

        $status = (string) $row['status'];
        if ($status === 'succeeded') {
            return ['row' => $row, 'replay' => true];
        }
        if (!in_array($status, ['processing', 'failed', 'unknown'], true)) {
            throw new RuntimeException('Member asset idempotency status is invalid');
        }
        if ($status === 'processing' && hash_equals((string) $row['lease_token'], $leaseToken)) {
            return ['row' => $row, 'replay' => false];
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
            throw new RuntimeException('Member asset idempotency lease could not be acquired');
        }

        return ['row' => $row, 'replay' => false];
    }

    private function decodeResult(array $row, string $internalKey, int $principalId): array
    {
        if ((int) $row['result_http_status'] !== self::HTTP_STATUS
            || (string) $row['result_code'] !== 'created'
            || !is_string($row['result_json']) || !is_string($row['result_hash'])) {
            throw new RuntimeException('Stored member asset idempotency result is incomplete');
        }
        $decoded = json_decode($row['result_json'], true);
        if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Stored member asset idempotency result is invalid JSON');
        }
        $computedHash = hash('sha256', BootstrapIdempotency::canonicalJson($decoded));
        if (!hash_equals($row['result_hash'], $computedHash)
            || !hash_equals((string) $row['idempotency_key'], $internalKey)
            || !isset($decoded['sealed']) || !is_array($decoded['sealed'])) {
            throw new RuntimeException('Stored member asset idempotency result identity is invalid');
        }

        return EncryptedIdempotencyResult::open(
            $decoded['sealed'],
            $this->associatedData($internalKey, $principalId)
        );
    }

    private function completeRecord(
        int $recordId,
        string $leaseToken,
        string $internalKey,
        int $principalId,
        array $data,
        int $now
    ): void {
        $result = [
            'sealed' => EncryptedIdempotencyResult::seal(
                $data,
                $this->associatedData($internalKey, $principalId)
            ),
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
                'result_http_status' => self::HTTP_STATUS,
                'result_code' => 'created',
                'result_hash' => hash('sha256', $resultJson),
                'result_json' => $resultJson,
                'completed_time' => $now,
                'expire_time' => $now + self::RETENTION_SECONDS,
                'update_time' => $now,
            ]);
        if ($updated !== 1) {
            throw new RuntimeException('Member asset idempotency result could not be completed');
        }
    }

    private function associatedData(string $internalKey, int $principalId): string
    {
        return BootstrapIdempotency::canonicalJson([
            'operation' => self::OPERATION,
            'internal_key' => $internalKey,
            'principal_type' => self::PRINCIPAL_TYPE,
            'principal_id' => $principalId,
            'http_status' => self::HTTP_STATUS,
        ]);
    }
}
