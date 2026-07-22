<?php

namespace app\chamber\membership;

use InvalidArgumentException;

final class GraduateVerificationState
{
    public const DRAFT = 'draft';
    public const PENDING = 'pending';
    public const APPROVED = 'approved';
    public const RETURNED = 'returned';
    public const REJECTED = 'rejected';
    public const REVOKED = 'revoked';

    private const TRANSITIONS = [
        self::DRAFT => [self::PENDING],
        self::PENDING => [self::APPROVED, self::RETURNED, self::REJECTED],
        self::APPROVED => [self::REVOKED],
        self::RETURNED => [],
        self::REJECTED => [],
        self::REVOKED => [],
    ];

    private const DATABASE_CODES = [
        self::DRAFT => 0,
        self::PENDING => 1,
        self::APPROVED => 2,
        self::RETURNED => 3,
        self::REJECTED => 4,
        self::REVOKED => 5,
    ];

    public static function all(): array
    {
        return array_keys(self::TRANSITIONS);
    }

    public static function isValid($state): bool
    {
        return is_string($state) && array_key_exists($state, self::TRANSITIONS);
    }

    public static function assertValid($state): string
    {
        if (!self::isValid($state)) {
            throw new InvalidArgumentException('Unknown graduate verification state');
        }

        return $state;
    }

    public static function toDatabase($state): int
    {
        return self::DATABASE_CODES[self::assertValid($state)];
    }

    public static function fromDatabase($code): string
    {
        if (!is_int($code)) {
            throw new InvalidArgumentException('Graduate verification database code must be an integer');
        }
        $state = array_search($code, self::DATABASE_CODES, true);
        if ($state === false) {
            throw new InvalidArgumentException('Unknown graduate verification database code');
        }

        return $state;
    }

    public static function canTransition($from, $to): bool
    {
        self::assertValid($from);
        self::assertValid($to);

        return in_array($to, self::TRANSITIONS[$from], true);
    }

    public static function assertTransition($from, $to): string
    {
        if (!self::canTransition($from, $to)) {
            throw new InvalidArgumentException(sprintf(
                'Graduate verification cannot transition from %s to %s',
                $from,
                $to
            ));
        }

        return $to;
    }

    public static function isTerminal($state): bool
    {
        self::assertValid($state);

        return self::TRANSITIONS[$state] === [];
    }
}
