<?php

namespace app\chamber\services;

use RuntimeException;
use Throwable;

final class RequestTraceId
{
    /** @var callable */
    private $generator;

    public function __construct(callable $generator = null)
    {
        $this->generator = $generator ?: function (): string {
            try {
                return bin2hex(random_bytes(16));
            } catch (Throwable $exception) {
                throw new RuntimeException('Unable to generate request trace ID', 0, $exception);
            }
        };
    }

    public function resolve(array $candidates): string
    {
        foreach ($candidates as $candidate) {
            $candidate = trim((string) $candidate);
            if ($this->isValid($candidate)) {
                return $candidate;
            }
        }

        $generated = (string) call_user_func($this->generator);
        if (!$this->isValid($generated)) {
            throw new RuntimeException('Generated request trace ID is invalid');
        }

        return $generated;
    }

    public function resolvePair(string $requestId, string $correlationId): array
    {
        $resolvedRequestId = $this->resolve([$requestId]);
        $correlationId = trim($correlationId);

        return [
            'request_id' => $resolvedRequestId,
            'correlation_id' => $this->isValid($correlationId) ? $correlationId : $resolvedRequestId,
        ];
    }

    public function isValid(string $requestId): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$/', $requestId);
    }
}
