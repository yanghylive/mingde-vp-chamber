<?php

declare(strict_types=1);

namespace app\chamber\commerce;

use InvalidArgumentException;

/** State rules for one immutable provider refund number. */
final class RefundAttemptState
{
    public const REQUESTED = 'requested';
    public const PROCESSING = 'processing';
    public const UNKNOWN = 'unknown';
    public const SUCCEEDED = 'succeeded';
    public const FAILED = 'failed';
    public const MANUAL = 'manual';

    public const SOURCE_BALANCE = 'balance_transaction';
    public const SOURCE_PROVIDER_QUERY = 'provider_query_success';
    public const SOURCE_MANUAL = 'manual_finance_confirm';

    private const TRANSITIONS = [
        self::REQUESTED => [self::PROCESSING, self::UNKNOWN, self::SUCCEEDED, self::FAILED, self::MANUAL],
        self::PROCESSING => [self::PROCESSING, self::UNKNOWN, self::SUCCEEDED, self::FAILED, self::MANUAL],
        self::UNKNOWN => [self::PROCESSING, self::UNKNOWN, self::SUCCEEDED, self::FAILED, self::MANUAL],
        self::SUCCEEDED => [self::SUCCEEDED],
        self::FAILED => [self::FAILED],
        self::MANUAL => [self::MANUAL],
    ];

    private const FINAL_SOURCE_BY_STATUS = [
        self::SUCCEEDED => [self::SOURCE_BALANCE, self::SOURCE_PROVIDER_QUERY],
        self::MANUAL => [self::SOURCE_MANUAL],
    ];

    public static function assertStatus(string $status): string
    {
        if (!isset(self::TRANSITIONS[$status])) {
            throw new InvalidArgumentException('Unsupported refund attempt status');
        }

        return $status;
    }

    public static function assertTransition(string $from, string $to): string
    {
        self::assertStatus($from);
        self::assertStatus($to);
        if (!in_array($to, self::TRANSITIONS[$from], true)) {
            throw new InvalidArgumentException(sprintf(
                'Refund attempt cannot transition from %s to %s',
                $from,
                $to
            ));
        }

        return $to;
    }

    public static function isFinal(string $status): bool
    {
        self::assertStatus($status);

        return isset(self::FINAL_SOURCE_BY_STATUS[$status]) || $status === self::FAILED;
    }

    public static function assertFinalConfirmation(string $status, string $source): void
    {
        self::assertStatus($status);
        if (!isset(self::FINAL_SOURCE_BY_STATUS[$status])
            || !in_array($source, self::FINAL_SOURCE_BY_STATUS[$status], true)) {
            throw new InvalidArgumentException('Refund attempt does not have a trusted final confirmation');
        }
    }

    public static function shouldQuery(string $status): bool
    {
        self::assertStatus($status);

        return in_array($status, [self::PROCESSING, self::UNKNOWN], true);
    }
}
