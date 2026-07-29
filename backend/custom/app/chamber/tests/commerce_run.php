<?php

use app\chamber\commerce\CommerceEvent;
use app\chamber\commerce\CommerceEventReceipt;
use app\chamber\commerce\CommerceEventType;
use app\chamber\commerce\RefundAttemptState;
use app\chamber\commerce\RefundLifecycle;
use app\chamber\exceptions\CommerceEventConflictException;
use app\chamber\services\CommerceEventRecorder;
use app\chamber\services\InMemoryCommerceEventStore;
use app\chamber\services\ThinkDbCommerceEventStore;

spl_autoload_register(function (string $class): void {
    $prefix = 'app\\chamber\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    require dirname(__DIR__) . '/' . str_replace('\\', '/', $relative) . '.php';
});

$tests = [];

$tests['event vocabulary is frozen at v1'] = function (): void {
    assertSame([
        'commerce.order.completed.v1',
        'commerce.refund.requested.v1',
        'commerce.refund.cancelled.v1',
        'commerce.refund.processing.v1',
        'commerce.refund.completed.v1',
        'commerce.refund.failed.v1',
    ], [
        CommerceEventType::ORDER_COMPLETED,
        CommerceEventType::REFUND_REQUESTED,
        CommerceEventType::REFUND_CANCELLED,
        CommerceEventType::REFUND_PROCESSING,
        CommerceEventType::REFUND_COMPLETED,
        CommerceEventType::REFUND_FAILED,
    ]);
};

$tests['refund attempts preserve unknown outcomes for provider query'] = function (): void {
    assertSame(RefundAttemptState::PROCESSING, RefundAttemptState::assertTransition(
        RefundAttemptState::REQUESTED,
        RefundAttemptState::PROCESSING
    ));
    assertSame(RefundAttemptState::UNKNOWN, RefundAttemptState::assertTransition(
        RefundAttemptState::PROCESSING,
        RefundAttemptState::UNKNOWN
    ));
    assertSame(true, RefundAttemptState::shouldQuery(RefundAttemptState::UNKNOWN));
    assertSame(true, RefundAttemptState::shouldQuery(RefundAttemptState::PROCESSING));
    assertSame(false, RefundAttemptState::shouldQuery(RefundAttemptState::REQUESTED));
};

$tests['refund finality requires a trusted source and remains terminal'] = function (): void {
    RefundAttemptState::assertFinalConfirmation(
        RefundAttemptState::SUCCEEDED,
        RefundAttemptState::SOURCE_PROVIDER_QUERY
    );
    RefundAttemptState::assertFinalConfirmation(
        RefundAttemptState::SUCCEEDED,
        RefundAttemptState::SOURCE_BALANCE
    );
    RefundAttemptState::assertFinalConfirmation(
        RefundAttemptState::MANUAL,
        RefundAttemptState::SOURCE_MANUAL
    );
    assertSame(true, RefundAttemptState::isFinal(RefundAttemptState::SUCCEEDED));
    assertSame(true, RefundAttemptState::isFinal(RefundAttemptState::FAILED));
    assertSame(true, RefundAttemptState::isFinal(RefundAttemptState::MANUAL));
    assertSame(RefundAttemptState::SUCCEEDED, RefundAttemptState::assertTransition(
        RefundAttemptState::SUCCEEDED,
        RefundAttemptState::SUCCEEDED
    ));

    expectException(InvalidArgumentException::class, function (): void {
        RefundAttemptState::assertFinalConfirmation(
            RefundAttemptState::SUCCEEDED,
            'provider_accepted'
        );
    });
    expectException(InvalidArgumentException::class, function (): void {
        RefundAttemptState::assertTransition(
            RefundAttemptState::SUCCEEDED,
            RefundAttemptState::PROCESSING
        );
    });
    expectException(InvalidArgumentException::class, function (): void {
        RefundAttemptState::assertTransition(
            RefundAttemptState::FAILED,
            RefundAttemptState::REQUESTED
        );
    });
};

$tests['event identity and payload hash are deterministic'] = function (): void {
    $payload = orderPayload();
    $first = CommerceEvent::fromArray($payload);
    $second = CommerceEvent::fromArray(array_reverse($payload, true));

    assertSame($first->eventId(), $second->eventId());
    assertSame($first->payloadHash(), $second->payloadHash());
    assertSame(64, strlen($first->eventId()));
    assertSame(64, strlen($first->payloadHash()));
    assertSame(true, (bool) preg_match('/^[a-f0-9]{64}$/', $first->eventId()));
};

$tests['same payment callback repeated ten times is recorded once'] = function (): void {
    $store = new InMemoryCommerceEventStore();
    $recorder = new CommerceEventRecorder($store);
    $event = CommerceEvent::fromArray(orderPayload());

    for ($attempt = 0; $attempt < 10; $attempt++) {
        $receipt = $recorder->record($event);
        assertSame($attempt > 0, $receipt->isDuplicate());
    }

    assertSame(1, $store->count());
    assertSame($event->eventId(), $store->events()[0]->eventId());
};

$tests['event identity and storage isolation include tenant'] = function (): void {
    $store = new InMemoryCommerceEventStore();
    $firstTenant = CommerceEvent::fromArray(orderPayload());
    $secondTenant = CommerceEvent::fromArray(orderPayload(['tenant_id' => 2]));

    assertNotSame($firstTenant->eventId(), $secondTenant->eventId());
    assertSame(CommerceEventReceipt::RECORDED, $store->record($firstTenant)->outcome());
    assertSame(CommerceEventReceipt::RECORDED, $store->record($secondTenant)->outcome());
    assertSame(2, $store->count());
};

$tests['same source identity with another payload is a conflict'] = function (): void {
    $store = new InMemoryCommerceEventStore();
    $recorder = new CommerceEventRecorder($store);
    $first = CommerceEvent::fromArray(orderPayload());
    $changed = CommerceEvent::fromArray(orderPayload(['paid_amount' => '1001.00']));

    assertSame($first->eventId(), $changed->eventId());
    assertNotSame($first->payloadHash(), $changed->payloadHash());
    $recorder->record($first);

    expectException(CommerceEventConflictException::class, function () use ($recorder, $changed): void {
        $recorder->record($changed);
    });
    assertSame(1, $store->count());
};

$tests['money is a strict non-negative two-decimal string'] = function (): void {
    foreach (['1000', '1000.0', '-1.00', '01.00', '1.000'] as $invalid) {
        expectException(InvalidArgumentException::class, function () use ($invalid): void {
            CommerceEvent::fromArray(orderPayload(['paid_amount' => $invalid]));
        });
    }
    expectException(InvalidArgumentException::class, function (): void {
        CommerceEvent::fromArray(orderPayload(['paid_amount' => 1000.00]));
    });
};

$tests['PII and unknown payload extensions are rejected'] = function (): void {
    foreach (['real_name', 'user_phone', 'user_address', 'email', 'openid'] as $field) {
        expectException(InvalidArgumentException::class, function () use ($field): void {
            CommerceEvent::fromArray(orderPayload([$field => 'sensitive-value']));
        });
    }
    expectException(InvalidArgumentException::class, function (): void {
        CommerceEvent::fromArray(orderPayload(['metadata' => ['phone' => '13800000000']]));
    });
    expectException(InvalidArgumentException::class, function (): void {
        CommerceEvent::fromArray(orderPayload(['correlation_id' => 'short']));
    });
    expectException(InvalidArgumentException::class, function (): void {
        CommerceEvent::fromArray(orderPayload(['refund_pk' => 501]));
    });
    expectException(InvalidArgumentException::class, function (): void {
        CommerceEvent::fromArray(refundPayload(CommerceEventType::REFUND_REQUESTED, [
            'completion_kind' => 'paid',
        ]));
    });
};

$tests['zero amount order uses the same completed event with an explicit kind'] = function (): void {
    $event = CommerceEvent::fromArray(orderPayload([
        'source_event_id' => 'store_order:101:zero_completed',
        'paid_amount' => '0.00',
        'completion_kind' => 'zero_amount',
        'pay_type' => 'none',
        'trade_no' => '',
    ]));
    assertSame('zero_amount', $event->payload()['completion_kind']);

    expectException(InvalidArgumentException::class, function (): void {
        CommerceEvent::fromArray(orderPayload([
            'source_event_id' => 'store_order:101:bad_zero',
            'paid_amount' => '0.00',
            'completion_kind' => 'paid',
        ]));
    });
    expectException(InvalidArgumentException::class, function (): void {
        CommerceEvent::fromArray(refundPayload(
            CommerceEventType::REFUND_REQUESTED,
            ['paid_amount' => '0.00']
        ));
    });
};

$tests['CRMEB accepted status remains processing until trusted completion'] = function (): void {
    $lifecycle = new RefundLifecycle(1, 101, 501, '1000.00');
    $lifecycle = $lifecycle->apply(CommerceEvent::fromArray(refundPayload(CommerceEventType::REFUND_REQUESTED)));
    $lifecycle = $lifecycle->apply(CommerceEvent::fromArray(refundPayload(
        CommerceEventType::REFUND_PROCESSING,
        ['completion_source' => 'crmeb_refund_status', 'provider_status' => 'accepted']
    )));
    assertSame(RefundLifecycle::PROCESSING, $lifecycle->status());
    assertSame('0.00', $lifecycle->cumulativeAmount());

    expectException(InvalidArgumentException::class, function (): void {
        CommerceEvent::fromArray(refundPayload(CommerceEventType::REFUND_COMPLETED, [
            'refund_delta' => '1000.00',
            'cumulative_refunded_amount' => '1000.00',
            'completion_id' => 'crmeb-accepted-is-not-final',
            'completion_source' => 'provider_accepted',
            'provider_status' => 'accepted',
        ]));
    });

    $completed = CommerceEvent::fromArray(refundPayload(CommerceEventType::REFUND_COMPLETED, [
        'refund_delta' => '1000.00',
        'cumulative_refunded_amount' => '1000.00',
        'completion_id' => 'wechat-refund-501-success',
        'completion_source' => 'provider_query_success',
        'provider_status' => 'success',
        'provider_refund_no' => 'wx-refund-501',
    ]));
    $lifecycle = $lifecycle->apply($completed);
    assertSame(RefundLifecycle::COMPLETED, $lifecycle->status());

    $duplicate = $lifecycle->apply($completed);
    assertSame(RefundLifecycle::COMPLETED, $duplicate->status());
    assertSame('1000.00', $duplicate->cumulativeAmount());

    $reobserved = CommerceEvent::fromArray(refundPayload(CommerceEventType::REFUND_COMPLETED, [
        'source_event_id' => 'refund:501:completed:reobserved',
        'occurred_at' => 1784625660,
        'correlation_id' => 'correlation-commerce-reconcile-0002',
        'refund_delta' => '1000.00',
        'cumulative_refunded_amount' => '1000.00',
        'completion_id' => 'wechat-refund-501-success',
        'completion_source' => 'provider_query_success',
        'provider_status' => 'success',
        'provider_refund_no' => 'wx-refund-501',
    ]));
    assertNotSame($completed->payloadHash(), $reobserved->payloadHash());
    assertSame($completed->completionFingerprint(), $reobserved->completionFingerprint());
    assertSame(RefundLifecycle::COMPLETED, $lifecycle->apply($reobserved)->status());

    $conflictingCompletion = CommerceEvent::fromArray(refundPayload(CommerceEventType::REFUND_COMPLETED, [
        'source_event_id' => 'refund:501:completed:conflict',
        'refund_delta' => '999.00',
        'cumulative_refunded_amount' => '999.00',
        'completion_id' => 'wechat-refund-501-success',
        'completion_source' => 'provider_query_success',
        'provider_status' => 'success',
        'provider_refund_no' => 'wx-refund-501',
    ]));
    expectException(CommerceEventConflictException::class, function () use ($lifecycle, $conflictingCompletion): void {
        $lifecycle->apply($conflictingCompletion);
    });
};

$tests['balance transaction and manual finance confirmation are trusted completion sources'] = function (): void {
    foreach (['balance_transaction', 'manual_finance_confirm'] as $source) {
        $event = CommerceEvent::fromArray(refundPayload(CommerceEventType::REFUND_COMPLETED, [
            'source_event_id' => 'refund:501:completed:' . $source,
            'refund_delta' => '1000.00',
            'cumulative_refunded_amount' => '1000.00',
            'completion_id' => 'completion-' . $source,
            'completion_source' => $source,
            'provider_status' => $source === 'balance_transaction' ? 'committed' : 'confirmed',
        ]));
        assertSame('completed', $event->refundStatus());
    }
};

$tests['refund request may retry after failure or cancellation without changing completed amount'] = function (): void {
    $lifecycle = new RefundLifecycle(1, 101, 501, '1000.00');
    $lifecycle = $lifecycle->apply(CommerceEvent::fromArray(refundPayload(CommerceEventType::REFUND_REQUESTED)));
    $lifecycle = $lifecycle->apply(CommerceEvent::fromArray(refundPayload(
        CommerceEventType::REFUND_FAILED,
        ['source_event_id' => 'refund:501:failed', 'provider_status' => 'closed']
    )));
    assertSame(RefundLifecycle::FAILED, $lifecycle->status());
    $lifecycle = $lifecycle->apply(CommerceEvent::fromArray(refundPayload(
        CommerceEventType::REFUND_REQUESTED,
        ['source_event_id' => 'refund:501:requested:retry-after-failure']
    )));
    assertSame(RefundLifecycle::REQUESTED, $lifecycle->status());
    $lifecycle = $lifecycle->apply(CommerceEvent::fromArray(refundPayload(
        CommerceEventType::REFUND_FAILED,
        ['source_event_id' => 'refund:501:failed:retry', 'provider_status' => 'closed']
    )));
    $lifecycle = $lifecycle->apply(CommerceEvent::fromArray(refundPayload(
        CommerceEventType::REFUND_CANCELLED,
        ['source_event_id' => 'refund:501:cancelled']
    )));
    assertSame(RefundLifecycle::CANCELLED, $lifecycle->status());
    $lifecycle = $lifecycle->apply(CommerceEvent::fromArray(refundPayload(
        CommerceEventType::REFUND_REQUESTED,
        ['source_event_id' => 'refund:501:requested:retry-after-cancel']
    )));
    assertSame(RefundLifecycle::REQUESTED, $lifecycle->status());
    assertSame('0.00', $lifecycle->cumulativeAmount());
};

$tests['late cancellation cannot restore a completed refund'] = function (): void {
    $lifecycle = new RefundLifecycle(1, 101, 501, '1000.00');
    $lifecycle = $lifecycle->apply(CommerceEvent::fromArray(refundPayload(CommerceEventType::REFUND_REQUESTED)));
    $lifecycle = $lifecycle->apply(CommerceEvent::fromArray(refundPayload(CommerceEventType::REFUND_COMPLETED, [
        'source_event_id' => 'refund:501:completed:late-cancel-test',
        'refund_delta' => '1000.00',
        'cumulative_refunded_amount' => '1000.00',
        'completion_id' => 'finance-confirmation-501',
        'completion_source' => 'manual_finance_confirm',
        'provider_status' => 'confirmed',
    ])));

    expectException(InvalidArgumentException::class, function () use ($lifecycle): void {
        $lifecycle->apply(CommerceEvent::fromArray(refundPayload(
            CommerceEventType::REFUND_CANCELLED,
            ['source_event_id' => 'refund:501:cancelled:late']
        )));
    });
};

$tests['trusted fund completion wins a cancellation race'] = function (): void {
    $lifecycle = new RefundLifecycle(1, 101, 501, '1000.00');
    $lifecycle = $lifecycle->apply(CommerceEvent::fromArray(refundPayload(CommerceEventType::REFUND_REQUESTED)));
    $lifecycle = $lifecycle->apply(CommerceEvent::fromArray(refundPayload(
        CommerceEventType::REFUND_CANCELLED,
        ['source_event_id' => 'refund:501:cancelled:before-provider-result']
    )));
    assertSame(RefundLifecycle::CANCELLED, $lifecycle->status());

    $lifecycle = $lifecycle->apply(CommerceEvent::fromArray(refundPayload(CommerceEventType::REFUND_PROCESSING, [
        'source_event_id' => 'refund:501:processing:after-cancel',
        'completion_source' => 'provider_accepted',
        'provider_status' => 'processing',
        'provider_refund_no' => 'wx-refund-501-race',
    ])));
    $lifecycle = $lifecycle->apply(CommerceEvent::fromArray(refundPayload(CommerceEventType::REFUND_COMPLETED, [
        'source_event_id' => 'refund:501:completed:after-cancel',
        'refund_delta' => '1000.00',
        'cumulative_refunded_amount' => '1000.00',
        'completion_id' => 'wx-refund-501-race-success',
        'completion_source' => 'provider_query_success',
        'provider_status' => 'success',
        'provider_refund_no' => 'wx-refund-501-race',
    ])));
    assertSame(RefundLifecycle::COMPLETED, $lifecycle->status());
};

$tests['partial refunds require distinct completion ids and exact cumulative amounts'] = function (): void {
    $lifecycle = new RefundLifecycle(1, 101, 501, '1000.00');
    $lifecycle = $lifecycle->apply(CommerceEvent::fromArray(refundPayload(CommerceEventType::REFUND_REQUESTED)));
    $first = CommerceEvent::fromArray(refundPayload(CommerceEventType::REFUND_COMPLETED, [
        'source_event_id' => 'refund:501:completed:part-1',
        'refund_delta' => '400.00',
        'cumulative_refunded_amount' => '400.00',
        'completion_id' => 'refund-completion-501-1',
        'completion_source' => 'manual_finance_confirm',
        'provider_status' => 'confirmed',
    ]));
    $lifecycle = $lifecycle->apply($first);
    assertSame(RefundLifecycle::PARTIALLY_COMPLETED, $lifecycle->status());
    assertSame('400.00', $lifecycle->cumulativeAmount());

    $lifecycle = $lifecycle->apply(CommerceEvent::fromArray(refundPayload(CommerceEventType::REFUND_REQUESTED, [
        'source_event_id' => 'refund:501:requested:part-2',
        'cumulative_refunded_amount' => '400.00',
    ])));
    $lifecycle = $lifecycle->apply(CommerceEvent::fromArray(refundPayload(CommerceEventType::REFUND_PROCESSING, [
        'source_event_id' => 'refund:501:processing:part-2',
        'cumulative_refunded_amount' => '400.00',
        'completion_source' => 'provider_accepted',
        'provider_status' => 'processing',
        'provider_refund_no' => 'wx-refund-501-2',
    ])));
    $second = CommerceEvent::fromArray(refundPayload(CommerceEventType::REFUND_COMPLETED, [
        'source_event_id' => 'refund:501:completed:part-2',
        'refund_delta' => '600.00',
        'cumulative_refunded_amount' => '1000.00',
        'completion_id' => 'refund-completion-501-2',
        'completion_source' => 'provider_query_success',
        'provider_status' => 'success',
        'provider_refund_no' => 'wx-refund-501-2',
    ]));
    $lifecycle = $lifecycle->apply($second);
    assertSame(RefundLifecycle::COMPLETED, $lifecycle->status());
    assertSame('1000.00', $lifecycle->cumulativeAmount());

    expectException(InvalidArgumentException::class, function (): void {
        CommerceEvent::fromArray(refundPayload(CommerceEventType::REFUND_COMPLETED, [
            'source_event_id' => 'refund:501:completed:over-refund',
            'refund_delta' => '0.01',
            'cumulative_refunded_amount' => '1000.01',
            'completion_id' => 'refund-completion-501-over',
            'completion_source' => 'manual_finance_confirm',
            'provider_status' => 'confirmed',
        ]));
    });
};

$tests['refund lifecycle rejects another tenant and order snapshot'] = function (): void {
    $lifecycle = new RefundLifecycle(1, 101, 501, '1000.00');

    expectException(InvalidArgumentException::class, function () use ($lifecycle): void {
        $lifecycle->apply(CommerceEvent::fromArray(refundPayload(
            CommerceEventType::REFUND_REQUESTED,
            ['tenant_id' => 2]
        )));
    });
    expectException(InvalidArgumentException::class, function () use ($lifecycle): void {
        $lifecycle->apply(CommerceEvent::fromArray(refundPayload(
            CommerceEventType::REFUND_REQUESTED,
            ['paid_amount' => '999.00']
        )));
    });
    expectException(InvalidArgumentException::class, function (): void {
        new RefundLifecycle(1, 101, 501, '0.00');
    });
};

$tests['ThinkDb adapter callbacks preserve duplicate and conflict semantics'] = function (): void {
    $rows = [];
    $key = function (array $identity): string {
        return implode('|', [
            $identity['tenant_id'],
            $identity['source'],
            $identity['event_type'],
            $identity['source_event_id'],
        ]);
    };
    $query = function (array $identity) use (&$rows, $key) {
        $identityKey = $key($identity);
        return isset($rows[$identityKey]) ? $rows[$identityKey] : null;
    };
    $write = function (array $row) use (&$rows, $key): bool {
        $identityKey = $key($row);
        if (isset($rows[$identityKey])) {
            throw new RuntimeException('simulated duplicate key');
        }
        $rows[$identityKey] = $row;
        return true;
    };

    $store = new ThinkDbCommerceEventStore($write, $query);
    $event = CommerceEvent::fromArray(orderPayload());
    assertSame(CommerceEventReceipt::RECORDED, $store->record($event)->outcome());
    assertSame(CommerceEventReceipt::DUPLICATE, $store->record($event)->outcome());
    assertSame(1, count($rows));
    $stored = array_values($rows)[0];
    assertSame(1, $stored['tenant_id']);
    assertSame(1, $stored['schema_version']);
    assertSame('membership', $stored['business_type']);
    assertSame(2001, $stored['context_id']);
    assertSame('correlation-commerce-0001', $stored['correlation_id']);
    assertSame('received', $stored['status']);
    assertSame(true, strlen($stored['payload_json']) > 0);
    assertSame(true, $stored['received_time'] > 0);
    assertSame($stored['received_time'], $stored['add_time']);
    assertSame($stored['received_time'], $stored['update_time']);

    $changed = CommerceEvent::fromArray(orderPayload(['paid_amount' => '1001.00']));
    expectException(CommerceEventConflictException::class, function () use ($store, $changed): void {
        $store->record($changed);
    });
};

$tests['ThinkDb adapter resolves an insert race through the unique identity lookup'] = function (): void {
    $event = CommerceEvent::fromArray(orderPayload(['source_event_id' => 'store_order:101:race']));
    $row = null;
    $query = function (array $identity) use (&$row) {
        return $row;
    };
    $write = function (array $incoming) use (&$row): bool {
        $row = $incoming;
        throw new RuntimeException('simulated concurrent unique-key insert');
    };
    $store = new ThinkDbCommerceEventStore($write, $query);

    $receipt = $store->record($event);
    assertSame(CommerceEventReceipt::DUPLICATE, $receipt->outcome());
    assertSame($event->eventId(), $receipt->eventId());
};

$tests['ThinkDb adapter fails closed on a corrupt stored event identity'] = function (): void {
    $event = CommerceEvent::fromArray(orderPayload(['source_event_id' => 'store_order:101:corrupt-row']));
    $query = function (array $identity) use ($event): array {
        return [
            'event_id' => str_repeat('0', 64),
            'payload_hash' => $event->payloadHash(),
        ];
    };
    $store = new ThinkDbCommerceEventStore(function (array $row): bool {
        return true;
    }, $query);

    expectException(RuntimeException::class, function () use ($store, $event): void {
        $store->record($event);
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

function orderPayload(array $overrides = []): array
{
    return array_replace([
        'source' => 'crmeb',
        'source_event_id' => 'store_order:101:paid',
        'event_type' => CommerceEventType::ORDER_COMPLETED,
        'schema_version' => 1,
        'occurred_at' => 1784625600,
        'tenant_id' => 1,
        'channel_id' => 11,
        'order_pk' => 101,
        'order_no' => 'ORDER202607210001',
        'uid' => 1001,
        'business_type' => 'membership',
        'context_id' => 2001,
        'currency' => 'CNY',
        'paid_amount' => '1000.00',
        'correlation_id' => 'correlation-commerce-0001',
        'completion_kind' => 'paid',
        'pay_type' => 'weixin',
        'trade_no' => 'WX202607210001',
        'paid_at' => 1784625600,
    ], $overrides);
}

function refundPayload(string $eventType, array $overrides = []): array
{
    $status = CommerceEventType::refundStatus($eventType);
    $payload = [
        'source' => 'crmeb',
        'source_event_id' => 'refund:501:' . $status,
        'event_type' => $eventType,
        'schema_version' => 1,
        'occurred_at' => 1784625600,
        'tenant_id' => 1,
        'channel_id' => 11,
        'order_pk' => 101,
        'order_no' => 'ORDER202607210001',
        'uid' => 1001,
        'business_type' => 'membership',
        'context_id' => 2001,
        'currency' => 'CNY',
        'paid_amount' => '1000.00',
        'correlation_id' => 'correlation-commerce-0001',
        'refund_pk' => 501,
        'refund_no' => 'REFUND202607210001',
        'provider_refund_no' => '',
        'refund_status' => $status,
        'refund_delta' => '0.00',
        'cumulative_refunded_amount' => '0.00',
        'completion_id' => '',
        'completion_source' => '',
        'provider_status' => '',
    ];

    if ($eventType === CommerceEventType::REFUND_PROCESSING) {
        $payload['completion_source'] = 'provider_accepted';
        $payload['provider_status'] = 'processing';
    }

    return array_replace($payload, $overrides);
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

function assertNotSame($expected, $actual): void
{
    if ($expected === $actual) {
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

    throw new RuntimeException(sprintf('Expected exception %s', $class));
}
