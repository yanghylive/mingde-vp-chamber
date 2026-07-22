<?php

namespace app\chamber\membership;

use InvalidArgumentException;

/**
 * A member tier is a projection of verification and active term evidence.
 * These helpers must never be used as purchase authorization by themselves.
 */
final class MemberTier
{
    public const L1 = 'L1';
    public const L2 = 'L2';
    public const L3 = 'L3';
    public const L4 = 'L4';

    private const RANKS = [
        self::L1 => 1,
        self::L2 => 2,
        self::L3 => 3,
        self::L4 => 4,
    ];

    public static function all(): array
    {
        return array_keys(self::RANKS);
    }

    public static function isValid($tier): bool
    {
        return is_string($tier) && isset(self::RANKS[$tier]);
    }

    public static function assertValid($tier): string
    {
        if (!self::isValid($tier)) {
            throw new InvalidArgumentException('Unknown member tier');
        }

        return $tier;
    }

    public static function rank($tier): int
    {
        return self::RANKS[self::assertValid($tier)];
    }

    public static function fromDatabaseRank($rank): string
    {
        if (!is_int($rank)) {
            throw new InvalidArgumentException('Member tier database rank must be an integer');
        }
        $tier = array_search($rank, self::RANKS, true);
        if ($tier === false) {
            throw new InvalidArgumentException('Unknown member tier database rank');
        }

        return $tier;
    }

    public static function isAtLeast($actual, $required): bool
    {
        return self::rank($actual) >= self::rank($required);
    }

    public static function project($graduateApproved, array $activePaidTiers): string
    {
        if (!is_bool($graduateApproved)) {
            throw new InvalidArgumentException('Graduate approval evidence must be boolean');
        }
        if (!$graduateApproved) {
            return self::L1;
        }

        $projected = self::L2;
        foreach ($activePaidTiers as $tier) {
            self::assertValid($tier);
            if (!in_array($tier, [self::L3, self::L4], true)) {
                throw new InvalidArgumentException('Paid membership term must project L3 or L4');
            }
            if (self::rank($tier) > self::rank($projected)) {
                $projected = $tier;
            }
        }

        return $projected;
    }

    public static function canProjectionChange($from, $to): bool
    {
        self::assertValid($from);
        self::assertValid($to);

        return $from !== $to;
    }

    public static function assertProjectionChange($from, $to): string
    {
        if (!self::canProjectionChange($from, $to)) {
            throw new InvalidArgumentException(sprintf('Member tier projection did not change from %s', $from));
        }

        return $to;
    }
}
