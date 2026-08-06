<?php

declare(strict_types=1);

namespace app\chamber\services;

use app\dao\order\StoreOrderDao;
use app\services\order\OutStoreOrderServices;

/**
 * OutAPI has a receive path that updates the order before invoking the native
 * take service. Guarding here prevents a membership order from being mutated
 * before the later post-take guard can run.
 */
final class GuardedOutStoreOrderServices extends OutStoreOrderServices
{
    /** @var MembershipNativeOrderGuard */
    private $membershipGuard;

    public function __construct(StoreOrderDao $dao, MembershipNativeOrderGuard $membershipGuard)
    {
        parent::__construct($dao);
        $this->membershipGuard = $membershipGuard;
    }

    public function receive(string $orderId): bool
    {
        $this->assertOrderAllowed(['order_id' => $orderId]);

        return parent::receive($orderId);
    }

    public function delivery(string $orderId, array $data)
    {
        $this->assertOrderAllowed(['order_id' => $orderId]);

        return parent::delivery($orderId, $data);
    }

    public function splitDelivery(string $orderId, array $data): bool
    {
        $this->assertOrderAllowed(['order_id' => $orderId]);

        return parent::splitDelivery($orderId, $data);
    }

    public function updateDistribution(string $orderId, array $data)
    {
        $this->assertOrderAllowed(['order_id' => $orderId]);

        return parent::updateDistribution($orderId, $data);
    }

    private function assertOrderAllowed(array $where): void
    {
        $order = $this->dao->getOne($where);
        $this->membershipGuard->assertNativeReadAllowed($order);
    }
}
