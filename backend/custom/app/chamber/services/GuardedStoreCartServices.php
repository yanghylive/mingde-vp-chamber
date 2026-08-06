<?php

declare(strict_types=1);

namespace app\chamber\services;

use app\dao\order\StoreCartDao;
use app\services\order\StoreCartServices;
use think\facade\Db;

final class GuardedStoreCartServices extends StoreCartServices
{
    /** @var MembershipNativeOrderGuard */
    private $membershipGuard;

    public function __construct(StoreCartDao $dao, MembershipNativeOrderGuard $membershipGuard)
    {
        parent::__construct($dao);
        $this->membershipGuard = $membershipGuard;
    }

    public function setCart(
        int $uid,
        int $product_id,
        int $cart_num = 1,
        string $product_attr_unique = '',
        int $type = 0,
        bool $new = true,
        int $combination_id = 0,
        int $seckill_id = 0,
        int $bargain_id = 0,
        int $advance_id = 0
    ) {
        $this->membershipGuard->assertProductAllowed($product_id);

        return parent::setCart(
            $uid,
            $product_id,
            $cart_num,
            $product_attr_unique,
            $type,
            $new,
            $combination_id,
            $seckill_id,
            $bargain_id,
            $advance_id
        );
    }

    /**
     * CRMEB exposes this path from the v2 product page. Keep it behind the
     * same product boundary as the regular add-to-cart method.
     */
    public function setCartNum($uid, $productId, $num, $unique, $type)
    {
        $this->membershipGuard->assertProductAllowed((int) $productId);

        return parent::setCartNum($uid, $productId, $num, $unique, $type);
    }

    public function checkProductStock(
        int $uid,
        int $cartNum,
        string $unique,
        int $type = 0,
        $productId,
        int $seckillId,
        int $bargainId,
        int $combinationId,
        int $advanceId
    ) {
        $this->membershipGuard->assertProductAllowed((int) $productId);

        return parent::checkProductStock(
            $uid,
            $cartNum,
            $unique,
            $type,
            $productId,
            $seckillId,
            $bargainId,
            $combinationId,
            $advanceId
        );
    }

    public function getUserCartNums(array $unique, int $productId, int $uid)
    {
        $this->membershipGuard->assertProductAllowed($productId);

        return parent::getUserCartNums($unique, $productId, $uid);
    }

    public function changeUserCartNum($id, $number, $uid)
    {
        $this->assertCartIdAllowed((int) $id, (int) $uid);

        return parent::changeUserCartNum($id, $number, $uid);
    }

    public function removeUserCart(int $uid, array $ids)
    {
        foreach ($ids as $id) {
            $this->assertCartIdAllowed((int) $id, $uid);
        }

        return parent::removeUserCart($uid, $ids);
    }

    public function modifyCart(int $cart_id, int $product_id, string $unique)
    {
        $this->assertCartIdAllowed($cart_id);
        $this->membershipGuard->assertProductAllowed($product_id);

        return parent::modifyCart($cart_id, $product_id, $unique);
    }

    public function resetCart($id, $uid, $productId, $unique, $num)
    {
        $this->assertCartIdAllowed((int) $id, (int) $uid);
        $this->membershipGuard->assertProductAllowed((int) $productId);

        return parent::resetCart($id, $uid, $productId, $unique, $num);
    }

    public function getUserProductCartListV1(
        $uid,
        $cartIds = '',
        bool $new,
        $addr = [],
        int $shipping_type = 1,
        $is_gift = 0
    ) {
        $cartGroup = parent::getUserProductCartListV1(
            $uid,
            $cartIds,
            $new,
            $addr,
            $shipping_type,
            $is_gift
        );
        $this->membershipGuard->assertCartGroupAllowed($cartGroup);

        return $cartGroup;
    }

    public function getUserCartList(int $uid, int $status, string $cartIds = '')
    {
        $cartGroup = parent::getUserCartList($uid, $status, $cartIds);
        $this->membershipGuard->assertCartGroupAllowed($cartGroup);

        return $cartGroup;
    }

    public function getUserCartCount(int $uid, string $numType)
    {
        $rows = $this->dao->getUserCartList($uid, 'product_id');
        $this->membershipGuard->assertCartRowsAllowed(is_array($rows) ? $rows : []);

        return parent::getUserCartCount($uid, $numType);
    }

    public function getUserCartNum($uid, $type, $numType)
    {
        $rows = $this->dao->getCartList([
            'uid' => $uid,
            'type' => $type,
            'is_pay' => 0,
            'is_new' => 0,
            'is_del' => 0,
        ]);
        $this->membershipGuard->assertCartRowsAllowed(is_array($rows) ? $rows : []);

        return $this->dao->getUserCartNum($uid, $type, $numType);
    }

    public function get($id, ?array $field = [], ?array $with = [])
    {
        $row = $this->dao->get($id, $field, $with);
        $this->assertCartRowAllowed($row);

        return $row;
    }

    public function getOne(array $where, ?string $field = '*', array $with = [])
    {
        $row = $this->dao->getOne($where, $field, $with);
        $this->assertCartRowAllowed($row);

        return $row;
    }

    public function update($id, array $data, ?string $key = null)
    {
        $row = is_array($id) ? $this->dao->getOne($id) : $this->dao->get($id);
        $this->assertCartRowAllowed($row);

        return $this->dao->update($id, $data, $key);
    }

    public function delete($id, ?string $key = null)
    {
        $where = is_array($id) ? $id : [is_null($key) ? 'id' : $key => $id];
        $this->assertCartRowAllowed($this->dao->getOne($where));

        return $this->dao->delete($id, $key);
    }

    public function updateCartStatus($cartIds)
    {
        foreach ((array) $cartIds as $cartId) {
            $this->assertCartIdAllowed((int) $cartId);
        }

        if (method_exists($this->dao, 'updateCartStatus')) {
            return $this->dao->updateCartStatus($cartIds);
        }

        return $this->dao->updateDel((array) $cartIds);
    }

    public function deleteCartStatus($cartIds)
    {
        foreach ((array) $cartIds as $cartId) {
            $this->assertCartIdAllowed((int) $cartId);
        }

        return $this->dao->deleteCartStatus($cartIds);
    }

    public function productIdByCartNum(array $ids, int $uid)
    {
        foreach ($ids as $productId) {
            $this->membershipGuard->assertProductAllowed((int) $productId);
        }

        return $this->dao->productIdByCartNum($ids, $uid);
    }

    public function changeStatus(int $productId, $status = 0)
    {
        $this->membershipGuard->assertProductAllowed($productId);

        return parent::changeStatus($productId, $status);
    }

    /**
     * The v2 and PC controllers call this DAO method through BaseServices::__call.
     * Expose it explicitly so native cart reads cannot reveal a membership cart.
     */
    public function getCartList(array $where, ?int $page = 0, ?int $limit = 0, ?array $with = [])
    {
        $rows = parent::getCartList($where, $page, $limit, $with);
        $this->membershipGuard->assertCartRowsAllowed(is_array($rows) ? $rows : []);

        return $rows;
    }

    private function assertCartIdAllowed(int $cartId, int $uid = 0): void
    {
        if ($cartId <= 0) {
            return;
        }

        $query = Db::table('eb_store_cart')->where('id', $cartId);
        if ($uid > 0) {
            $query->where('uid', $uid);
        }
        $productId = (int) $query->value('product_id');
        if ($productId > 0) {
            $this->membershipGuard->assertProductAllowed($productId);
        }
    }

    /** @param mixed $row */
    private function assertCartRowAllowed($row): void
    {
        if (is_object($row) && method_exists($row, 'toArray')) {
            $row = $row->toArray();
        }
        if (is_array($row)) {
            $this->membershipGuard->assertCartRowsAllowed([$row]);
        }
    }
}
