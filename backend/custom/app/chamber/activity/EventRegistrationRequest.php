<?php

declare(strict_types=1);

namespace app\chamber\activity;

use app\chamber\commerce\Money;
use app\chamber\exceptions\MemberTransactionException;
use InvalidArgumentException;

/** Strict client snapshot for one event ticket registration attempt. */
final class EventRegistrationRequest
{
    /** @var int */
    private $ticketId;

    /** @var string|null */
    private $expectedAmount;

    /** @var int|null */
    private $expectedIntegral;

    private function __construct()
    {
    }

    public static function fromArray(array $payload): self
    {
        foreach (array_keys($payload) as $field) {
            if (!is_string($field)
                || !in_array($field, ['ticket_id', 'expected_amount', 'expected_integral'], true)) {
                $name = is_string($field) ? $field : 'body';
                throw self::validation($name, 'unknown_field', 'Unknown event registration field: ' . $name);
            }
        }

        $ticketId = $payload['ticket_id'] ?? null;
        if (!is_int($ticketId) || $ticketId <= 0) {
            throw self::validation(
                'ticket_id',
                'invalid_value',
                'ticket_id must be a positive integer'
            );
        }

        $expectedAmount = null;
        if (array_key_exists('expected_amount', $payload)) {
            try {
                $expectedAmount = Money::assertAmount($payload['expected_amount'], 'expected_amount');
            } catch (InvalidArgumentException $exception) {
                throw self::validation(
                    'expected_amount',
                    'invalid_value',
                    'expected_amount must be a non-negative two-decimal string'
                );
            }
        }

        $expectedIntegral = null;
        if (array_key_exists('expected_integral', $payload)) {
            $expectedIntegral = $payload['expected_integral'];
            if (!is_int($expectedIntegral) || $expectedIntegral < 0 || $expectedIntegral > 4294967295) {
                throw self::validation(
                    'expected_integral',
                    'invalid_value',
                    'expected_integral must be a non-negative integer'
                );
            }
        }

        $instance = new self();
        $instance->ticketId = $ticketId;
        $instance->expectedAmount = $expectedAmount;
        $instance->expectedIntegral = $expectedIntegral;

        return $instance;
    }

    public function ticketId(): int
    {
        return $this->ticketId;
    }

    public function expectedAmount(): ?string
    {
        return $this->expectedAmount;
    }

    public function expectedIntegral(): ?int
    {
        return $this->expectedIntegral;
    }

    public function toIdempotencyArray(): array
    {
        return [
            'ticket_id' => $this->ticketId,
            'expected_amount' => $this->expectedAmount,
            'expected_integral' => $this->expectedIntegral,
        ];
    }

    private static function validation(string $field, string $code, string $message): MemberTransactionException
    {
        return new MemberTransactionException(
            422,
            'request_validation_failed',
            $message,
            [['field' => $field, 'code' => $code]]
        );
    }
}
