<?php

use app\chamber\commerce\CommerceEvent;
use app\chamber\commerce\CommerceEventReceipt;
use app\chamber\commerce\CommerceEventType;
use app\chamber\exceptions\CommerceEventConflictException;
use app\chamber\services\ThinkDbCommerceEventStore;
use think\App;
use think\facade\Db;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

(new App())->initialize();

$sourceEventId = sprintf('g0-db-test:%d:%d', time(), getmypid());
$store = new ThinkDbCommerceEventStore();
$assertions = 0;
$frameworkTransactionOpen = false;

Db::startTrans();
$frameworkTransactionOpen = true;
try {
    $first = CommerceEvent::fromArray(orderPayload($sourceEventId));
    assertSame(CommerceEventReceipt::RECORDED, $store->record($first)->outcome());
    $assertions++;
    assertSame(CommerceEventReceipt::DUPLICATE, $store->record($first)->outcome());
    $assertions++;

    $conflicting = CommerceEvent::fromArray(orderPayload($sourceEventId, [
        'paid_amount' => '1001.00',
    ]));
    expectException(CommerceEventConflictException::class, function () use ($store, $conflicting): void {
        $store->record($conflicting);
    });
    $assertions++;

    $secondTenant = CommerceEvent::fromArray(orderPayload($sourceEventId, [
        'tenant_id' => 2,
        'channel_id' => 2,
    ]));
    assertSame(CommerceEventReceipt::RECORDED, $store->record($secondTenant)->outcome());
    $assertions++;

    $rows = Db::table('ch_commerce_event_inbox')
        ->where('source', 'chamber_g0_test')
        ->where('source_event_id', $sourceEventId)
        ->order('tenant_id', 'asc')
        ->select()
        ->toArray();
    assertSame(2, count($rows));
    $assertions++;
    $expectedRow = [
        'event_id' => $first->eventId(),
        'tenant_id' => 1,
        'schema_version' => 1,
        'business_type' => 'membership',
        'context_id' => 2001,
        'correlation_id' => 'g0-db-test-correlation',
        'status' => 'received',
        'payload_hash' => $first->payloadHash(),
    ];
    foreach ($expectedRow as $field => $expected) {
        assertSame($expected, $rows[0][$field]);
    }
    $assertions++;

    Db::rollback();
    $frameworkTransactionOpen = false;
    assertRepeatableReadDuplicateRace($sourceEventId . ':repeatable-read');
    $assertions++;

    fwrite(STDOUT, sprintf("PASS commerce database adapter (%d assertions; transaction rolled back)\n", $assertions));
    exit(0);
} catch (Throwable $exception) {
    if ($frameworkTransactionOpen) {
        Db::rollback();
    }
    fwrite(STDERR, sprintf("FAIL commerce database adapter: %s\n", $exception->getMessage()));
    exit(1);
}

function assertRepeatableReadDuplicateRace(string $sourceEventId): void
{
    $event = CommerceEvent::fromArray(orderPayload($sourceEventId));
    $snapshotConnection = commercePdo();
    $competitorConnection = commercePdo();
    $competitorInserted = false;

    $snapshotConnection->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $snapshotConnection->beginTransaction();

    try {
        $lookup = function (array $identity) use ($snapshotConnection) {
            $sql = 'SELECT event_id, payload_hash FROM ch_commerce_event_inbox'
                . ' WHERE tenant_id = ? AND source = ? AND event_type = ? AND source_event_id = ? LIMIT 1';
            if (!empty($identity['current_read'])) {
                $sql .= ' FOR UPDATE';
            }
            $statement = $snapshotConnection->prepare($sql);
            $statement->execute([
                $identity['tenant_id'],
                $identity['source'],
                $identity['event_type'],
                $identity['source_event_id'],
            ]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);

            return $row === false ? null : $row;
        };
        $writer = function (array $row) use ($snapshotConnection, $competitorConnection, &$competitorInserted): bool {
            insertCommerceRow($competitorConnection, $row);
            $competitorInserted = true;
            insertCommerceRow($snapshotConnection, $row);

            return true;
        };

        $store = new ThinkDbCommerceEventStore($writer, $lookup);
        assertSame(CommerceEventReceipt::DUPLICATE, $store->record($event)->outcome());
    } finally {
        if ($snapshotConnection->inTransaction()) {
            $snapshotConnection->rollBack();
        }
        if ($competitorInserted) {
            $statement = $competitorConnection->prepare(
                'DELETE FROM ch_commerce_event_inbox WHERE event_id = ? AND source = ?'
            );
            $statement->execute([$event->eventId(), 'chamber_g0_test']);
        }
    }
}

function commercePdo(): PDO
{
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        getenv('MYSQL_HOST_IP'),
        getenv('MYSQL_PORT'),
        getenv('MYSQL_DATABASE')
    );

    return new PDO($dsn, getenv('MYSQL_USER'), getenv('MYSQL_PASSWORD'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function insertCommerceRow(PDO $connection, array $row): void
{
    $columns = array_keys($row);
    $quotedColumns = array_map(function (string $column): string {
        return '`' . $column . '`';
    }, $columns);
    $sql = sprintf(
        'INSERT INTO ch_commerce_event_inbox (%s) VALUES (%s)',
        implode(', ', $quotedColumns),
        implode(', ', array_fill(0, count($columns), '?'))
    );
    $statement = $connection->prepare($sql);
    $statement->execute(array_values($row));
}

function orderPayload(string $sourceEventId, array $overrides = []): array
{
    return array_replace([
        'source' => 'chamber_g0_test',
        'source_event_id' => $sourceEventId,
        'event_type' => CommerceEventType::ORDER_COMPLETED,
        'schema_version' => 1,
        'occurred_at' => time(),
        'tenant_id' => 1,
        'channel_id' => 1,
        'order_pk' => 101,
        'order_no' => 'G0DBTESTORDER0001',
        'uid' => 1001,
        'business_type' => 'membership',
        'context_id' => 2001,
        'currency' => 'CNY',
        'paid_amount' => '1000.00',
        'correlation_id' => 'g0-db-test-correlation',
        'completion_kind' => 'paid',
        'pay_type' => 'weixin',
        'trade_no' => 'G0DBTESTTRADE0001',
        'paid_at' => time(),
    ], $overrides);
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
