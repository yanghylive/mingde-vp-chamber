<?php

declare(strict_types=1);

namespace app\chamber\services;

use app\dao\order\StoreOrderDao;
use app\services\order\StoreOrderTakeServices;

final class GuardedStoreOrderTakeServices extends StoreOrderTakeServices
{
    /** @var MembershipNativeOrderGuard */
    private $membershipGuard;

    public function __construct(StoreOrderDao $dao, MembershipNativeOrderGuard $membershipGuard)
    {
        parent::__construct($dao);
        $this->membershipGuard = $membershipGuard;
    }

    public function gainUserIntegral($order, $userInfo, $storeTitle)
    {
        if ($this->isMembershipOrder($order)) {
            return true;
        }

        return parent::gainUserIntegral($order, $userInfo, $storeTitle);
    }

    public function gainUserExp($order, $userInfo)
    {
        if ($this->isMembershipOrder($order)) {
            return true;
        }

        return parent::gainUserExp($order, $userInfo);
    }

    public function storeProductOrderUserTakeDelivery($order, bool $isTran = true)
    {
        // Skip the whole post-take event fan-out for membership orders. The
        // Chamber payment/entitlement flow owns their lifecycle and rewards.
        if ($this->isMembershipOrder($order)) {
            return true;
        }

        return parent::storeProductOrderUserTakeDelivery($order, $isTran);
    }

    public function backOrderBrokerage($orderInfo, $userInfo)
    {
        if ($this->isMembershipOrder($orderInfo)) {
            return true;
        }

        return parent::backOrderBrokerage($orderInfo, $userInfo);
    }

    public function divisionBrokerage($orderInfo, $userInfo)
    {
        if ($this->isMembershipOrder($orderInfo)) {
            return true;
        }

        return parent::divisionBrokerage($orderInfo, $userInfo);
    }

    private function isMembershipOrder($order): bool
    {
        if (is_object($order) && method_exists($order, 'toArray')) {
            $order = $order->toArray();
        }
        if (!is_array($order)) {
            return false;
        }

        return $this->membershipGuard->isMembershipOrder($order);
    }
}
