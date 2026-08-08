<?php

declare(strict_types=1);

namespace app\chamber\contracts;

use app\chamber\commerce\EventRefundGatewayResult;

interface EventRefundGatewayInterface
{
    public function loadOrder(int $orderPk): array;

    public function provider(array $order): string;

    public function supportsAutomaticAmount(array $order, string $amount, string $remaining): bool;

    public function submitApplication(
        array $order,
        string $providerRefundNo,
        string $amount,
        string $reason
    ): EventRefundGatewayResult;

    public function query(array $attempt): EventRefundGatewayResult;
}
