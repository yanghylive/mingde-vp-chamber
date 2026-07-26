<?php

declare(strict_types=1);

use app\chamber\exceptions\MemberTransactionException;
use app\chamber\membership\MemberTier;
use app\chamber\membership\MembershipPlanSnapshot;
use app\chamber\services\CrmebMembershipOrderGateway;

spl_autoload_register(function (string $class): void {
    $prefix = 'app\\chamber\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = dirname(__DIR__) . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

$tests = [];

$tests['pending virtual membership order normalizes to stable dto'] = function (): void {
    $gateway = new CrmebMembershipOrderGateway();
    $key = checkoutKey();

    assertSame([
        'order_pk' => 701,
        'order_no' => 'cp701000000000000001',
        'order_status' => 'pending_payment',
        'payable_amount' => '1000.00',
        'currency' => 'CNY',
        'payment_required' => true,
    ], $gateway->assertOrderMatches(membershipOrder(), membershipPlan(), 42, $key));
};

$tests['paid fulfillment and completion states use OpenAPI lifecycle values'] = function (): void {
    $gateway = new CrmebMembershipOrderGateway();

    $paid = membershipOrder(['paid' => 1]);
    assertSame('paid', normalizedStatus($gateway, $paid));

    $fulfilling = membershipOrder(['paid' => 1, 'status' => 1]);
    assertSame('fulfilling', normalizedStatus($gateway, $fulfilling));

    $partiallyDelivered = membershipOrder(['paid' => 1, 'status' => 4]);
    assertSame('fulfilling', normalizedStatus($gateway, $partiallyDelivered));

    $completed = membershipOrder(['paid' => 1, 'status' => 3]);
    $normalized = $gateway->assertOrderMatches($completed, membershipPlan(), 42, checkoutKey());
    assertSame('completed', $normalized['order_status']);
    assertSame(false, $normalized['payment_required']);
};

$tests['cancelled spelling and payment flag match the public contract'] = function (): void {
    $gateway = new CrmebMembershipOrderGateway();
    $order = membershipOrder(['is_cancel' => 1, 'is_del' => 1]);
    $normalized = $gateway->assertOrderMatches($order, membershipPlan(), 42, checkoutKey());

    assertSame('cancelled', $normalized['order_status']);
    assertSame(false, $normalized['payment_required']);
};

$tests['refund states are derived only from consistent CRMEB facts'] = function (): void {
    $gateway = new CrmebMembershipOrderGateway();

    assertSame('refund_pending', normalizedStatus($gateway, membershipOrder([
        'paid' => 1,
        'refund_status' => 1,
        'refund_price' => '0.00',
    ])));
    assertSame('refund_pending', normalizedStatus($gateway, membershipOrder([
        'paid' => 1,
        'refund_status' => 3,
        'refund_price' => '0.00',
    ])));
    assertSame('partially_refunded', normalizedStatus($gateway, membershipOrder([
        'paid' => 1,
        'refund_status' => 3,
        'refund_price' => '200.00',
    ])));
    assertSame('refunded', normalizedStatus($gateway, membershipOrder([
        'paid' => 1,
        'status' => -2,
        'refund_status' => 2,
        'refund_price' => '1000.00',
    ])));
};

$tests['checkout identity must match CRMEB globally unique order key'] = function (): void {
    $gateway = new CrmebMembershipOrderGateway();
    $order = membershipOrder(['unique' => str_repeat('b', 32)]);

    assertOrderFailure($gateway, $order);
    expectReason('membership_order_inconsistent', function () use ($gateway): void {
        $gateway->assertOrderMatches(membershipOrder(), membershipPlan(), 42, 'not-a-checkout-key');
    });
};

$tests['order rejects coupons integral postage gifts activities and wrong quantity'] = function (): void {
    $gateway = new CrmebMembershipOrderGateway();
    foreach ([
        ['coupon_id' => 9],
        ['coupon_price' => '1.00'],
        ['use_integral' => '1.00'],
        ['gain_integral' => '1.00'],
        ['deduction_price' => '1.00'],
        ['pay_postage' => '1.00'],
        ['gift_price' => '1.00'],
        ['seckill_id' => 8],
        ['total_num' => 2],
        ['pay_type' => 'yue'],
    ] as $override) {
        assertOrderFailure($gateway, membershipOrder($override));
    }
};

$tests['cart row and cart payload must agree with the immutable plan mapping'] = function (): void {
    $gateway = new CrmebMembershipOrderGateway();

    $wrongRowProduct = membershipOrder();
    $wrongRowProduct['cart_info'][0]['product_id'] = 999;
    assertOrderFailure($gateway, $wrongRowProduct);

    $wrongSku = membershipOrder();
    $wrongSku['cart_info'][0]['cart_info']['product_attr_unique'] = 'badsku01';
    assertOrderFailure($gateway, $wrongSku);

    $wrongNestedProduct = membershipOrder();
    $wrongNestedProduct['cart_info'][0]['cart_info']['productInfo']['id'] = 999;
    assertOrderFailure($gateway, $wrongNestedProduct);

    $physical = membershipOrder();
    $physical['cart_info'][0]['cart_info']['productInfo']['is_virtual'] = 0;
    assertOrderFailure($gateway, $physical);
};

$tests['cart price cannot be discounted or drift from the plan snapshot'] = function (): void {
    $gateway = new CrmebMembershipOrderGateway();
    foreach (['sum_price', 'truePrice', 'sum_true_price'] as $field) {
        $order = membershipOrder();
        $order['cart_info'][0]['cart_info'][$field] = '999.00';
        assertOrderFailure($gateway, $order);
    }

    $order = membershipOrder();
    $order['cart_info'][0]['cart_info']['coupon_price'] = '1.00';
    assertOrderFailure($gateway, $order);
};

$tests['custom form marker is strict supplementary checkout evidence'] = function (): void {
    $gateway = new CrmebMembershipOrderGateway();
    $order = membershipOrder(['custom_form' => []]);
    assertOrderFailure($gateway, $order);

    $encoded = membershipOrder([
        'custom_form' => json_encode(['chamber_membership_checkout_key' => checkoutKey()]),
    ]);
    assertSame('pending_payment', normalizedStatus($gateway, $encoded));
};

$tests['invalid payment and refund combinations fail closed'] = function (): void {
    $gateway = new CrmebMembershipOrderGateway();
    foreach ([
        ['paid' => 0, 'status' => 1],
        ['paid' => 0, 'refund_status' => 1],
        ['paid' => 1, 'is_cancel' => 1],
        ['paid' => 1, 'refund_status' => 3, 'refund_price' => '1000.00'],
        ['paid' => 1, 'refund_status' => 2, 'refund_price' => '999.00'],
        ['paid' => 1, 'refund_status' => 0, 'refund_price' => '1.00'],
        ['paid' => 2],
        ['refund_status' => 9],
    ] as $override) {
        assertOrderFailure($gateway, membershipOrder($override));
    }
};

$tests['inconsistent exception exposes only stable gateway text'] = function (): void {
    $gateway = new CrmebMembershipOrderGateway();
    $order = membershipOrder();
    $order['cart_info'][0]['cart_info'] = '{"database_secret":"leak-me"';

    try {
        $gateway->assertOrderMatches($order, membershipPlan(), 42, checkoutKey());
    } catch (MemberTransactionException $exception) {
        assertSame(503, $exception->httpStatus());
        assertSame('membership_order_inconsistent', $exception->reason());
        assertSame('Membership order data is inconsistent', $exception->getMessage());
        assertSame(false, strpos($exception->getMessage(), 'leak-me') !== false);
        return;
    }
    throw new RuntimeException('Expected MemberTransactionException was not thrown');
};

$failures = 0;
foreach ($tests as $name => $test) {
    try {
        $test();
        fwrite(STDOUT, "PASS {$name}\n");
    } catch (Throwable $exception) {
        $failures++;
        fwrite(STDERR, "FAIL {$name}: {$exception->getMessage()}\n");
    }
}

fwrite(STDOUT, sprintf("%d tests, %d failures\n", count($tests), $failures));
exit($failures === 0 ? 0 : 1);

function checkoutKey(): string
{
    return str_repeat('a', 32);
}

function membershipPlan(array $overrides = []): MembershipPlanSnapshot
{
    return MembershipPlanSnapshot::fromArray(array_merge([
        'id' => 31,
        'tenant_id' => 1,
        'channel_id' => 10,
        'code' => 'annual.l3',
        'version' => 1,
        'name' => 'Annual L3',
        'tier' => MemberTier::L3,
        'purchase_enabled' => true,
        'price' => '1000.00',
        'currency' => 'CNY',
        'term_months' => 12,
        'product_id' => 310,
        'product_attr_unique' => 'c1a30001',
        'benefits' => ['Member events'],
        'renewal_policy' => ['mode' => 'append'],
        'upgrade_policy' => ['proration' => false],
        'refund_policy' => ['partial' => false],
        'status' => 1,
        'effective_time' => 100,
        'end_time' => 0,
    ], $overrides));
}

function membershipOrder(array $overrides = []): array
{
    $key = checkoutKey();
    $cartId = '421234567890123456';
    $cart = [
        'id' => $cartId,
        'type' => 0,
        'seckill_id' => 0,
        'bargain_id' => 0,
        'combination_id' => 0,
        'advance_id' => 0,
        'product_id' => 310,
        'product_attr_unique' => 'c1a30001',
        'cart_num' => 1,
        'sum_price' => '1000.00',
        'truePrice' => '1000.00',
        'vip_truePrice' => 0,
        'sum_true_price' => '1000.00',
        'coupon_price' => 0,
        'integral_price' => 0,
        'use_integral' => 0,
        'postage_price' => 0,
        'productInfo' => [
            'id' => 310,
            'price' => '1000.00',
            'is_virtual' => 1,
            'virtual_type' => 3,
            'is_sub' => 1,
            'is_del' => 0,
            'is_show' => 1,
            'presale' => 0,
            'is_gift' => 0,
            'give_integral' => 0,
            'attrInfo' => [
                'product_id' => 310,
                'unique' => 'c1a30001',
                'type' => 0,
                'is_virtual' => 1,
                'coupon_id' => 0,
                'price' => '1000.00',
                'vip_price' => '0.00',
                'brokerage' => '0.00',
                'brokerage_two' => '0.00',
            ],
        ],
    ];

    return array_merge([
        'id' => 701,
        'pid' => 0,
        'order_id' => 'cp701000000000000001',
        'uid' => 42,
        'cart_id' => [$cartId],
        'total_num' => 1,
        'total_price' => '1000.00',
        'total_postage' => '0.00',
        'pay_price' => '1000.00',
        'pay_type' => 'weixin',
        'pay_postage' => '0.00',
        'deduction_price' => '0.00',
        'coupon_id' => 0,
        'coupon_price' => '0.00',
        'paid' => 0,
        'status' => 0,
        'refund_status' => 0,
        'refund_price' => '0.00',
        'use_integral' => '0.00',
        'gain_integral' => '0.00',
        'is_del' => 0,
        'is_cancel' => 0,
        'unique' => $key,
        'combination_id' => 0,
        'pink_id' => 0,
        'seckill_id' => 0,
        'bargain_id' => 0,
        'advance_id' => 0,
        'shipping_type' => 1,
        'virtual_type' => 3,
        'pay_uid' => 42,
        'custom_form' => ['chamber_membership_checkout_key' => $key],
        'is_gift' => 0,
        'gift_price' => '0.00',
        'one_brokerage' => '0.00',
        'two_brokerage' => '0.00',
        'staff_brokerage' => '0.00',
        'agent_brokerage' => '0.00',
        'division_brokerage' => '0.00',
        'cart_info' => [[
            'oid' => 701,
            'uid' => 42,
            'cart_id' => $cartId,
            'product_id' => 310,
            'cart_num' => 1,
            'cart_info' => $cart,
        ]],
    ], $overrides);
}

function normalizedStatus(CrmebMembershipOrderGateway $gateway, array $order): string
{
    return $gateway->assertOrderMatches($order, membershipPlan(), 42, checkoutKey())['order_status'];
}

function assertOrderFailure(CrmebMembershipOrderGateway $gateway, array $order): void
{
    expectReason('membership_order_inconsistent', function () use ($gateway, $order): void {
        $gateway->assertOrderMatches($order, membershipPlan(), 42, checkoutKey());
    });
}

function expectReason(string $reason, callable $callback): void
{
    try {
        $callback();
    } catch (MemberTransactionException $exception) {
        assertSame($reason, $exception->reason());
        return;
    }
    throw new RuntimeException('Expected MemberTransactionException was not thrown');
}

function assertSame($expected, $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf(
            'Expected %s, got %s',
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
}
