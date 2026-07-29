<?php

declare(strict_types=1);

namespace app\chamber\contracts;

use app\chamber\activity\EventTicketOrderSnapshot;

interface EventOrderGatewayInterface
{
    public function assertTicketProduct(EventTicketOrderSnapshot $ticket): void;

    public function findByCheckoutKey(int $uid, string $checkoutKey): ?array;

    public function create(
        array $authenticatedUser,
        EventTicketOrderSnapshot $ticket,
        string $checkoutKey
    ): array;

    public function assertOrderMatches(
        array $order,
        EventTicketOrderSnapshot $ticket,
        int $uid,
        string $checkoutKey
    ): array;

    /** Cancels an unpaid order. Returns false when payment already won the race. */
    public function cancelUnpaid(array $order): bool;
}
