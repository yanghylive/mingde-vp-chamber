<?php

declare(strict_types=1);

namespace app\chamber\services;

use app\dao\order\StoreOrderDao;
use app\services\order\StoreOrderServices;

final class GuardedStoreOrderServices extends StoreOrderServices
{
    /** @var MembershipNativeOrderGuard */
    private $membershipGuard;

    public function __construct(StoreOrderDao $dao, MembershipNativeOrderGuard $membershipGuard)
    {
        parent::__construct($dao);
        $this->membershipGuard = $membershipGuard;
    }

    public function get($id, ?array $field = [], ?array $with = [])
    {
        $order = $this->dao->get($id, $field, $with);
        $this->membershipGuard->assertOrderAllowed($order);

        return $order;
    }

    public function getOne(array $where, ?string $field = '*', array $with = [])
    {
        $order = $this->dao->getOne($where, $field, $with);
        $this->membershipGuard->assertOrderAllowed($order);

        return $order;
    }

    public function getUserOrderDetail(string $key, int $uid, $with = [])
    {
        $order = $this->dao->getUserOrderDetail($key, $uid, $with);
        $this->membershipGuard->assertNativeReadAllowed($order);

        return $order;
    }

    public function getOrderData(int $uid = 0)
    {
        if ($uid > 0) {
            $rows = $this->dao->getList(['uid' => $uid], ['id'], 0, 0, []);
            $this->assertNativeOrderRowsAllowed(is_array($rows) ? $rows : []);
        }

        return parent::getOrderData($uid);
    }

    public function getOrderApiList(array $where, array $field = ['*'], array $with = [])
    {
        $result = parent::getOrderApiList($where, $field, $with);
        $this->assertNativeOrderRowsAllowed(is_array($result) ? $result : []);

        return $result;
    }

    public function getOrderList(array $where, array $field = ['*'], array $with = [])
    {
        $result = parent::getOrderList($where, $field, $with);
        if (is_array($result) && isset($result['data']) && is_array($result['data'])) {
            $this->assertNativeOrderRowsAllowed($result['data']);
        }

        return $result;
    }

    public function getList(array $where, array $field, int $page = 0, int $limit = 0, array $with = [])
    {
        $rows = $this->dao->getList($where, $field, $page, $limit, $with);
        $this->assertNativeOrderRowsAllowed(is_array($rows) ? $rows : []);

        return $rows;
    }

    public function getSplitOrderList(
        array $where,
        array $field = ['*'],
        array $with = [],
        $page = 0,
        $limit = 0,
        $order = 'pay_time DESC,id DESC'
    ) {
        $rows = parent::getSplitOrderList($where, $field, $with, $page, $limit, $order);
        $this->assertNativeOrderRowsAllowed(is_array($rows) ? $rows : []);

        return $rows;
    }

    public function getFriendDetail($orderId, $uid)
    {
        $this->assertNativeOrderLookup(['id' => (int) $orderId, 'is_del' => 0]);

        return parent::getFriendDetail($orderId, $uid);
    }

    public function refundCartInfoList(array $cart_ids = [], int $id = 0)
    {
        $this->assertNativeOrderLookup(['id' => $id]);

        return parent::refundCartInfoList($cart_ids, $id);
    }

    public function getCashierInfo(int $uid, string $orderId, string $type)
    {
        if ($type === 'order') {
            $this->assertNativeOrderLookup(['order_id' => $orderId]);
        }

        return parent::getCashierInfo($uid, $orderId, $type);
    }

    public function cancelOrder($order_id, int $uid)
    {
        $this->assertNativeOrderLookup(['order_id' => $order_id, 'uid' => $uid, 'is_del' => 0]);

        return parent::cancelOrder($order_id, $uid);
    }

    public function giftDetail($oid)
    {
        $this->assertNativeOrderLookup(['id' => (int) $oid, 'is_del' => 0]);

        return parent::giftDetail($oid);
    }

    public function receiveGift($uid, $oid, $gift_key, $shipping_type, $name, $phone, $address_id = 0, $store_id = 0)
    {
        $this->assertNativeOrderLookup(['id' => (int) $oid]);

        return parent::receiveGift($uid, $oid, $gift_key, $shipping_type, $name, $phone, $address_id, $store_id);
    }

    public function delete($id, ?string $key = null)
    {
        $where = is_array($id) ? $id : [is_null($key) ? 'id' : $key => $id];
        $this->assertNativeOrderLookup($where);

        return $this->dao->delete($id, $key);
    }

    /**
     * @param array<int, mixed> $rows
     */
    private function assertOrderRowsAllowed(array $rows): void
    {
        foreach ($rows as $row) {
            $this->membershipGuard->assertOrderAllowed($row);
        }
    }

    /**
     * @param array<int, mixed> $rows
     */
    private function assertNativeOrderRowsAllowed(array $rows): void
    {
        foreach ($rows as $row) {
            $this->membershipGuard->assertNativeReadAllowed($row);
        }
    }

    private function assertOrderLookup(array $where): void
    {
        $this->membershipGuard->assertOrderAllowed($this->dao->getOne($where));
    }

    private function assertNativeOrderLookup(array $where): void
    {
        $this->membershipGuard->assertNativeReadAllowed($this->dao->getOne($where));
    }
}
