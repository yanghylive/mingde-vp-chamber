<?php

declare(strict_types=1);

use app\chamber\activity\EventTicketOrderSnapshot;
use app\chamber\contracts\EventOrderGatewayInterface;

final class TestEventOrderGateway implements EventOrderGatewayInterface
{
    private $orders = [];
    private $nextOrderPk;
    private $runId;
    private $createCount = 0;

    public function __construct(int $nextOrderPk, string $runId)
    {
        $this->nextOrderPk = $nextOrderPk;
        $this->runId = $runId;
    }

    public function assertTicketProduct(EventTicketOrderSnapshot $ticket): void
    {
        unset($ticket);
    }

    public function findByCheckoutKey(int $uid, string $checkoutKey): ?array
    {
        return $this->orders[$uid . ':' . $checkoutKey] ?? null;
    }

    public function create(array $authenticatedUser, EventTicketOrderSnapshot $ticket, string $checkoutKey): array
    {
        $uid = (int) ($authenticatedUser['uid'] ?? 0);
        $this->createCount++;
        $order = [
            'order_pk' => $this->nextOrderPk++,
            'order_no' => 'EVT' . $this->runId . str_pad((string) $this->createCount, 4, '0', STR_PAD_LEFT),
            'order_status' => 'pending_payment',
            'payable_amount' => $ticket->price(),
            'currency' => 'CNY',
            'payment_required' => true,
            'uid' => $uid,
            'checkout_key' => $checkoutKey,
            'ticket_id' => $ticket->ticketId(),
        ];
        $this->orders[$uid . ':' . $checkoutKey] = $order;

        return $order;
    }

    public function assertOrderMatches(array $order, EventTicketOrderSnapshot $ticket, int $uid, string $checkoutKey): array
    {
        if (($order['uid'] ?? 0) !== $uid
            || ($order['checkout_key'] ?? '') !== $checkoutKey
            || ($order['ticket_id'] ?? 0) !== $ticket->ticketId()) {
            throw new RuntimeException('Fake event order mismatch');
        }

        return $order;
    }

    public function cancelUnpaid(array $order): bool
    {
        return ($order['order_status'] ?? '') === 'pending_payment';
    }

    public function createCount(): int
    {
        return $this->createCount;
    }
}
