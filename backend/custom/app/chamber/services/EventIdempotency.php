<?php

declare(strict_types=1);

namespace app\chamber\services;

use app\chamber\tenancy\TenantContext;

/** Reuses the encrypted, leased idempotency record implementation for activity mutations. */
final class EventIdempotency
{
    /** @var GraduateVerificationIdempotency */
    private $delegate;

    public function __construct(callable $clock = null, callable $leaseTokenFactory = null)
    {
        $this->delegate = new GraduateVerificationIdempotency($clock, $leaseTokenFactory);
    }

    public function execute(
        TenantContext $tenant,
        string $operation,
        string $principalType,
        int $principalId,
        string $callerKey,
        array $normalizedRequest,
        int $successHttpStatus,
        callable $execution,
        callable $authorization
    ): array {
        return $this->delegate->execute(
            $tenant,
            $operation,
            $principalType,
            $principalId,
            $callerKey,
            $normalizedRequest,
            $successHttpStatus,
            $execution,
            $authorization
        );
    }
}
