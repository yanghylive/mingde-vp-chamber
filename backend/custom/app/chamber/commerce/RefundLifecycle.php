<?php

namespace app\chamber\commerce;

use app\chamber\exceptions\CommerceEventConflictException;
use InvalidArgumentException;

/**
 * Refund facts for one CRMEB refund aggregate across one or more channel attempts.
 */
final class RefundLifecycle
{
    public const NONE = 'none';
    public const REQUESTED = 'requested';
    public const PROCESSING = 'processing';
    public const PARTIALLY_COMPLETED = 'partially_completed';
    public const COMPLETED = 'completed';
    public const CANCELLED = 'cancelled';
    public const FAILED = 'failed';

    /** @var int */
    private $tenantId;

    /** @var int */
    private $orderPk;

    /** @var int */
    private $refundPk;

    /** @var string */
    private $paidAmount;

    /** @var string */
    private $cumulativeAmount = '0.00';

    /** @var string */
    private $status = self::NONE;

    /** @var string[] */
    private $completionFingerprints = [];

    public function __construct(int $tenantId, int $orderPk, int $refundPk, string $paidAmount)
    {
        if ($tenantId <= 0 || $orderPk <= 0 || $refundPk <= 0) {
            throw new InvalidArgumentException('Refund lifecycle identifiers must be positive');
        }
        $this->tenantId = $tenantId;
        $this->orderPk = $orderPk;
        $this->refundPk = $refundPk;
        $this->paidAmount = Money::assertAmount($paidAmount, 'paid_amount');
        if (Money::toMinor($this->paidAmount) === 0) {
            throw new InvalidArgumentException('Refund lifecycle requires a positive paid_amount');
        }
    }

    public function apply(CommerceEvent $event): self
    {
        if (!CommerceEventType::isRefund($event->eventType())) {
            throw new InvalidArgumentException('Refund lifecycle accepts only refund events');
        }
        if ($event->tenantId() !== $this->tenantId) {
            throw new InvalidArgumentException('Refund event tenant does not match lifecycle tenant');
        }
        if ($event->orderPk() !== $this->orderPk || $event->refundPk() !== $this->refundPk) {
            throw new InvalidArgumentException('Refund event aggregate does not match lifecycle aggregate');
        }
        if ($event->paidAmount() !== $this->paidAmount) {
            throw new InvalidArgumentException('Refund event paid_amount does not match the order snapshot');
        }

        $next = clone $this;
        switch ($event->eventType()) {
            case CommerceEventType::REFUND_REQUESTED:
                $next->assertStatus([self::NONE, self::FAILED, self::CANCELLED, self::PARTIALLY_COMPLETED], 'request');
                $next->assertCumulativeUnchanged($event);
                $next->status = self::REQUESTED;
                break;
            case CommerceEventType::REFUND_PROCESSING:
                $next->assertStatus([self::REQUESTED, self::PROCESSING, self::FAILED, self::CANCELLED, self::PARTIALLY_COMPLETED], 'process');
                $next->assertCumulativeUnchanged($event);
                $next->status = self::PROCESSING;
                break;
            case CommerceEventType::REFUND_FAILED:
                $next->assertStatus([self::REQUESTED, self::PROCESSING, self::FAILED], 'fail');
                $next->assertCumulativeUnchanged($event);
                $next->status = self::FAILED;
                break;
            case CommerceEventType::REFUND_CANCELLED:
                $next->assertStatus([self::REQUESTED, self::PROCESSING, self::FAILED], 'cancel');
                if (Money::toMinor($next->cumulativeAmount) !== 0) {
                    throw new InvalidArgumentException('A refund with completed funds cannot be cancelled');
                }
                $next->assertCumulativeUnchanged($event);
                $next->status = self::CANCELLED;
                break;
            case CommerceEventType::REFUND_COMPLETED:
                return $next->applyCompletion($event);
        }

        return $next;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function cumulativeAmount(): string
    {
        return $this->cumulativeAmount;
    }

    private function applyCompletion(CommerceEvent $event): self
    {
        $completionId = $event->completionId();
        $completionFingerprint = $event->completionFingerprint();
        if (isset($this->completionFingerprints[$completionId])) {
            if (!hash_equals($this->completionFingerprints[$completionId], $completionFingerprint)) {
                throw CommerceEventConflictException::forCompletion(
                    $event,
                    $this->completionFingerprints[$completionId],
                    $completionFingerprint
                );
            }

            return $this;
        }

        $this->assertStatus([self::REQUESTED, self::PROCESSING, self::FAILED, self::CANCELLED, self::PARTIALLY_COMPLETED], 'complete');

        $expectedMinor = Money::toMinor($this->cumulativeAmount) + Money::toMinor($event->refundDelta());
        $incomingMinor = Money::toMinor($event->cumulativeRefundedAmount());
        if ($expectedMinor !== $incomingMinor) {
            throw new InvalidArgumentException('Refund cumulative amount must equal prior cumulative plus refund_delta');
        }
        if ($incomingMinor > Money::toMinor($this->paidAmount)) {
            throw new InvalidArgumentException('Refund cumulative amount cannot exceed paid_amount');
        }

        $this->completionFingerprints[$completionId] = $completionFingerprint;
        $this->cumulativeAmount = $event->cumulativeRefundedAmount();
        $this->status = $incomingMinor === Money::toMinor($this->paidAmount)
            ? self::COMPLETED
            : self::PARTIALLY_COMPLETED;

        return $this;
    }

    private function assertStatus(array $allowed, string $action): void
    {
        if (!in_array($this->status, $allowed, true)) {
            throw new InvalidArgumentException(sprintf('Cannot %s refund from status %s', $action, $this->status));
        }
    }

    private function assertCumulativeUnchanged(CommerceEvent $event): void
    {
        if ($event->cumulativeRefundedAmount() !== $this->cumulativeAmount) {
            throw new InvalidArgumentException('Non-completion event cannot change cumulative refunded amount');
        }
    }
}
