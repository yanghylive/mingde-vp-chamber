<?php

namespace app\chamber\services;

use app\chamber\commerce\CommerceEvent;
use app\chamber\commerce\CommerceEventReceipt;
use app\chamber\contracts\CommerceEventStoreInterface;

final class CommerceEventRecorder
{
    /** @var CommerceEventStoreInterface */
    private $store;

    public function __construct(CommerceEventStoreInterface $store)
    {
        $this->store = $store;
    }

    public function record(CommerceEvent $event): CommerceEventReceipt
    {
        return $this->store->record($event);
    }

    public function recordPayload(array $payload): CommerceEventReceipt
    {
        return $this->record(CommerceEvent::fromArray($payload));
    }
}
