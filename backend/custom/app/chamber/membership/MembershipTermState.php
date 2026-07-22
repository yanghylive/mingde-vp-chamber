<?php

namespace app\chamber\membership;

use InvalidArgumentException;

final class MembershipTermState
{
    public const GRANTED = 1;
    public const REVOKED = 2;
    public const FULLY_REFUNDED = 3;

    public const EFFECTIVE_SCHEDULED = 'scheduled';
    public const EFFECTIVE_ACTIVE = 'active';
    public const EFFECTIVE_EXPIRED = 'expired';
    public const EFFECTIVE_REVOKED = 'revoked';
    public const EFFECTIVE_REFUNDED = 'refunded';

    private const TRANSITIONS = [
        self::GRANTED => [self::REVOKED, self::FULLY_REFUNDED],
        self::REVOKED => [],
        self::FULLY_REFUNDED => [],
    ];

    public static function all(): array
    {
        return array_keys(self::TRANSITIONS);
    }

    public static function isValid($state): bool
    {
        return is_int($state) && array_key_exists($state, self::TRANSITIONS);
    }

    public static function assertValid($state): int
    {
        if (!self::isValid($state)) {
            throw new InvalidArgumentException('Unknown membership term state');
        }

        return $state;
    }

    public static function canTransition($from, $to): bool
    {
        self::assertValid($from);
        self::assertValid($to);

        return in_array($to, self::TRANSITIONS[$from], true);
    }

    public static function assertTransition($from, $to): int
    {
        if (!self::canTransition($from, $to)) {
            throw new InvalidArgumentException(sprintf('Membership term cannot transition from %d to %d', $from, $to));
        }

        return $to;
    }

    public static function effectiveStatus($state, int $startTime, int $endTime, int $now): string
    {
        self::assertValid($state);
        if ($startTime <= 0 || $endTime <= $startTime || $now < 0) {
            throw new InvalidArgumentException('Membership term time range is invalid');
        }
        if ($state === self::REVOKED) {
            return self::EFFECTIVE_REVOKED;
        }
        if ($state === self::FULLY_REFUNDED) {
            return self::EFFECTIVE_REFUNDED;
        }
        if ($now < $startTime) {
            return self::EFFECTIVE_SCHEDULED;
        }

        return $now < $endTime ? self::EFFECTIVE_ACTIVE : self::EFFECTIVE_EXPIRED;
    }
}
