<?php

namespace app\chamber\commerce;

use InvalidArgumentException;

final class CommerceEventReceipt
{
    public const RECORDED = 'recorded';
    public const DUPLICATE = 'duplicate';

    /** @var string */
    private $eventId;

    /** @var string */
    private $outcome;

    public function __construct(string $eventId, string $outcome)
    {
        if (!in_array($outcome, [self::RECORDED, self::DUPLICATE], true)) {
            throw new InvalidArgumentException('Unsupported commerce event record outcome');
        }
        $this->eventId = $eventId;
        $this->outcome = $outcome;
    }

    public function eventId(): string
    {
        return $this->eventId;
    }

    public function outcome(): string
    {
        return $this->outcome;
    }

    public function isDuplicate(): bool
    {
        return $this->outcome === self::DUPLICATE;
    }
}
