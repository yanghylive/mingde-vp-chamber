<?php

use app\chamber\membership\GraduateVerificationState;
use app\chamber\membership\MemberTier;
use app\chamber\membership\MembershipTermState;
use app\chamber\membership\OrderContextState;

spl_autoload_register(function (string $class): void {
    $prefix = 'app\\chamber\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    require dirname(__DIR__) . '/' . str_replace('\\', '/', $relative) . '.php';
});

$tests = [];

$tests['member tiers expose the frozen vocabulary'] = function (): void {
    assertSame(['L1', 'L2', 'L3', 'L4'], MemberTier::all());
};

$tests['member tier rejects unknown values'] = function (): void {
    expectException(InvalidArgumentException::class, function (): void {
        MemberTier::assertValid('VIP');
    });
};

$tests['member tier rank and minimum checks are ordered'] = function (): void {
    assertSame(4, MemberTier::rank(MemberTier::L4));
    assertSame(true, MemberTier::isAtLeast(MemberTier::L3, MemberTier::L2));
    assertSame(false, MemberTier::isAtLeast(MemberTier::L1, MemberTier::L2));
};

$tests['member tier database ranks round trip without coercion'] = function (): void {
    foreach (MemberTier::all() as $tier) {
        assertSame($tier, MemberTier::fromDatabaseRank(MemberTier::rank($tier)));
    }
    expectException(InvalidArgumentException::class, function (): void {
        MemberTier::fromDatabaseRank('4');
    });
};

$tests['tier projection restores the highest valid paid term after recertification'] = function (): void {
    assertSame(MemberTier::L1, MemberTier::project(false, [MemberTier::L4]));
    assertSame(MemberTier::L4, MemberTier::project(true, [MemberTier::L3, MemberTier::L4]));
    assertSame(MemberTier::L2, MemberTier::project(true, []));
};

$tests['tier projection rejects weakly typed verification evidence'] = function (): void {
    foreach ([1, 0, 'true', 'false'] as $invalid) {
        expectException(InvalidArgumentException::class, function () use ($invalid): void {
            MemberTier::project($invalid, [MemberTier::L4]);
        });
    }
    expectException(InvalidArgumentException::class, function (): void {
        MemberTier::project(true, [MemberTier::L2]);
    });
};

$tests['tier projection may jump to any different evidence-backed result'] = function (): void {
    assertSame(true, MemberTier::canProjectionChange(MemberTier::L1, MemberTier::L4));
    assertSame(true, MemberTier::canProjectionChange(MemberTier::L4, MemberTier::L3));
    assertSame(true, MemberTier::canProjectionChange(MemberTier::L3, MemberTier::L1));
};

$tests['same tier is not a projection change'] = function (): void {
    expectException(InvalidArgumentException::class, function (): void {
        MemberTier::assertProjectionChange(MemberTier::L2, MemberTier::L2);
    });
};

$tests['graduate states expose the frozen vocabulary'] = function (): void {
    assertSame(
        ['draft', 'pending', 'approved', 'returned', 'rejected', 'revoked'],
        GraduateVerificationState::all()
    );
};

$tests['graduate verification database codes round trip strictly'] = function (): void {
    foreach (GraduateVerificationState::all() as $state) {
        assertSame($state, GraduateVerificationState::fromDatabase(
            GraduateVerificationState::toDatabase($state)
        ));
    }
    expectException(InvalidArgumentException::class, function (): void {
        GraduateVerificationState::fromDatabase('2');
    });
};

$tests['graduate draft may only be submitted'] = function (): void {
    assertSame(
        GraduateVerificationState::PENDING,
        GraduateVerificationState::assertTransition(
            GraduateVerificationState::DRAFT,
            GraduateVerificationState::PENDING
        )
    );
    assertSame(false, GraduateVerificationState::canTransition(
        GraduateVerificationState::DRAFT,
        GraduateVerificationState::APPROVED
    ));
};

$tests['pending graduate application supports every review outcome'] = function (): void {
    foreach ([
        GraduateVerificationState::APPROVED,
        GraduateVerificationState::RETURNED,
        GraduateVerificationState::REJECTED,
    ] as $outcome) {
        assertSame(true, GraduateVerificationState::canTransition(GraduateVerificationState::PENDING, $outcome));
    }
};

$tests['returned application is immutable and resubmission requires a new row'] = function (): void {
    assertSame(true, GraduateVerificationState::isTerminal(GraduateVerificationState::RETURNED));
    assertSame(false, GraduateVerificationState::canTransition(
        GraduateVerificationState::RETURNED,
        GraduateVerificationState::PENDING
    ));
};

$tests['approved graduate status may be revoked'] = function (): void {
    assertSame(
        GraduateVerificationState::REVOKED,
        GraduateVerificationState::assertTransition(
            GraduateVerificationState::APPROVED,
            GraduateVerificationState::REVOKED
        )
    );
};

$tests['pending graduate application cannot move back to draft'] = function (): void {
    expectException(InvalidArgumentException::class, function (): void {
        GraduateVerificationState::assertTransition(
            GraduateVerificationState::PENDING,
            GraduateVerificationState::DRAFT
        );
    });
};

$tests['term states store facts rather than computed expiry'] = function (): void {
    assertSame([1, 2, 3], MembershipTermState::all());
};

$tests['granted term may be revoked or fully refunded'] = function (): void {
    assertSame(true, MembershipTermState::canTransition(
        MembershipTermState::GRANTED,
        MembershipTermState::REVOKED
    ));
    assertSame(true, MembershipTermState::canTransition(
        MembershipTermState::GRANTED,
        MembershipTermState::FULLY_REFUNDED
    ));
};

$tests['terminal term facts cannot be revived'] = function (): void {
    assertSame(false, MembershipTermState::canTransition(
        MembershipTermState::REVOKED,
        MembershipTermState::GRANTED
    ));
    assertSame(false, MembershipTermState::canTransition(
        MembershipTermState::FULLY_REFUNDED,
        MembershipTermState::GRANTED
    ));
};

$tests['granted term before start is scheduled'] = function (): void {
    assertSame('scheduled', MembershipTermState::effectiveStatus(
        MembershipTermState::GRANTED,
        100,
        200,
        99
    ));
};

$tests['term uses a half-open active interval'] = function (): void {
    assertSame('active', MembershipTermState::effectiveStatus(MembershipTermState::GRANTED, 100, 200, 100));
    assertSame('active', MembershipTermState::effectiveStatus(MembershipTermState::GRANTED, 100, 200, 199));
    assertSame('expired', MembershipTermState::effectiveStatus(MembershipTermState::GRANTED, 100, 200, 200));
};

$tests['revoked and refunded facts override the clock'] = function (): void {
    assertSame('revoked', MembershipTermState::effectiveStatus(MembershipTermState::REVOKED, 100, 200, 150));
    assertSame('refunded', MembershipTermState::effectiveStatus(
        MembershipTermState::FULLY_REFUNDED,
        100,
        200,
        150
    ));
};

$tests['invalid term interval is rejected'] = function (): void {
    expectException(InvalidArgumentException::class, function (): void {
        MembershipTermState::effectiveStatus(MembershipTermState::GRANTED, 200, 200, 200);
    });
};

$tests['order context exposes three independent lifecycle dimensions'] = function (): void {
    assertSame([0, 1, 2, 3], OrderContextState::allPayStatuses());
    assertSame([0, 1, 2, 3, 4, 5, 6], OrderContextState::allRefundStatuses());
    assertSame(['pending', 'paid', 'zero_amount'], OrderContextState::allCompletionKinds());
};

$tests['pending order may complete cancel or close'] = function (): void {
    foreach ([
        OrderContextState::PAY_COMPLETED,
        OrderContextState::PAY_CANCELLED,
        OrderContextState::PAY_CLOSED,
    ] as $status) {
        assertSame(true, OrderContextState::canPayTransition(OrderContextState::PAY_PENDING, $status));
    }
};

$tests['completed payment is terminal'] = function (): void {
    assertSame(false, OrderContextState::canPayTransition(
        OrderContextState::PAY_COMPLETED,
        OrderContextState::PAY_CANCELLED
    ));
};

$tests['refund follows request processing partial and completion'] = function (): void {
    assertSame(OrderContextState::REFUND_REQUESTED, OrderContextState::assertRefundTransition(
        OrderContextState::REFUND_NONE,
        OrderContextState::REFUND_REQUESTED
    ));
    assertSame(OrderContextState::REFUND_PROCESSING, OrderContextState::assertRefundTransition(
        OrderContextState::REFUND_REQUESTED,
        OrderContextState::REFUND_PROCESSING
    ));
    assertSame(OrderContextState::REFUND_PARTIALLY_COMPLETED, OrderContextState::assertRefundTransition(
        OrderContextState::REFUND_PROCESSING,
        OrderContextState::REFUND_PARTIALLY_COMPLETED
    ));
    assertSame(OrderContextState::REFUND_COMPLETED, OrderContextState::assertRefundTransition(
        OrderContextState::REFUND_PARTIALLY_COMPLETED,
        OrderContextState::REFUND_COMPLETED
    ));
};

$tests['first trusted refund completion may be partial directly after request'] = function (): void {
    assertSame(true, OrderContextState::canRefundTransition(
        OrderContextState::REFUND_REQUESTED,
        OrderContextState::REFUND_PARTIALLY_COMPLETED
    ));
};

$tests['failed or cancelled refund may start a new request'] = function (): void {
    assertSame(true, OrderContextState::canRefundTransition(
        OrderContextState::REFUND_FAILED,
        OrderContextState::REFUND_REQUESTED
    ));
    assertSame(true, OrderContextState::canRefundTransition(
        OrderContextState::REFUND_CANCELLED,
        OrderContextState::REFUND_REQUESTED
    ));
};

$tests['trusted late refund evidence may supersede local cancel or failure'] = function (): void {
    foreach ([OrderContextState::REFUND_CANCELLED, OrderContextState::REFUND_FAILED] as $from) {
        assertSame(true, OrderContextState::canRefundTransition($from, OrderContextState::REFUND_PROCESSING));
        assertSame(true, OrderContextState::canRefundTransition(
            $from,
            OrderContextState::REFUND_PARTIALLY_COMPLETED
        ));
        assertSame(true, OrderContextState::canRefundTransition($from, OrderContextState::REFUND_COMPLETED));
    }
};

$tests['a partially completed order may start another refund request'] = function (): void {
    assertSame(true, OrderContextState::canRefundTransition(
        OrderContextState::REFUND_PARTIALLY_COMPLETED,
        OrderContextState::REFUND_REQUESTED
    ));
};

$tests['refund cannot complete without a request'] = function (): void {
    expectException(InvalidArgumentException::class, function (): void {
        OrderContextState::assertRefundTransition(
            OrderContextState::REFUND_NONE,
            OrderContextState::REFUND_COMPLETED
        );
    });
};

$tests['paid completion snapshot accepts paid and zero amount evidence'] = function (): void {
    OrderContextState::assertPaymentSnapshot(OrderContextState::PAY_COMPLETED, OrderContextState::COMPLETION_PAID);
    OrderContextState::assertPaymentSnapshot(
        OrderContextState::PAY_COMPLETED,
        OrderContextState::COMPLETION_ZERO_AMOUNT
    );
    assertSame(true, true);
};

$tests['pending and terminal unpaid snapshots require pending completion kind'] = function (): void {
    OrderContextState::assertPaymentSnapshot(OrderContextState::PAY_PENDING, OrderContextState::COMPLETION_PENDING);
    OrderContextState::assertPaymentSnapshot(OrderContextState::PAY_CANCELLED, OrderContextState::COMPLETION_PENDING);
    OrderContextState::assertPaymentSnapshot(OrderContextState::PAY_CLOSED, OrderContextState::COMPLETION_PENDING);
    assertSame(true, true);
};

$tests['incoherent payment snapshot is rejected'] = function (): void {
    expectException(InvalidArgumentException::class, function (): void {
        OrderContextState::assertPaymentSnapshot(
            OrderContextState::PAY_PENDING,
            OrderContextState::COMPLETION_PAID
        );
    });
};

$tests['combined order snapshot accepts coherent paid zero and refund states'] = function (): void {
    OrderContextState::assertSnapshot(
        OrderContextState::PAY_COMPLETED,
        OrderContextState::COMPLETION_PAID,
        OrderContextState::REFUND_NONE,
        '1000.00',
        '0.00'
    );
    OrderContextState::assertSnapshot(
        OrderContextState::PAY_COMPLETED,
        OrderContextState::COMPLETION_PAID,
        OrderContextState::REFUND_PARTIALLY_COMPLETED,
        '1000.00',
        '200.00'
    );
    OrderContextState::assertSnapshot(
        OrderContextState::PAY_COMPLETED,
        OrderContextState::COMPLETION_ZERO_AMOUNT,
        OrderContextState::REFUND_NONE,
        '0.00',
        '0.00'
    );
    assertSame(true, true);
};

$tests['combined order snapshot rejects impossible money and lifecycle combinations'] = function (): void {
    $invalid = [
        [OrderContextState::PAY_PENDING, OrderContextState::COMPLETION_PENDING, OrderContextState::REFUND_REQUESTED, '0.00', '0.00'],
        [OrderContextState::PAY_COMPLETED, OrderContextState::COMPLETION_ZERO_AMOUNT, OrderContextState::REFUND_COMPLETED, '0.00', '0.00'],
        [OrderContextState::PAY_COMPLETED, OrderContextState::COMPLETION_PAID, OrderContextState::REFUND_PARTIALLY_COMPLETED, '100.00', '100.00'],
        [OrderContextState::PAY_COMPLETED, OrderContextState::COMPLETION_PAID, OrderContextState::REFUND_COMPLETED, '100.00', '101.00'],
        [OrderContextState::PAY_COMPLETED, OrderContextState::COMPLETION_PAID, OrderContextState::REFUND_REQUESTED, '100.00', '100.00'],
        [OrderContextState::PAY_COMPLETED, OrderContextState::COMPLETION_PAID, OrderContextState::REFUND_FAILED, '100.00', '100.00'],
    ];
    foreach ($invalid as $snapshot) {
        expectException(InvalidArgumentException::class, function () use ($snapshot): void {
            OrderContextState::assertSnapshot(...$snapshot);
        });
    }
};

$tests['state helpers reject weakly typed database values'] = function (): void {
    expectException(InvalidArgumentException::class, function (): void {
        MembershipTermState::assertValid('1');
    });
    expectException(InvalidArgumentException::class, function (): void {
        OrderContextState::assertPayStatus('1');
    });
    expectException(InvalidArgumentException::class, function (): void {
        OrderContextState::assertRefundStatus('3');
    });
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
