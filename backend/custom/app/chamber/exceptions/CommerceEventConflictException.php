<?php

namespace app\chamber\exceptions;

use app\chamber\commerce\CommerceEvent;
use RuntimeException;

final class CommerceEventConflictException extends RuntimeException
{
    /** @var string */
    private $storedHash;

    /** @var string */
    private $incomingHash;

    public static function forEvent(CommerceEvent $event, string $storedHash): self
    {
        $exception = new self(sprintf(
            'Commerce event identity conflict for %s/%s/%s',
            $event->source(),
            $event->eventType(),
            $event->sourceEventId()
        ));
        $exception->storedHash = $storedHash;
        $exception->incomingHash = $event->payloadHash();

        return $exception;
    }

    public static function forCompletion(CommerceEvent $event, string $storedHash, string $incomingHash): self
    {
        $exception = new self(sprintf(
            'Refund completion identity conflict for %s/%s',
            $event->source(),
            $event->completionId()
        ));
        $exception->storedHash = $storedHash;
        $exception->incomingHash = $incomingHash;

        return $exception;
    }

    public function storedHash(): string
    {
        return $this->storedHash;
    }

    public function incomingHash(): string
    {
        return $this->incomingHash;
    }
}
