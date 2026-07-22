<?php

namespace app\chamber\exceptions;

use RuntimeException;

class TenantResolutionException extends RuntimeException
{
    public const BAD_SIGNATURE = 'bad_signature';
    public const CONFLICT = 'conflicting_context';
    public const INACTIVE = 'inactive_tenant';
    public const INCOMPLETE_SIGNATURE = 'incomplete_signature';
    public const INVALID_INPUT = 'invalid_input';
    public const MISSING = 'missing_context';
    public const REPLAY_GUARD_UNAVAILABLE = 'replay_guard_unavailable';
    public const REPLAYED = 'replayed_request';
    public const SIGNING_UNAVAILABLE = 'signing_unavailable';
    public const STALE_SIGNATURE = 'stale_signature';
    public const UNKNOWN = 'unknown_tenant';

    /** @var string */
    private $reason;

    /** @var int */
    private $httpStatus;

    public function __construct(string $reason, string $message, int $httpStatus)
    {
        parent::__construct($message);
        $this->reason = $reason;
        $this->httpStatus = $httpStatus;
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }
}
