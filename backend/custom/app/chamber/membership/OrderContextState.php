<?php

namespace app\chamber\membership;

use app\chamber\commerce\Money;
use InvalidArgumentException;

/**
 * Order-wide payment and cumulative refund projection persisted by ch_order_context.
 */
final class OrderContextState
{
    public const PAY_PENDING = 0;
    public const PAY_COMPLETED = 1;
    public const PAY_CANCELLED = 2;
    public const PAY_CLOSED = 3;

    public const REFUND_NONE = 0;
    public const REFUND_REQUESTED = 1;
    public const REFUND_PROCESSING = 2;
    public const REFUND_PARTIALLY_COMPLETED = 3;
    public const REFUND_COMPLETED = 4;
    public const REFUND_CANCELLED = 5;
    public const REFUND_FAILED = 6;

    public const COMPLETION_PENDING = 'pending';
    public const COMPLETION_PAID = 'paid';
    public const COMPLETION_ZERO_AMOUNT = 'zero_amount';

    private const PAY_TRANSITIONS = [
        self::PAY_PENDING => [self::PAY_COMPLETED, self::PAY_CANCELLED, self::PAY_CLOSED],
        self::PAY_COMPLETED => [],
        self::PAY_CANCELLED => [],
        self::PAY_CLOSED => [],
    ];

    private const REFUND_TRANSITIONS = [
        self::REFUND_NONE => [self::REFUND_REQUESTED],
        self::REFUND_REQUESTED => [
            self::REFUND_PROCESSING,
            self::REFUND_PARTIALLY_COMPLETED,
            self::REFUND_CANCELLED,
            self::REFUND_FAILED,
            self::REFUND_COMPLETED,
        ],
        self::REFUND_PROCESSING => [
            self::REFUND_PARTIALLY_COMPLETED,
            self::REFUND_COMPLETED,
            self::REFUND_CANCELLED,
            self::REFUND_FAILED,
        ],
        self::REFUND_PARTIALLY_COMPLETED => [
            self::REFUND_REQUESTED,
            self::REFUND_PROCESSING,
            self::REFUND_COMPLETED,
            self::REFUND_FAILED,
        ],
        self::REFUND_COMPLETED => [],
        self::REFUND_CANCELLED => [
            self::REFUND_REQUESTED,
            self::REFUND_PROCESSING,
            self::REFUND_PARTIALLY_COMPLETED,
            self::REFUND_COMPLETED,
        ],
        self::REFUND_FAILED => [
            self::REFUND_REQUESTED,
            self::REFUND_PROCESSING,
            self::REFUND_PARTIALLY_COMPLETED,
            self::REFUND_COMPLETED,
        ],
    ];

    public static function allPayStatuses(): array
    {
        return array_keys(self::PAY_TRANSITIONS);
    }

    public static function allRefundStatuses(): array
    {
        return array_keys(self::REFUND_TRANSITIONS);
    }

    public static function allCompletionKinds(): array
    {
        return [self::COMPLETION_PENDING, self::COMPLETION_PAID, self::COMPLETION_ZERO_AMOUNT];
    }

    public static function assertPayStatus($status): int
    {
        if (!is_int($status) || !array_key_exists($status, self::PAY_TRANSITIONS)) {
            throw new InvalidArgumentException('Unknown order-context pay status');
        }

        return $status;
    }

    public static function assertRefundStatus($status): int
    {
        if (!is_int($status) || !array_key_exists($status, self::REFUND_TRANSITIONS)) {
            throw new InvalidArgumentException('Unknown order-context refund status');
        }

        return $status;
    }

    public static function assertCompletionKind($kind): string
    {
        if (!is_string($kind) || !in_array($kind, self::allCompletionKinds(), true)) {
            throw new InvalidArgumentException('Unknown order-context completion kind');
        }

        return $kind;
    }

    public static function canPayTransition($from, $to): bool
    {
        self::assertPayStatus($from);
        self::assertPayStatus($to);

        return in_array($to, self::PAY_TRANSITIONS[$from], true);
    }

    public static function assertPayTransition($from, $to): int
    {
        if (!self::canPayTransition($from, $to)) {
            throw new InvalidArgumentException(sprintf('Pay status cannot transition from %d to %d', $from, $to));
        }

        return $to;
    }

    public static function canRefundTransition($from, $to): bool
    {
        self::assertRefundStatus($from);
        self::assertRefundStatus($to);

        return in_array($to, self::REFUND_TRANSITIONS[$from], true);
    }

    public static function assertRefundTransition($from, $to): int
    {
        if (!self::canRefundTransition($from, $to)) {
            throw new InvalidArgumentException(sprintf('Refund status cannot transition from %d to %d', $from, $to));
        }

        return $to;
    }

    public static function assertPaymentSnapshot($payStatus, $completionKind): void
    {
        self::assertPayStatus($payStatus);
        self::assertCompletionKind($completionKind);
        $valid = $payStatus === self::PAY_COMPLETED
            ? in_array($completionKind, [self::COMPLETION_PAID, self::COMPLETION_ZERO_AMOUNT], true)
            : $completionKind === self::COMPLETION_PENDING;

        if (!$valid) {
            throw new InvalidArgumentException('Pay status and completion kind are inconsistent');
        }
    }

    public static function assertSnapshot(
        $payStatus,
        $completionKind,
        $refundStatus,
        $paidAmount,
        $refundedAmount
    ): void
    {
        self::assertPaymentSnapshot($payStatus, $completionKind);
        self::assertRefundStatus($refundStatus);
        $paidMinor = Money::toMinor(Money::assertAmount($paidAmount, 'paid_amount'));
        $refundedMinor = Money::toMinor(Money::assertAmount($refundedAmount, 'refunded_amount'));

        if ($refundedMinor > $paidMinor) {
            throw new InvalidArgumentException('refunded_amount cannot exceed paid_amount');
        }
        if ($payStatus !== self::PAY_COMPLETED) {
            if ($paidMinor !== 0 || $refundedMinor !== 0 || $refundStatus !== self::REFUND_NONE) {
                throw new InvalidArgumentException('Unpaid order context cannot carry payment or refund facts');
            }
            return;
        }
        if ($completionKind === self::COMPLETION_ZERO_AMOUNT) {
            if ($paidMinor !== 0 || $refundedMinor !== 0 || $refundStatus !== self::REFUND_NONE) {
                throw new InvalidArgumentException('Zero-amount completion cannot carry refund facts');
            }
            return;
        }
        if ($paidMinor === 0) {
            throw new InvalidArgumentException('Paid completion requires a positive paid_amount');
        }
        if ($refundStatus === self::REFUND_NONE && $refundedMinor !== 0) {
            throw new InvalidArgumentException('Refund amount requires a refund lifecycle');
        }
        if ($refundStatus === self::REFUND_PARTIALLY_COMPLETED
            && ($refundedMinor === 0 || $refundedMinor >= $paidMinor)) {
            throw new InvalidArgumentException('Partial refund must be positive and less than paid_amount');
        }
        if ($refundStatus === self::REFUND_COMPLETED && $refundedMinor !== $paidMinor) {
            throw new InvalidArgumentException('Completed refund must equal paid_amount');
        }
        if ($refundedMinor > 0
            && $refundedMinor === $paidMinor
            && $refundStatus !== self::REFUND_COMPLETED) {
            throw new InvalidArgumentException('Full refunded amount requires completed refund status');
        }
    }
}
