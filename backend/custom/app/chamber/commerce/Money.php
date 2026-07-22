<?php

namespace app\chamber\commerce;

use InvalidArgumentException;

final class Money
{
    private const PATTERN = '/^(0|[1-9][0-9]{0,13})\.[0-9]{2}$/';

    public static function assertAmount($amount, string $field): string
    {
        if (!is_string($amount) || !preg_match(self::PATTERN, $amount)) {
            throw new InvalidArgumentException(sprintf('%s must be a non-negative two-decimal string', $field));
        }

        return $amount;
    }

    public static function toMinor(string $amount): int
    {
        self::assertAmount($amount, 'amount');
        list($whole, $fraction) = explode('.', $amount, 2);

        return ((int) $whole * 100) + (int) $fraction;
    }
}
