<?php

declare(strict_types=1);

namespace app\chamber\services;

use app\dao\order\StoreOrderDao;
use app\services\order\StoreOrderDeliveryServices;
use think\facade\Db;

/**
 * Keeps CRMEB delivery and distribution mutations out of Chamber membership
 * orders. The service is bound at the root provider, so admin, kefu, outapi,
 * HTTP, queue and timer callers share the same decision.
 */
final class GuardedStoreOrderDeliveryServices extends StoreOrderDeliveryServices
{
    /** @var MembershipNativeOrderGuard */
    private $membershipGuard;

    public function __construct(StoreOrderDao $dao, MembershipNativeOrderGuard $membershipGuard)
    {
        parent::__construct($dao);
        $this->membershipGuard = $membershipGuard;
    }

    public function delivery(int $id, array $data)
    {
        $this->assertMutationAllowed($id);

        return parent::delivery($id, $data);
    }

    public function splitDelivery(int $id, array $data, $delivery_code = true)
    {
        $this->assertMutationAllowed($id);

        return parent::splitDelivery($id, $data, $delivery_code);
    }

    public function updateDistribution(int $id, array $data)
    {
        $this->assertMutationAllowed($id);

        return parent::updateDistribution($id, $data);
    }

    public function orderDeliveryGoods(int $id, array $data, $orderInfo, $storeTitle)
    {
        $this->assertMutationAllowed($id);

        return parent::orderDeliveryGoods($id, $data, $orderInfo, $storeTitle);
    }

    public function orderDelivery(int $id, array $data, $orderInfo, string $storeTitle)
    {
        $this->assertMutationAllowed($id);

        return parent::orderDelivery($id, $data, $orderInfo, $storeTitle);
    }

    public function orderDeliverGoods(int $id, array $data, $orderInfo, $storeTitle)
    {
        $this->assertMutationAllowed($id);

        return parent::orderDeliverGoods($id, $data, $orderInfo, $storeTitle);
    }

    public function orderVirtualDelivery(int $id, array $data)
    {
        $this->assertMutationAllowed($id);

        return parent::orderVirtualDelivery($id, $data);
    }

    public function orderDump($orderId, $type = 'order')
    {
        $id = (int) $orderId;
        if ($id > 0) {
            $this->assertMutationAllowed($id);
        } elseif (is_string($orderId) && $orderId !== '') {
            $order = $this->dao->getOne(['order_id' => $orderId]);
            $this->membershipGuard->assertNativeReadAllowed($order);
        }

        return parent::orderDump($orderId, $type);
    }

    public function getOrderSumWeight(int $id, $default = false)
    {
        $this->assertMutationAllowed($id);

        return parent::getOrderSumWeight($id, $default);
    }

    public function distributionForm(int $id)
    {
        $this->assertMutationAllowed($id);

        return parent::distributionForm($id);
    }

    public function virtualSend($orderInfo)
    {
        $this->assertMutationAllowed($this->orderId($orderInfo));

        return parent::virtualSend($orderInfo);
    }

    private function assertMutationAllowed(int $id): void
    {
        if ($id <= 0) {
            return;
        }

        // Cart rows live in eb_store_order_cart_info; cart_info is not an
        // eb_store_order column and must not be selected from the order table.
        $order = $this->dao->get($id, ['id', 'order_id', 'uid', 'virtual_type']);
        $this->membershipGuard->assertNativeReadAllowed($order);
    }

    /** @param mixed $order */
    private function orderId($order): int
    {
        if (is_object($order) && method_exists($order, 'toArray')) {
            $order = $order->toArray();
        }
        if (!is_array($order)) {
            return 0;
        }
        $id = (int) ($order['id'] ?? 0);
        if ($id > 0) {
            return $id;
        }
        $orderNo = (string) ($order['order_id'] ?? '');
        if ($orderNo === '') {
            return 0;
        }
        return (int) Db::table('eb_store_order')->where('order_id', $orderNo)->value('id');
    }
}
