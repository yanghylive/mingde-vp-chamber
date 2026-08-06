<?php

declare(strict_types=1);

namespace app\chamber\services;

use crmeb\exceptions\ApiException;
use think\facade\Db;

final class MembershipNativeOrderGuard
{
    /** @var callable */
    private $isMembershipProduct;

    /** @var callable */
    private $isEventProduct;

    public function __construct(callable $isMembershipProduct = null, callable $isEventProduct = null)
    {
        $this->isMembershipProduct = $isMembershipProduct ?: function (int $productId): bool {
            return Db::table('ch_membership_plan')
                ->where('product_id', $productId)
                ->count() > 0;
        };
        $this->isEventProduct = $isEventProduct ?: function (int $productId): bool {
            return Db::table('ch_event_ticket')
                ->where('product_id', $productId)
                ->count() > 0;
        };
    }

    public function assertProductAllowed(int $productId): void
    {
        if ($productId > 0 && (bool) call_user_func($this->isMembershipProduct, $productId)) {
            throw new ApiException('会籍商品只能从会籍中心购买');
        }
        if ($productId > 0 && (bool) call_user_func($this->isEventProduct, $productId)) {
            throw new ApiException('活动票只能从活动中心购买');
        }
    }

    public function assertCartGroupAllowed(array $cartGroup): void
    {
        foreach (['cartInfo', 'valid', 'invalid'] as $key) {
            $rows = $cartGroup[$key] ?? null;
            if (is_array($rows)) {
                $this->assertCartRowsAllowed($rows);
            }
        }
    }

    /**
     * Validate flat cart rows returned by CRMEB's list/DAO methods.
     *
     * @param array<int, mixed> $rows
     */
    public function assertCartRowsAllowed(array $rows): void
    {
        foreach ($rows as $row) {
            if (is_object($row) && method_exists($row, 'toArray')) {
                $row = $row->toArray();
            }
            if (!is_array($row)) {
                continue;
            }
            $productId = $row['product_id'] ?? ($row['productInfo']['id'] ?? 0);
            if (is_int($productId) || (is_string($productId) && ctype_digit($productId))) {
                $this->assertProductAllowed((int) $productId);
            }
        }
    }

    /**
     * Native payment may only touch a membership order after Chamber bound it.
     * This also blocks historical or forged CRMEB orders created outside checkout.
     *
     * @param mixed $order
     */
    public function assertOrderAllowed($order): void
    {
        if (!$this->isMembershipOrder($order)) {
            return;
        }
        if (is_object($order) && method_exists($order, 'toArray')) {
            $order = $order->toArray();
        }
        if (!is_array($order)) {
            return;
        }
        $orderId = (int) ($order['id'] ?? 0);
        $orderNo = (string) ($order['order_id'] ?? '');
        if ($orderId <= 0 && $orderNo !== '') {
            $orderId = (int) Db::table('eb_store_order')
                ->where('order_id', $orderNo)
                ->value('id');
        }
        if ($orderId <= 0) {
            return;
        }

        if ($this->isBoundMembershipOrder($orderId, $orderNo)) {
            return;
        }

        throw new ApiException($this->isEventOrder($order)
            ? '活动订单只能从活动中心操作'
            : '会籍订单只能从会籍中心操作');
    }

    /**
     * Native read endpoints must not expose membership orders, even after the
     * trusted Chamber context has been bound. Chamber owns the public order
     * projection and payment lifecycle for these orders.
     *
     * @param mixed $order
     */
    public function assertNativeReadAllowed($order): void
    {
        if ($this->isMembershipOrder($order)) {
            throw new ApiException($this->isEventOrder($order)
                ? '活动订单只能从活动中心查看'
                : '会籍订单只能从会籍中心查看');
        }
    }

    /**
     * Detect membership orders even when the Chamber context was never bound.
     * This is used by queue/timer paths that must fail closed for legacy rows.
     *
     * @param mixed $order
     */
    public function isMembershipOrder($order): bool
    {
        if (is_object($order) && method_exists($order, 'toArray')) {
            $order = $order->toArray();
        }
        if (!is_array($order)) {
            return false;
        }

        $productIds = $this->orderProductIds($order);
        foreach ($productIds as $productId) {
            if ($productId > 0 && ((bool) call_user_func($this->isMembershipProduct, $productId)
                    || (bool) call_user_func($this->isEventProduct, $productId))) {
                return true;
            }
        }

        return false;
    }

    /** @param mixed $order */
    private function isEventOrder($order): bool
    {
        if (is_object($order) && method_exists($order, 'toArray')) {
            $order = $order->toArray();
        }
        if (!is_array($order)) {
            return false;
        }
        foreach ($this->orderProductIds($order) as $productId) {
            if ($productId > 0 && (bool) call_user_func($this->isEventProduct, $productId)) {
                return true;
            }
        }

        return false;
    }

    private function orderProductIds(array $order): array
    {
        $productIds = [];
        $cartInfo = $order['cart_info'] ?? ($order['cartInfo'] ?? []);
        if (is_string($cartInfo)) {
            $decoded = json_decode($cartInfo, true);
            $cartInfo = is_array($decoded) ? $decoded : [];
        }
        if (is_array($cartInfo)) {
            foreach ($cartInfo as $row) {
                if (is_object($row) && method_exists($row, 'toArray')) {
                    $row = $row->toArray();
                }
                if (is_array($row)) {
                    $productIds[] = (int) ($row['product_id'] ?? ($row['cart_info']['product_id'] ?? 0));
                }
            }
        }

        $orderId = (int) ($order['id'] ?? 0);
        if ($orderId > 0) {
            $productIds = array_merge(
                $productIds,
                array_map('intval', Db::table('eb_store_order_cart_info')
                    ->where('oid', $orderId)
                    ->column('product_id'))
            );
        }
        return array_values(array_unique($productIds));
    }

    public function isBoundMembershipOrder(int $orderId, string $orderNo = ''): bool
    {
        if ($orderId <= 0) {
            return false;
        }
        $query = Db::table('ch_order_context')
            ->whereIn('business_type', ['membership', 'event_registration'])
            ->where('order_pk', $orderId);
        if ($orderNo !== '') {
            $query->where('order_no', $orderNo);
        }

        return $query->count() > 0;
    }
}
