<?php

namespace app\chamber\services;

use app\chamber\commerce\CommerceEvent;
use app\chamber\commerce\CommerceEventReceipt;
use app\chamber\contracts\CommerceEventStoreInterface;
use app\chamber\exceptions\CommerceEventConflictException;
use RuntimeException;
use Throwable;
use think\facade\Db;

final class ThinkDbCommerceEventStore implements CommerceEventStoreInterface
{
    /** @var callable|null */
    private $writer;

    /** @var callable|null */
    private $rowLookup;

    /** @var string */
    private $table;

    public function __construct(callable $writer = null, callable $rowLookup = null, string $table = 'ch_commerce_event_inbox')
    {
        if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/', $table)) {
            throw new RuntimeException('Invalid commerce event table name');
        }
        $this->writer = $writer;
        $this->rowLookup = $rowLookup;
        $this->table = $table;
    }

    public function record(CommerceEvent $event): CommerceEventReceipt
    {
        $existing = $this->lookup($event);
        if ($existing !== null) {
            return $this->resolveExisting($event, $existing);
        }

        $row = $this->row($event);
        try {
            $inserted = $this->insert($row);
        } catch (Throwable $exception) {
            $existing = $this->lookup($event, true);
            if ($existing !== null) {
                return $this->resolveExisting($event, $existing);
            }
            throw $exception;
        }

        if (!$inserted) {
            $existing = $this->lookup($event, true);
            if ($existing !== null) {
                return $this->resolveExisting($event, $existing);
            }
            throw new RuntimeException('Commerce event insert did not persist a row');
        }

        return new CommerceEventReceipt($event->eventId(), CommerceEventReceipt::RECORDED);
    }

    private function resolveExisting(CommerceEvent $event, array $row): CommerceEventReceipt
    {
        if (!isset($row['payload_hash']) || !is_string($row['payload_hash'])) {
            throw new RuntimeException('Stored commerce event is missing payload_hash');
        }
        if (!hash_equals($row['payload_hash'], $event->payloadHash())) {
            throw CommerceEventConflictException::forEvent($event, $row['payload_hash']);
        }

        if (!isset($row['event_id']) || !is_string($row['event_id'])) {
            throw new RuntimeException('Stored commerce event is missing event_id');
        }
        if (!hash_equals($row['event_id'], $event->eventId())) {
            throw new RuntimeException('Stored commerce event identity is inconsistent');
        }

        return new CommerceEventReceipt($row['event_id'], CommerceEventReceipt::DUPLICATE);
    }

    private function lookup(CommerceEvent $event, bool $currentRead = false)
    {
        if ($this->rowLookup) {
            $row = call_user_func($this->rowLookup, [
                'tenant_id' => $event->tenantId(),
                'source' => $event->source(),
                'event_type' => $event->eventType(),
                'source_event_id' => $event->sourceEventId(),
                'current_read' => $currentRead,
            ]);
        } else {
            $query = Db::table($this->table)
                ->where('tenant_id', $event->tenantId())
                ->where('source', $event->source())
                ->where('event_type', $event->eventType())
                ->where('source_event_id', $event->sourceEventId());
            if ($currentRead) {
                $query->lock(true);
            }
            $row = $query->find();
        }

        if ($row === null || $row === false || $row === []) {
            return null;
        }
        if (!is_array($row)) {
            throw new RuntimeException('Commerce event lookup must return an array or null');
        }

        return $row;
    }

    private function insert(array $row): bool
    {
        if ($this->writer) {
            return call_user_func($this->writer, $row) !== false;
        }

        return Db::table($this->table)->insert($row) === 1;
    }

    private function row(CommerceEvent $event): array
    {
        $payload = $event->payload();
        $receivedAt = time();

        return [
            'event_id' => $event->eventId(),
            'source' => $event->source(),
            'source_event_id' => $event->sourceEventId(),
            'event_type' => $event->eventType(),
            'schema_version' => $payload['schema_version'],
            'tenant_id' => $event->tenantId(),
            'channel_id' => $event->channelId(),
            'order_pk' => $event->orderPk(),
            'order_no' => $payload['order_no'],
            'business_type' => $payload['business_type'],
            'context_id' => $payload['context_id'],
            'correlation_id' => $payload['correlation_id'],
            'payload_hash' => $event->payloadHash(),
            'payload_json' => $event->toJson(),
            'status' => 'received',
            'attempt_count' => 0,
            'occurred_time' => $payload['occurred_at'],
            'received_time' => $receivedAt,
            'add_time' => $receivedAt,
            'update_time' => $receivedAt,
        ];
    }
}
