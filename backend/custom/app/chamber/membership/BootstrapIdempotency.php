<?php

declare(strict_types=1);

namespace app\chamber\membership;

use InvalidArgumentException;

final class BootstrapIdempotency
{
    private const INTERNAL_KEY_VERSION = 'sha256-v1';
    private const REQUEST_HASH_VERSION = 'request-sha256-v1';

    public static function assertCallerKey(string $callerKey): string
    {
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$/D', $callerKey)) {
            throw new InvalidArgumentException('Idempotency-Key is invalid');
        }

        return $callerKey;
    }

    public static function deriveInternalKey(
        int $tenantId,
        string $operation,
        string $principalType,
        int $principalId,
        string $callerKey
    ): string {
        self::assertPositiveInteger($tenantId, 'tenantId');
        self::assertIdentifier($operation, 'operation', 64);
        self::assertIdentifier($principalType, 'principalType', 32);
        self::assertPositiveInteger($principalId, 'principalId');
        self::assertCallerKey($callerKey);

        $digest = hash('sha256', self::canonicalJson([
            'caller_key' => $callerKey,
            'operation' => $operation,
            'principal' => [
                'id' => $principalId,
                'type' => $principalType,
            ],
            'scheme' => self::INTERNAL_KEY_VERSION,
            'tenant_id' => $tenantId,
        ]));

        return self::INTERNAL_KEY_VERSION . ':' . $digest;
    }

    public static function requestHash(int $trustedChannelId, array $normalizedRequest): string
    {
        self::assertPositiveInteger($trustedChannelId, 'trustedChannelId');

        return hash('sha256', self::canonicalJson([
            'request' => $normalizedRequest,
            'scheme' => self::REQUEST_HASH_VERSION,
            'trusted_channel_id' => $trustedChannelId,
        ]));
    }

    /**
     * @param mixed $value
     */
    public static function canonicalJson($value): string
    {
        $normalized = self::normalize($value);

        $json = json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json) || json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidArgumentException('Value cannot be encoded as canonical JSON');
        }

        return $json;
    }

    private static function assertPositiveInteger(int $value, string $field): void
    {
        if ($value <= 0) {
            throw new InvalidArgumentException(sprintf('%s must be a positive integer', $field));
        }
    }

    private static function assertIdentifier(string $value, string $field, int $maxLength): void
    {
        if (strlen($value) < 1
            || strlen($value) > $maxLength
            || !preg_match('/^[A-Za-z][A-Za-z0-9._:-]*$/D', $value)) {
            throw new InvalidArgumentException(sprintf('%s is invalid', $field));
        }
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private static function normalize($value)
    {
        if ($value === null || is_bool($value) || is_int($value) || is_string($value)) {
            return $value;
        }
        if (!is_array($value)) {
            throw new InvalidArgumentException('Canonical JSON accepts only null, booleans, integers, strings, and arrays');
        }

        if (self::isList($value)) {
            $normalized = [];
            foreach ($value as $item) {
                $normalized[] = self::normalize($item);
            }

            return $normalized;
        }

        foreach (array_keys($value) as $key) {
            if (!is_string($key)) {
                throw new InvalidArgumentException('Canonical JSON object keys must be strings');
            }
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = self::normalize($item);
        }

        return $value;
    }

    private static function isList(array $value): bool
    {
        $expected = 0;
        foreach ($value as $key => $unused) {
            if ($key !== $expected) {
                return false;
            }
            $expected++;
        }

        return true;
    }
}
