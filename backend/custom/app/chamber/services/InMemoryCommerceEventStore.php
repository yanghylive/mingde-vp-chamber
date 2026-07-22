<?php

namespace app\chamber\services;

use app\chamber\commerce\CommerceEvent;
use app\chamber\commerce\CommerceEventReceipt;
use app\chamber\contracts\CommerceEventStoreInterface;
use app\chamber\exceptions\CommerceEventConflictException;

final class InMemoryCommerceEventStore implements CommerceEventStoreInterface
{
    /** @var CommerceEvent[] */
    private $events = [];

    public function record(CommerceEvent $event): CommerceEventReceipt
    {
        $identity = $this->identity($event);
        if (isset($this->events[$identity])) {
            $stored = $this->events[$identity];
            if (!hash_equals($stored->payloadHash(), $event->payloadHash())) {
                throw CommerceEventConflictException::forEvent($event, $stored->payloadHash());
            }

            return new CommerceEventReceipt($stored->eventId(), CommerceEventReceipt::DUPLICATE);
        }

        $this->events[$identity] = $event;

        return new CommerceEventReceipt($event->eventId(), CommerceEventReceipt::RECORDED);
    }

    public function count(): int
    {
        return count($this->events);
    }

    /** @return CommerceEvent[] */
    public function events(): array
    {
        return array_values($this->events);
    }

    private function identity(CommerceEvent $event): string
    {
        return implode("\0", [
            (string) $event->tenantId(),
            $event->source(),
            $event->eventType(),
            $event->sourceEventId(),
        ]);
    }
}
