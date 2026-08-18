<?php

declare(strict_types=1);

use app\chamber\membership\MembershipCheckoutIdempotency;
use app\chamber\membership\MembershipCheckoutRequest;
use app\chamber\membership\MembershipPlanSnapshot;
use app\chamber\membership\MembershipPurchasePolicy;
use app\chamber\membership\MemberTier;

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

$tests['checkout request accepts the exact public contract and canonicalizes field order'] = function (): void {
    $request = MembershipCheckoutRequest::fromArray([
        'currency' => 'CNY',
        'expected_amount' => '1000.00',
        'plan_version' => 3,
        'plan_code' => 'annual.l3',
    ]);

    assertSame([
        'currency' => 'CNY',
        'expected_amount' => '1000.00',
        'plan_code' => 'annual.l3',
        'plan_version' => 3,
    ], $request->toCanonicalArray());
};

$tests['checkout request rejects missing unknown and weakly typed fields'] = function (): void {
    expectException(InvalidArgumentException::class, function (): void {
        MembershipCheckoutRequest::fromArray([
            'plan_code' => 'annual.l3',
            'plan_version' => 1,
            'expected_amount' => '1000.00',
        ]);
    });
    expectException(InvalidArgumentException::class, function (): void {
        MembershipCheckoutRequest::fromArray([
            'plan_code' => 'annual.l3',
            'plan_version' => 1,
            'expected_amount' => '1000.00',
            'currency' => 'CNY',
            'uid' => 42,
        ]);
    });
    expectException(InvalidArgumentException::class, function (): void {
        MembershipCheckoutRequest::fromArray([
            'plan_code' => 'annual.l3',
            'plan_version' => '1',
            'expected_amount' => 1000,
            'currency' => 'cny',
        ]);
    });
};

$tests['checkout request enforces identifiers money and currency boundaries'] = function (): void {
    foreach (['', '-annual', str_repeat('a', 33), 'annual/l3'] as $code) {
        expectException(InvalidArgumentException::class, function () use ($code): void {
            checkoutRequest(['plan_code' => $code]);
        });
    }
    foreach (['1000', '1000.0', '01.00', '-1.00'] as $amount) {
        expectException(InvalidArgumentException::class, function () use ($amount): void {
            checkoutRequest(['expected_amount' => $amount]);
        });
    }
    foreach (['CN', 'CNYY', 'cny', 123] as $currency) {
        expectException(InvalidArgumentException::class, function () use ($currency): void {
            checkoutRequest(['currency' => $currency]);
        });
    }
};

$tests['plan snapshot preserves public and immutable commerce facts'] = function (): void {
    $plan = membershipPlan();
    $public = $plan->toPublicArray(true, null);

    assertSame('annual.l3', $public['code']);
    assertSame('L3', $public['tier']);
    assertSame(1, $public['duration_value']);
    assertSame('year', $public['duration_unit']);
    assertSame(false, array_key_exists('product_id', $public));
    assertSame(false, array_key_exists('product_attr_unique', $public));
    assertSame([
        'currency' => 'CNY',
        'list_amount' => '1000.00',
        'payable_amount' => '1000.00',
        'plan_code' => 'annual.l3',
        'plan_version' => 1,
    ], $plan->priceSnapshot());
    assertSame(310, $plan->productId());
    assertSame('sku-l3', $plan->productAttrUnique());
};

$tests['plan snapshot rejects incomplete internal mappings and unsupported tiers'] = function (): void {
    expectException(InvalidArgumentException::class, function (): void {
        membershipPlan(['product_id' => 0]);
    });
    expectException(InvalidArgumentException::class, function (): void {
        membershipPlan(['product_attr_unique' => '']);
    });
    expectException(InvalidArgumentException::class, function (): void {
        membershipPlan(['tier' => MemberTier::L1]);
    });
    expectException(InvalidArgumentException::class, function (): void {
        membershipPlan(['tier' => MemberTier::L4]);
    });
};

$tests['plan snapshot enforces yearly intervals and strict scalar types'] = function (): void {
    expectException(InvalidArgumentException::class, function (): void {
        membershipPlan(['term_months' => 6]);
    });
    expectException(InvalidArgumentException::class, function (): void {
        membershipPlan(['purchase_enabled' => 1]);
    });
    expectException(InvalidArgumentException::class, function (): void {
        membershipPlan(['effective_time' => '100']);
    });
    expectException(InvalidArgumentException::class, function (): void {
        membershipPlan(['effective_time' => 200, 'end_time' => 200]);
    });
    expectException(InvalidArgumentException::class, function (): void {
        membershipPlan(['price' => '0.00']);
    });
    assertSame([], membershipPlan(['refund_policy' => []])->refundPolicySnapshot());
};

$tests['availability uses a half-open configured interval'] = function (): void {
    $plan = membershipPlan(['effective_time' => 100, 'end_time' => 200]);

    assertSame(false, $plan->isAvailableAt(99));
    assertSame(true, $plan->isAvailableAt(100));
    assertSame(true, $plan->isAvailableAt(199));
    assertSame(false, $plan->isAvailableAt(200));
    assertSame(false, membershipPlan(['purchase_enabled' => false])->isAvailableAt(150));
    assertSame(false, membershipPlan(['status' => 2])->isAvailableAt(150));
};

$tests['purchase permits L2 and L3 purchases and tier upgrades'] = function (): void {
    // L1（初始档）可买 L2、L3（升级）
    assertSame(true, MembershipPurchasePolicy::isEligible(
        membershipPlan(['tier' => MemberTier::L2, 'code' => 'annual.l2', 'price' => '1000.00']),
        MemberTier::L1,
        150
    ));
    assertSame(true, MembershipPurchasePolicy::isEligible(
        membershipPlan(),
        MemberTier::L1,
        150
    ));
    // L2 可买 L2（续费）和 L3（升级）
    assertSame(true, MembershipPurchasePolicy::isEligible(
        membershipPlan(['tier' => MemberTier::L2, 'code' => 'annual.l2', 'price' => '1000.00']),
        MemberTier::L2,
        150
    ));
    assertSame(true, MembershipPurchasePolicy::isEligible(
        membershipPlan(),
        MemberTier::L2,
        150
    ));
    // L3 可买 L3（续费）
    assertSame(true, MembershipPurchasePolicy::isEligible(
        membershipPlan(),
        MemberTier::L3,
        150
    ));
};

$tests['purchase rejects downgrades (a member buying a lower tier)'] = function (): void {
    // L4 不能买 L2、L3（降级）
    assertSame(
        MembershipPurchasePolicy::DOWNGRADE_NOT_ALLOWED,
        MembershipPurchasePolicy::ineligibleReason(
            membershipPlan(['tier' => MemberTier::L2, 'code' => 'annual.l2', 'price' => '1000.00']),
            MemberTier::L4,
            150
        )
    );
    assertSame(
        MembershipPurchasePolicy::DOWNGRADE_NOT_ALLOWED,
        MembershipPurchasePolicy::ineligibleReason(membershipPlan(), MemberTier::L4, 150)
    );
    // L3 不能买 L2（降级）
    assertSame(
        MembershipPurchasePolicy::DOWNGRADE_NOT_ALLOWED,
        MembershipPurchasePolicy::ineligibleReason(
            membershipPlan(['tier' => MemberTier::L2, 'code' => 'annual.l2', 'price' => '1000.00']),
            MemberTier::L3,
            150
        )
    );
};

$tests['unavailable plan fails before member purchase rules'] = function (): void {
    assertSame(
        MembershipPurchasePolicy::PLAN_UNAVAILABLE,
        MembershipPurchasePolicy::ineligibleReason(
            membershipPlan(['purchase_enabled' => false]),
            MemberTier::L1,
            150
        )
    );
};

$tests['purchase policy rejects invalid tier and time evidence'] = function (): void {
    expectException(InvalidArgumentException::class, function (): void {
        MembershipPurchasePolicy::ineligibleReason(membershipPlan(), MemberTier::L2, 0);
    });
    expectException(InvalidArgumentException::class, function (): void {
        MembershipPurchasePolicy::ineligibleReason(membershipPlan(), 'VIP', 150);
    });
};

$tests['checkout must match the selected plan code version amount and currency exactly'] = function (): void {
    $plan = membershipPlan();
    MembershipPurchasePolicy::assertRequestMatchesPlan(checkoutRequest(), $plan);

    foreach ([
        ['plan_code' => 'annual.l4'],
        ['plan_version' => 2],
        ['expected_amount' => '999.00'],
        ['currency' => 'USD'],
    ] as $override) {
        expectException(InvalidArgumentException::class, function () use ($override, $plan): void {
            MembershipPurchasePolicy::assertRequestMatchesPlan(checkoutRequest($override), $plan);
        });
    }
};

$tests['checkout idempotency key scopes caller key by tenant operation and authenticated user'] = function (): void {
    $callerKey = 'checkout-client-key-0001';
    $base = MembershipCheckoutIdempotency::deriveInternalKey(1, 42, $callerKey);

    assertSame(74, strlen($base));
    assertSame('sha256-v1:', substr($base, 0, 10));
    assertNotSame($base, MembershipCheckoutIdempotency::deriveInternalKey(2, 42, $callerKey));
    assertNotSame($base, MembershipCheckoutIdempotency::deriveInternalKey(1, 43, $callerKey));
};

$tests['checkout request hash is stable and scoped by trusted channel'] = function (): void {
    $left = MembershipCheckoutRequest::fromArray([
        'plan_code' => 'annual.l3',
        'plan_version' => 1,
        'expected_amount' => '1000.00',
        'currency' => 'CNY',
    ]);
    $right = MembershipCheckoutRequest::fromArray([
        'currency' => 'CNY',
        'expected_amount' => '1000.00',
        'plan_version' => 1,
        'plan_code' => 'annual.l3',
    ]);
    $base = MembershipCheckoutIdempotency::requestHash(10, $left);

    assertSame($base, MembershipCheckoutIdempotency::requestHash(10, $right));
    assertNotSame($base, MembershipCheckoutIdempotency::requestHash(11, $right));
    assertNotSame($base, MembershipCheckoutIdempotency::requestHash(
        10,
        checkoutRequest(['plan_version' => 2])
    ));
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
        'product_attr_unique' => 'sku-l3',
        'benefits' => ['Member events', 'Directory access'],
        'renewal_policy' => ['mode' => 'append'],
        'upgrade_policy' => ['proration' => false],
        'refund_policy' => ['partial' => false],
        'status' => 1,
        'effective_time' => 100,
        'end_time' => 0,
    ], $overrides));
}

function checkoutRequest(array $overrides = []): MembershipCheckoutRequest
{
    return MembershipCheckoutRequest::fromArray(array_merge([
        'plan_code' => 'annual.l3',
        'plan_version' => 1,
        'expected_amount' => '1000.00',
        'currency' => 'CNY',
    ], $overrides));
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

function assertNotSame($unexpected, $actual): void
{
    if ($unexpected === $actual) {
        throw new RuntimeException(sprintf('Did not expect %s', var_export($actual, true)));
    }
}

function expectException(string $class, callable $callback): void
{
    try {
        $callback();
    } catch (Throwable $exception) {
        if ($exception instanceof $class) {
            return;
        }
        throw $exception;
    }

    throw new RuntimeException("Expected exception {$class}");
}
