<?php

declare(strict_types=1);

namespace app\chamber\services;

use app\chamber\commerce\Money;
use app\chamber\contracts\MembershipOrderGatewayInterface;
use app\chamber\exceptions\MemberTransactionException;
use app\chamber\membership\MembershipPlanSnapshot;
use app\dao\order\StoreOrderCartInfoDao;
use app\dao\order\StoreOrderDao;
use app\services\order\StoreCartServices;
use app\services\order\StoreOrderCreateServices;
use app\services\order\StoreOrderServices;
use app\services\product\product\StoreProductServices;
use app\services\product\sku\StoreProductAttrValueServices;
use crmeb\services\CacheService;
use Throwable;

final class CrmebMembershipOrderGateway implements MembershipOrderGatewayInterface
{
    private const CHECKOUT_KEY_PATTERN = '/^[a-f0-9]{32}$/D';
    private const CHECKOUT_FORM_FIELD = 'chamber_membership_checkout_key';
    private const ORDER_CACHE_SECONDS = 600;
    private const MEMBERSHIP_VIRTUAL_TYPE = 3;

    public function assertPlanProduct(MembershipPlanSnapshot $plan): void
    {
        try {
            if ($plan->currency() !== 'CNY') {
                throw $this->inconsistent();
            }

            /** @var StoreProductServices $products */
            $products = app()->make(StoreProductServices::class);
            $productModel = $products->get($plan->productId());
            if (!$productModel) {
                throw $this->unavailable();
            }
            $product = $this->snapshot($productModel);
            if ($this->integerField($product, 'id') !== $plan->productId()) {
                throw $this->inconsistent();
            }
            if ($this->integerField($product, 'is_del') !== 0
                || $this->integerField($product, 'is_show') !== 1
                || $this->integerField($product, 'stock') < 1) {
                throw $this->unavailable();
            }
            foreach ([
                'is_virtual' => 1,
                'virtual_type' => self::MEMBERSHIP_VIRTUAL_TYPE,
                'is_sub' => 1,
                'is_vip' => 0,
                'presale' => 0,
                'is_gift' => 0,
                'give_integral' => 0,
                'is_limit' => 0,
                'min_qty' => 1,
            ] as $field => $expected) {
                if ($this->integerField($product, $field) !== $expected) {
                    throw $this->inconsistent();
                }
            }
            $this->assertMoneyEquals($this->field($product, 'price'), $plan->price());
            $this->assertMoneyEquals($this->field($product, 'vip_price'), '0.00');
            $this->assertMoneyEquals($this->field($product, 'gift_price'), '0.00');

            /** @var StoreProductAttrValueServices $attributes */
            $attributes = app()->make(StoreProductAttrValueServices::class);
            $attributeModel = $attributes->getOne([
                'product_id' => $plan->productId(),
                'unique' => $plan->productAttrUnique(),
                'type' => 0,
            ]);
            if (!$attributeModel) {
                throw $this->unavailable();
            }
            $attribute = $this->snapshot($attributeModel);
            if ($this->integerField($attribute, 'product_id') !== $plan->productId()
                || $this->stringField($attribute, 'unique', 20) !== $plan->productAttrUnique()) {
                throw $this->inconsistent();
            }
            if ($this->integerField($attribute, 'stock') < 1
                || $this->integerField($attribute, 'is_show') !== 1) {
                throw $this->unavailable();
            }
            foreach (['type' => 0, 'is_virtual' => 1, 'coupon_id' => 0] as $field => $expected) {
                if ($this->integerField($attribute, $field) !== $expected) {
                    throw $this->inconsistent();
                }
            }
            foreach (['brokerage', 'brokerage_two'] as $field) {
                $this->assertMoneyEquals($this->field($attribute, $field), '0.00');
            }
            $this->assertMoneyEquals($this->field($attribute, 'price'), $plan->price());
            $this->assertMoneyEquals($this->field($attribute, 'vip_price'), '0.00');
        } catch (MemberTransactionException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw $this->unavailable();
        }
    }

    public function findByCheckoutKey(int $uid, string $checkoutKey): ?array
    {
        $this->assertUidAndCheckoutKey($uid, $checkoutKey);

        try {
            // Recovery may run before OrderContext is bound. Query the native
            // DAO directly so an API read guard cannot hide a committed order
            // that reconciliation must reattach.
            /** @var StoreOrderDao $orders */
            $orders = app()->make(StoreOrderDao::class);
            $order = $orders->getOne(['uid' => $uid, 'unique' => $checkoutKey]);
            if (!$order) {
                return null;
            }

            return $this->withPersistedCartInfo($this->snapshot($order));
        } catch (MemberTransactionException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw $this->unavailable();
        }
    }

    public function create(
        array $authenticatedUser,
        MembershipPlanSnapshot $plan,
        string $checkoutKey
    ): array {
        $user = $this->authenticatedUser($authenticatedUser);
        $uid = $user['uid'];
        $this->assertUidAndCheckoutKey($uid, $checkoutKey);

        $existing = $this->findByCheckoutKey($uid, $checkoutKey);
        if ($existing !== null) {
            $this->assertOrderMatches($existing, $plan, $uid, $checkoutKey);
            return $existing;
        }

        $this->assertPlanProduct($plan);

        try {
            /** @var StoreCartServices $carts */
            $carts = app()->make(StoreCartServices::class);
            $cartKey = $carts->setCart(
                $uid,
                $plan->productId(),
                1,
                $plan->productAttrUnique(),
                0,
                true,
                0,
                0,
                0,
                0
            );
            $cartKey = $this->opaqueCrmebKey($cartKey);

            /** @var StoreOrderServices $orders */
            $orders = app()->make(StoreOrderServices::class);
            $confirmation = $orders->getOrderConfirmData($user, $cartKey, true, 0, 1, 0);
            if (!is_array($confirmation)) {
                throw $this->inconsistent();
            }
            $this->assertConfirmation($confirmation, $plan);
            $generatedOrderKey = $this->opaqueCrmebKey($this->field($confirmation, 'orderKey'));

            $cartGroup = $orders->getCacheOrderInfo($uid, $generatedOrderKey);
            if (!is_array($cartGroup)) {
                throw $this->unavailable();
            }
            $this->assertCartGroup($cartGroup, $plan);
            if (!CacheService::set(
                'user_order_' . $uid . $checkoutKey,
                $cartGroup,
                self::ORDER_CACHE_SECONDS
            )) {
                throw $this->unavailable();
            }

            $raced = $this->findByCheckoutKey($uid, $checkoutKey);
            if ($raced !== null) {
                $this->assertOrderMatches($raced, $plan, $uid, $checkoutKey);
                return $raced;
            }

            /** @var StoreOrderCreateServices $creator */
            $creator = app()->make(StoreOrderCreateServices::class);
            try {
                $created = $creator->createOrder(
                    $uid,
                    $checkoutKey,
                    $user,
                    0,
                    'weixin',
                    false,
                    0,
                    '',
                    0,
                    0,
                    0,
                    0,
                    1,
                    '',
                    '',
                    0,
                    true,
                    0,
                    $this->checkoutForm($checkoutKey),
                    0,
                    0,
                    ''
                );
            } catch (Throwable $exception) {
                $recovered = $this->findByCheckoutKey($uid, $checkoutKey);
                if ($recovered !== null) {
                    $this->assertOrderMatches($recovered, $plan, $uid, $checkoutKey);
                    return $recovered;
                }
                throw $this->unavailable();
            }

            $createdSnapshot = $this->snapshot($created);
            $orderPk = $this->positiveIntegerField($createdSnapshot, 'id');
            /** @var StoreOrderDao $orderDao */
            $orderDao = app()->make(StoreOrderDao::class);
            $persisted = $orderDao->getOne(['id' => $orderPk, 'uid' => $uid, 'unique' => $checkoutKey]);
            if (!$persisted) {
                throw $this->unavailable();
            }
            $result = $this->withPersistedCartInfo($this->snapshot($persisted));
            $this->assertOrderMatches($result, $plan, $uid, $checkoutKey);

            return $result;
        } catch (MemberTransactionException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw $this->unavailable();
        }
    }

    public function assertOrderMatches(
        array $order,
        MembershipPlanSnapshot $plan,
        int $uid,
        string $checkoutKey
    ): array {
        $this->assertUidAndCheckoutKey($uid, $checkoutKey);
        if ($plan->currency() !== 'CNY') {
            throw $this->inconsistent();
        }

        $orderPk = $this->positiveIntegerField($order, 'id');
        if ($this->positiveIntegerField($order, 'uid') !== $uid
            || $this->stringField($order, 'unique', 32) !== $checkoutKey
            || $this->stringField($order, 'pay_type', 32) !== 'weixin'
            || $this->integerField($order, 'pid') !== 0
            || $this->integerField($order, 'total_num') !== 1
            || $this->integerField($order, 'virtual_type') !== self::MEMBERSHIP_VIRTUAL_TYPE
            || $this->integerField($order, 'shipping_type') !== 1
            || $this->integerField($order, 'is_gift') !== 0
            || $this->positiveIntegerField($order, 'pay_uid') !== $uid) {
            throw $this->inconsistent();
        }
        foreach ([
            'coupon_id',
            'combination_id',
            'pink_id',
            'seckill_id',
            'bargain_id',
            'advance_id',
        ] as $field) {
            if ($this->integerField($order, $field) !== 0) {
                throw $this->inconsistent();
            }
        }
        foreach (['total_price', 'pay_price'] as $field) {
            $this->assertMoneyEquals($this->field($order, $field), $plan->price());
        }
        foreach ([
            'total_postage',
            'pay_postage',
            'coupon_price',
            'deduction_price',
            'use_integral',
            'gain_integral',
            'gift_price',
            'one_brokerage',
            'two_brokerage',
            'staff_brokerage',
            'agent_brokerage',
            'division_brokerage',
        ] as $field) {
            $this->assertMoneyEquals($this->field($order, $field), '0.00');
        }
        $this->assertCheckoutForm($this->field($order, 'custom_form'), $checkoutKey);

        $cartIds = $this->field($order, 'cart_id');
        if (is_string($cartIds)) {
            $cartIds = $this->decodeJsonArray($cartIds);
        }
        if (!is_array($cartIds) || count($cartIds) !== 1) {
            throw $this->inconsistent();
        }
        $cartId = $this->opaqueCrmebKey(array_values($cartIds)[0]);

        $rows = $this->field($order, 'cart_info');
        if (!is_array($rows) || count($rows) !== 1 || array_values($rows) !== $rows) {
            throw $this->inconsistent();
        }
        $row = $rows[0];
        if (!is_array($row)
            || $this->positiveIntegerField($row, 'oid') !== $orderPk
            || $this->positiveIntegerField($row, 'uid') !== $uid
            || $this->stringField($row, 'cart_id', 50) !== $cartId
            || $this->positiveIntegerField($row, 'product_id') !== $plan->productId()
            || $this->integerField($row, 'cart_num') !== 1) {
            throw $this->inconsistent();
        }
        $cart = $this->field($row, 'cart_info');
        if (is_string($cart)) {
            $cart = $this->decodeJsonArray($cart);
        }
        if (!is_array($cart)) {
            throw $this->inconsistent();
        }
        $this->assertCart($cart, $plan, $cartId, true);

        $payable = $this->money($this->field($order, 'pay_price'));
        $paid = $this->binaryField($order, 'paid');
        $status = $this->integerField($order, 'status');
        $refundStatus = $this->integerField($order, 'refund_status');
        $isCancel = $this->binaryField($order, 'is_cancel');
        $isDeleted = $this->binaryField($order, 'is_del');
        $refundAmount = $this->money($this->field($order, 'refund_price'));
        $orderStatus = $this->orderStatus(
            $paid,
            $status,
            $refundStatus,
            $isCancel,
            $isDeleted,
            $payable,
            $refundAmount
        );
        $orderNo = $this->stringField($order, 'order_id', 64);

        return [
            'order_pk' => $orderPk,
            'order_no' => $orderNo,
            'order_status' => $orderStatus,
            'payable_amount' => $payable,
            'currency' => 'CNY',
            'payment_required' => $orderStatus === 'pending_payment' && $payable !== '0.00',
        ];
    }

    private function assertConfirmation(array $confirmation, MembershipPlanSnapshot $plan): void
    {
        if ($this->integerField($confirmation, 'valid_count') !== 1
            || !in_array($this->field($confirmation, 'virtual_type'), [true, 1], true)
            || !in_array($this->field($confirmation, 'deduction'), [false, 0], true)) {
            throw $this->inconsistent();
        }
        foreach (['seckill_id', 'combination_id', 'bargain_id', 'advance_id'] as $field) {
            if ($this->integerField($confirmation, $field) !== 0) {
                throw $this->inconsistent();
            }
        }
        $cartInfo = $this->field($confirmation, 'cartInfo');
        if (!is_array($cartInfo) || count($cartInfo) !== 1 || array_values($cartInfo) !== $cartInfo) {
            throw $this->inconsistent();
        }
        $this->assertCart($cartInfo[0], $plan, null, false);
        $this->assertPriceGroup($this->field($confirmation, 'priceGroup'), $plan);
    }

    private function assertCartGroup(array $cartGroup, MembershipPlanSnapshot $plan): void
    {
        $cartInfo = $this->field($cartGroup, 'cartInfo');
        if (!is_array($cartInfo) || count($cartInfo) !== 1 || array_values($cartInfo) !== $cartInfo) {
            throw $this->inconsistent();
        }
        $this->assertCart($cartInfo[0], $plan, null, false);
        $this->assertPriceGroup($this->field($cartGroup, 'priceGroup'), $plan);
        if (!is_array($this->field($cartGroup, 'other'))) {
            throw $this->inconsistent();
        }
    }

    /**
     * @param mixed $priceGroup
     */
    private function assertPriceGroup($priceGroup, MembershipPlanSnapshot $plan): void
    {
        if (!is_array($priceGroup)) {
            throw $this->inconsistent();
        }
        foreach (['sumPrice', 'totalPrice'] as $field) {
            $this->assertMoneyEquals($this->field($priceGroup, $field), $plan->price());
        }
        foreach (['storePostage', 'vipPrice', 'levelPrice', 'memberPrice', 'giftPrice'] as $field) {
            $this->assertMoneyEquals($this->field($priceGroup, $field), '0.00');
        }
    }

    private function assertCart(
        array $cart,
        MembershipPlanSnapshot $plan,
        ?string $expectedCartId,
        bool $persisted
    ): void {
        $cartId = $this->opaqueCrmebKey($this->field($cart, 'id'));
        if (($expectedCartId !== null && $cartId !== $expectedCartId)
            || $this->integerField($cart, 'type') !== 0
            || $this->positiveIntegerField($cart, 'product_id') !== $plan->productId()
            || $this->stringField($cart, 'product_attr_unique', 20) !== $plan->productAttrUnique()
            || $this->integerField($cart, 'cart_num') !== 1) {
            throw $this->inconsistent();
        }
        foreach (['seckill_id', 'bargain_id', 'combination_id', 'advance_id'] as $field) {
            if ($this->integerField($cart, $field) !== 0) {
                throw $this->inconsistent();
            }
        }
        foreach (['sum_price', 'truePrice'] as $field) {
            $this->assertMoneyEquals($this->field($cart, $field), $plan->price());
        }
        $this->assertMoneyEquals($this->field($cart, 'vip_truePrice'), '0.00');
        if ($persisted) {
            $this->assertMoneyEquals($this->field($cart, 'sum_true_price'), $plan->price());
            foreach (['coupon_price', 'integral_price', 'use_integral', 'postage_price'] as $field) {
                $this->assertMoneyEquals($this->field($cart, $field), '0.00');
            }
        }

        $product = $this->field($cart, 'productInfo');
        if (!is_array($product)
            || $this->positiveIntegerField($product, 'id') !== $plan->productId()
            || $this->integerField($product, 'is_virtual') !== 1
            || $this->integerField($product, 'virtual_type') !== self::MEMBERSHIP_VIRTUAL_TYPE
            || $this->integerField($product, 'is_sub') !== 1
            || $this->integerField($product, 'is_del') !== 0
            || $this->integerField($product, 'is_show') !== 1
            || $this->integerField($product, 'presale') !== 0
            || $this->integerField($product, 'is_gift') !== 0
            || $this->integerField($product, 'give_integral') !== 0) {
            throw $this->inconsistent();
        }
        $this->assertMoneyEquals($this->field($product, 'price'), $plan->price());
        $attribute = $this->field($product, 'attrInfo');
        if (!is_array($attribute)
            || $this->positiveIntegerField($attribute, 'product_id') !== $plan->productId()
            || $this->stringField($attribute, 'unique', 20) !== $plan->productAttrUnique()
            || $this->integerField($attribute, 'type') !== 0
            || $this->integerField($attribute, 'is_virtual') !== 1
            || $this->integerField($attribute, 'coupon_id') !== 0) {
            throw $this->inconsistent();
        }
        $this->assertMoneyEquals($this->field($attribute, 'price'), $plan->price());
        $this->assertMoneyEquals($this->field($attribute, 'vip_price'), '0.00');
        $this->assertMoneyEquals($this->field($attribute, 'brokerage'), '0.00');
        $this->assertMoneyEquals($this->field($attribute, 'brokerage_two'), '0.00');
    }

    private function withPersistedCartInfo(array $order): array
    {
        $orderPk = $this->positiveIntegerField($order, 'id');
        /** @var StoreOrderCartInfoDao $cartInfoDao */
        $cartInfoDao = app()->make(StoreOrderCartInfoDao::class);
        $rows = $cartInfoDao->getCartInfoList(
            ['oid' => $orderPk],
            ['oid', 'uid', 'cart_id', 'product_id', 'cart_num', 'cart_info']
        );
        if (!is_array($rows)) {
            throw $this->inconsistent();
        }
        $order['cart_info'] = array_values($rows);

        return $order;
    }

    private function authenticatedUser(array $user): array
    {
        $uid = $this->positiveIntegerField($user, 'uid');
        $userType = $this->stringField($user, 'user_type', 255);
        if (preg_match('/^[A-Za-z0-9_-]+$/D', $userType) !== 1) {
            throw $this->inconsistent();
        }
        foreach (['division_id', 'agent_id', 'staff_id'] as $field) {
            $user[$field] = $this->nonNegativeIntegerField($user, $field);
        }
        $integral = $this->field($user, 'integral');
        if ((!is_int($integral) && !is_float($integral) && !is_string($integral))
            || !is_numeric($integral)
            || (float) $integral < 0) {
            throw $this->inconsistent();
        }
        $user['uid'] = $uid;

        return $user;
    }

    private function orderStatus(
        int $paid,
        int $status,
        int $refundStatus,
        int $isCancel,
        int $isDeleted,
        string $payable,
        string $refunded
    ): string {
        if (!in_array($status, [-2, -1, 0, 1, 2, 3, 4], true)
            || !in_array($refundStatus, [0, 1, 2, 3, 4], true)) {
            throw $this->inconsistent();
        }
        $payableMinor = Money::toMinor($payable);
        $refundedMinor = Money::toMinor($refunded);
        if ($refundedMinor > $payableMinor) {
            throw $this->inconsistent();
        }

        if ($paid === 0) {
            if ($refundStatus !== 0 || $refundedMinor !== 0 || $status !== 0) {
                throw $this->inconsistent();
            }
            return ($isCancel === 1 || $isDeleted === 1) ? 'cancelled' : 'pending_payment';
        }
        if ($isCancel === 1) {
            throw $this->inconsistent();
        }
        if (in_array($refundStatus, [1, 4], true)) {
            return 'refund_pending';
        }
        if ($refundStatus === 3) {
            if ($refundedMinor === 0) {
                return 'refund_pending';
            }
            if ($refundedMinor >= $payableMinor) {
                throw $this->inconsistent();
            }
            return 'partially_refunded';
        }
        if ($refundStatus === 2) {
            if ($refundedMinor !== $payableMinor) {
                throw $this->inconsistent();
            }
            return 'refunded';
        }
        if ($refundedMinor !== 0) {
            throw $this->inconsistent();
        }
        if ($status === 0) {
            return 'paid';
        }
        if (in_array($status, [1, 4], true)) {
            return 'fulfilling';
        }
        if (in_array($status, [2, 3], true)) {
            return 'completed';
        }
        throw $this->inconsistent();
    }

    /**
     * @param mixed $value
     */
    private function snapshot($value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_object($value) && method_exists($value, 'toArray')) {
            $snapshot = $value->toArray();
            if (is_array($snapshot)) {
                return $snapshot;
            }
        }
        throw $this->inconsistent();
    }

    /**
     * @return mixed
     */
    private function field(array $data, string $field)
    {
        if (!array_key_exists($field, $data)) {
            throw $this->inconsistent();
        }
        return $data[$field];
    }

    private function positiveIntegerField(array $data, string $field): int
    {
        $value = $this->integer($this->field($data, $field));
        if ($value < 1) {
            throw $this->inconsistent();
        }
        return $value;
    }

    private function nonNegativeIntegerField(array $data, string $field): int
    {
        $value = $this->integer($this->field($data, $field));
        if ($value < 0) {
            throw $this->inconsistent();
        }
        return $value;
    }

    private function integerField(array $data, string $field): int
    {
        return $this->integer($this->field($data, $field));
    }

    /**
     * @param mixed $value
     */
    private function integer($value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?(?:0|[1-9][0-9]*)$/D', $value) === 1) {
            $parsed = (int) $value;
            if ((string) $parsed === $value) {
                return $parsed;
            }
        }
        throw $this->inconsistent();
    }

    private function binaryField(array $data, string $field): int
    {
        $value = $this->integerField($data, $field);
        if ($value !== 0 && $value !== 1) {
            throw $this->inconsistent();
        }
        return $value;
    }

    private function stringField(array $data, string $field, int $maxLength): string
    {
        $value = $this->field($data, $field);
        if (!is_string($value) || $value === '' || strlen($value) > $maxLength) {
            throw $this->inconsistent();
        }
        return $value;
    }

    /**
     * @param mixed $value
     */
    private function opaqueCrmebKey($value): string
    {
        if (!is_string($value)
            || $value === ''
            || strlen($value) > 32
            || preg_match('/^[A-Za-z0-9]+$/D', $value) !== 1) {
            throw $this->inconsistent();
        }
        return $value;
    }

    private function assertUidAndCheckoutKey(int $uid, string $checkoutKey): void
    {
        if ($uid < 1 || preg_match(self::CHECKOUT_KEY_PATTERN, $checkoutKey) !== 1) {
            throw $this->inconsistent();
        }
    }

    /**
     * @param mixed $value
     */
    private function assertMoneyEquals($value, string $expected): void
    {
        if ($this->money($value) !== $expected) {
            throw $this->inconsistent();
        }
    }

    /**
     * @param mixed $value
     */
    private function money($value): string
    {
        if (is_int($value)) {
            $value = $value . '.00';
        } elseif (is_float($value)) {
            if (!is_finite($value) || $value < 0) {
                throw $this->inconsistent();
            }
            $formatted = number_format($value, 2, '.', '');
            if (abs($value - (float) $formatted) > 0.000001) {
                throw $this->inconsistent();
            }
            $value = $formatted;
        } elseif (is_string($value)
            && preg_match('/^(0|[1-9][0-9]{0,13})(?:\.([0-9]{1,2}))?$/D', $value, $matches) === 1) {
            $fraction = $matches[2] ?? '';
            $value = $matches[1] . '.' . str_pad($fraction, 2, '0');
        }
        try {
            return Money::assertAmount($value, 'CRMEB amount');
        } catch (Throwable $exception) {
            throw $this->inconsistent();
        }
    }

    private function checkoutForm(string $checkoutKey): array
    {
        return [self::CHECKOUT_FORM_FIELD => $checkoutKey];
    }

    /**
     * @param mixed $value
     */
    private function assertCheckoutForm($value, string $checkoutKey): void
    {
        if (is_string($value)) {
            $value = $this->decodeJsonArray($value);
        }
        if (!is_array($value) || $value !== $this->checkoutForm($checkoutKey)) {
            throw $this->inconsistent();
        }
    }

    private function decodeJsonArray(string $json): array
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
            throw $this->inconsistent();
        }
        return $decoded;
    }

    private function inconsistent(): MemberTransactionException
    {
        return new MemberTransactionException(
            503,
            'membership_order_inconsistent',
            'Membership order data is inconsistent'
        );
    }

    private function unavailable(): MemberTransactionException
    {
        return new MemberTransactionException(
            503,
            'membership_order_unavailable',
            'Membership order service is temporarily unavailable'
        );
    }
}
