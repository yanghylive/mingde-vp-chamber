<?php

declare(strict_types=1);

namespace app\chamber\activity;

use app\chamber\commerce\Money;
use InvalidArgumentException;

/** Immutable cash-side snapshot used to create one CRMEB event order. */
final class EventTicketOrderSnapshot
{
    /** @var array */
    private $values;

    private function __construct(array $values)
    {
        $this->values = $values;
    }

    public static function fromRows(array $event, array $ticket): self
    {
        $eventId = self::positive($event, 'id');
        $ticketId = self::positive($ticket, 'id');
        if (self::positive($ticket, 'event_id') !== $eventId) {
            throw new InvalidArgumentException('Event ticket does not belong to event');
        }
        $price = Money::assertAmount((string) ($ticket['price'] ?? ''), 'ticket.price');
        if (Money::toMinor($price) <= 0) {
            throw new InvalidArgumentException('Cash event ticket price must be positive');
        }
        $productId = self::positive($ticket, 'product_id');
        $productAttrUnique = $ticket['product_attr_unique'] ?? null;
        if (!is_string($productAttrUnique)
            || $productAttrUnique === ''
            || strlen($productAttrUnique) > 20
            || preg_match('/^[A-Za-z0-9_-]+$/D', $productAttrUnique) !== 1) {
            throw new InvalidArgumentException('Event ticket product_attr_unique is invalid');
        }
        $integral = $ticket['integral_price'] ?? null;
        if (!is_int($integral) || $integral < 0 || $integral > 4294967295) {
            throw new InvalidArgumentException('Event ticket integral_price is invalid');
        }

        return new self([
            'event_id' => $eventId,
            'event_no' => self::text($event, 'event_no', 64),
            'ticket_id' => $ticketId,
            'ticket_name' => self::text($ticket, 'name', 120),
            'price' => $price,
            'integral_price' => $integral,
            'currency' => 'CNY',
            'product_id' => $productId,
            'product_attr_unique' => $productAttrUnique,
            'refund_policy' => self::policy($ticket['refund_policy_json'] ?? null),
        ]);
    }

    public static function fromContext(array $context): self
    {
        $price = self::jsonObject($context['price_snapshot_json'] ?? null, 'price_snapshot_json');
        $settlement = self::jsonObject($context['settlement_snapshot_json'] ?? null, 'settlement_snapshot_json');
        $refund = self::jsonObject($context['refund_policy_snapshot_json'] ?? null, 'refund_policy_snapshot_json', true);

        return self::fromRows(
            [
                'id' => $price['event_id'] ?? null,
                'event_no' => $price['event_no'] ?? null,
            ],
            [
                'id' => $price['ticket_id'] ?? null,
                'event_id' => $price['event_id'] ?? null,
                'name' => $price['ticket_name'] ?? null,
                'price' => $price['cash_amount'] ?? null,
                'integral_price' => $price['integral_amount'] ?? null,
                'product_id' => $settlement['product_id'] ?? null,
                'product_attr_unique' => $settlement['product_attr_unique'] ?? null,
                'refund_policy_json' => $refund,
            ]
        );
    }

    public function eventId(): int
    {
        return $this->values['event_id'];
    }

    public function eventNo(): string
    {
        return $this->values['event_no'];
    }

    public function ticketId(): int
    {
        return $this->values['ticket_id'];
    }

    public function ticketName(): string
    {
        return $this->values['ticket_name'];
    }

    public function price(): string
    {
        return $this->values['price'];
    }

    public function integralPrice(): int
    {
        return $this->values['integral_price'];
    }

    public function currency(): string
    {
        return $this->values['currency'];
    }

    public function productId(): int
    {
        return $this->values['product_id'];
    }

    public function productAttrUnique(): string
    {
        return $this->values['product_attr_unique'];
    }

    public function priceSnapshot(): array
    {
        return [
            'event_id' => $this->eventId(),
            'event_no' => $this->eventNo(),
            'ticket_id' => $this->ticketId(),
            'ticket_name' => $this->ticketName(),
            'cash_amount' => $this->price(),
            'integral_amount' => $this->integralPrice(),
            'currency' => $this->currency(),
        ];
    }

    public function settlementSnapshot(): array
    {
        return [
            'adapter' => 'crmeb-store-order-event-v1',
            'discounts_allowed' => false,
            'integral_allowed' => false,
            'commission_allowed' => false,
            'order_reward_integral_allowed' => false,
            'product_id' => $this->productId(),
            'product_attr_unique' => $this->productAttrUnique(),
            'quantity' => 1,
        ];
    }

    public function refundPolicySnapshot(): array
    {
        return $this->values['refund_policy'];
    }

    private static function positive(array $row, string $field): int
    {
        $value = $row[$field] ?? null;
        if (is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value) === 1) {
            $parsed = (int) $value;
            if ((string) $parsed === $value) {
                $value = $parsed;
            }
        }
        if (!is_int($value) || $value <= 0) {
            throw new InvalidArgumentException($field . ' must be a positive integer');
        }

        return $value;
    }

    private static function text(array $row, string $field, int $maxLength): string
    {
        $value = $row[$field] ?? null;
        if (!is_string($value) || $value === '' || strlen($value) > $maxLength) {
            throw new InvalidArgumentException($field . ' is invalid');
        }

        return $value;
    }

    private static function policy($value): array
    {
        if (is_string($value) && trim($value) !== '') {
            $value = json_decode($value, true);
        }
        if (!is_array($value)) {
            return [];
        }

        return $value;
    }

    private static function jsonObject($value, string $field, bool $emptyAllowed = false): array
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }
        if (!is_array($value) || (!$emptyAllowed && $value === [])) {
            throw new InvalidArgumentException($field . ' is invalid');
        }

        return $value;
    }
}
