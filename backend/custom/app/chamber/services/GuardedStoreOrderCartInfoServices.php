<?php

declare(strict_types=1);

namespace app\chamber\services;

use app\dao\order\StoreOrderCartInfoDao;
use app\services\order\StoreOrderCartInfoServices;

/**
 * Prevents native API endpoints that read order line items from exposing a
 * membership order outside the Chamber projection.
 */
final class GuardedStoreOrderCartInfoServices extends StoreOrderCartInfoServices
{
    /** @var MembershipNativeOrderGuard */
    private $membershipGuard;

    public function __construct(StoreOrderCartInfoDao $dao, MembershipNativeOrderGuard $membershipGuard)
    {
        parent::__construct($dao);
        $this->membershipGuard = $membershipGuard;
    }

    public function getOne(array $where, ?string $field = '*', array $with = [])
    {
        $row = $this->dao->getOne($where, $field, $with);
        $this->assertCartReadAllowed($row);

        return $row;
    }

    public function getOrderCartInfo(int $oid)
    {
        $this->assertOrderReadAllowed($oid);

        return parent::getOrderCartInfo($oid);
    }

    public function getCartInfoList(array $where, array $field)
    {
        $rows = $this->dao->getCartInfoList($where, $field);
        $this->assertCartRowsReadAllowed($rows);

        return $rows;
    }

    public function getCartColunm(array $where, string $field, string $key = '')
    {
        $rows = $this->dao->getCartColunm($where, $field, $key);
        $this->assertCartRowsReadAllowed(is_array($rows) ? $rows : []);

        return $rows;
    }

    public function getRefundCartList(int $oid, string $field = '*', string $key = '')
    {
        $this->assertOrderReadAllowed($oid);

        return parent::getRefundCartList($oid, $field, $key);
    }

    public function getCartIdsProduct($oid)
    {
        $this->assertOrderReadAllowed((int) $oid);

        return parent::getCartIdsProduct($oid);
    }

    public function getSplitCartList(int $oid, string $field = '*', string $key = 'cart_id')
    {
        $this->assertOrderReadAllowed($oid);

        return parent::getSplitCartList($oid, $field, $key);
    }

    public function getCartInfoPrintProduct($oid)
    {
        $this->assertOrderReadAllowed((int) $oid);

        return parent::getCartInfoPrintProduct($oid);
    }

    public function getCarIdByProductTitle(int $oid, bool $goodsNum = false)
    {
        $this->assertOrderReadAllowed($oid);

        return parent::getCarIdByProductTitle($oid, $goodsNum);
    }

    private function assertOrderReadAllowed(int $orderId): void
    {
        if ($orderId > 0) {
            $this->membershipGuard->assertNativeReadAllowed(['id' => $orderId]);
        }
    }

    /** @param mixed $row */
    private function assertCartReadAllowed($row): void
    {
        if (is_object($row) && method_exists($row, 'toArray')) {
            $row = $row->toArray();
        }
        if (!is_array($row)) {
            return;
        }

        $orderId = (int) ($row['oid'] ?? 0);
        if ($orderId > 0) {
            $this->assertOrderReadAllowed($orderId);
            return;
        }

        $cartInfo = $row['cart_info'] ?? ($row['cartInfo'] ?? []);
        if (is_string($cartInfo)) {
            $decoded = json_decode($cartInfo, true);
            $cartInfo = is_array($decoded) ? $decoded : [];
        }
        if (is_array($cartInfo)) {
            $productId = (int) ($cartInfo['product_id'] ?? ($cartInfo['productInfo']['id'] ?? 0));
            $this->membershipGuard->assertProductAllowed($productId);
        }
        $this->membershipGuard->assertProductAllowed((int) ($row['product_id'] ?? 0));
    }

    /** @param array<int, mixed> $rows */
    private function assertCartRowsReadAllowed(array $rows): void
    {
        foreach ($rows as $row) {
            $this->assertCartReadAllowed($row);
        }
    }
}
