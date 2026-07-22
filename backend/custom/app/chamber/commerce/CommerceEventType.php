<?php

namespace app\chamber\commerce;

use InvalidArgumentException;

final class CommerceEventType
{
    public const ORDER_COMPLETED = 'commerce.order.completed.v1';
    public const REFUND_REQUESTED = 'commerce.refund.requested.v1';
    public const REFUND_CANCELLED = 'commerce.refund.cancelled.v1';
    public const REFUND_PROCESSING = 'commerce.refund.processing.v1';
    public const REFUND_COMPLETED = 'commerce.refund.completed.v1';
    public const REFUND_FAILED = 'commerce.refund.failed.v1';

    private const REFUND_STATUS_BY_TYPE = [
        self::REFUND_REQUESTED => 'requested',
        self::REFUND_CANCELLED => 'cancelled',
        self::REFUND_PROCESSING => 'processing',
        self::REFUND_COMPLETED => 'completed',
        self::REFUND_FAILED => 'failed',
    ];

    public static function assertSupported(string $eventType): void
    {
        if ($eventType !== self::ORDER_COMPLETED && !isset(self::REFUND_STATUS_BY_TYPE[$eventType])) {
            throw new InvalidArgumentException('Unsupported commerce event type');
        }
    }

    public static function isRefund(string $eventType): bool
    {
        return isset(self::REFUND_STATUS_BY_TYPE[$eventType]);
    }

    public static function refundStatus(string $eventType): string
    {
        if (!isset(self::REFUND_STATUS_BY_TYPE[$eventType])) {
            throw new InvalidArgumentException('Commerce event is not a refund event');
        }

        return self::REFUND_STATUS_BY_TYPE[$eventType];
    }
}
