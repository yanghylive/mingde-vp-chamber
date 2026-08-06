<?php

declare(strict_types=1);

namespace app\chamber\activity;

use RuntimeException;

final class EventCheckinToken
{
    public static function issue(int $tenantId, int $eventId, int $now, int $ttl = 300, string $secret = ''): array
    {
        if ($tenantId <= 0 || $eventId <= 0 || $ttl < 30 || $ttl > 3600) {
            throw new RuntimeException('Invalid event check-in token parameters');
        }
        $secret = self::secret($secret);
        $raw = self::base64url(random_bytes(24));
        $expires = $now + $ttl;
        $signature = hash_hmac('sha256', implode("\n", [$tenantId, $eventId, $raw, $expires]), $secret);

        return [
            'token' => $raw . '.' . $expires . '.' . $signature,
            'digest' => hash('sha256', $raw . '.' . $expires . '.' . $signature),
            'valid_from' => $now - 30,
            'expires_time' => $expires,
        ];
    }

    public static function verify(string $token, int $tenantId, int $eventId, int $now, string $secret = ''): bool
    {
        $parts = explode('.', trim($token));
        if (count($parts) !== 3 || $parts[0] === '' || !preg_match('/^[0-9]+$/D', $parts[1])) {
            return false;
        }
        $expires = (int) $parts[1];
        if ($expires < $now) {
            return false;
        }
        $secret = self::secret($secret);
        $expected = hash_hmac('sha256', implode("\n", [$tenantId, $eventId, $parts[0], $expires]), $secret);

        return hash_equals($expected, $parts[2]);
    }

    public static function digest(string $token): string
    {
        return hash('sha256', trim($token));
    }

    private static function secret(string $secret): string
    {
        if ($secret === '') {
            $secret = (string) getenv('CHAMBER_EVENT_CHECKIN_SECRET');
        }
        if ($secret === '') {
            $secret = (string) getenv('CHAMBER_TENANT_SIGNING_SECRET');
        }
        if (strlen($secret) < 32) {
            throw new RuntimeException('Event check-in secret is not configured');
        }

        return $secret;
    }

    private static function base64url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
