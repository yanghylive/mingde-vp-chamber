<?php

declare(strict_types=1);

namespace app\chamber\membership;

use app\chamber\commerce\Money;
use InvalidArgumentException;

final class MembershipCheckoutRequest
{
    private const FIELDS = ['plan_code', 'plan_version', 'expected_amount', 'currency'];

    /** @var string */
    private $planCode;

    /** @var int */
    private $planVersion;

    /** @var string */
    private $expectedAmount;

    /** @var string */
    private $currency;

    private function __construct(
        string $planCode,
        int $planVersion,
        string $expectedAmount,
        string $currency
    ) {
        $this->planCode = $planCode;
        $this->planVersion = $planVersion;
        $this->expectedAmount = $expectedAmount;
        $this->currency = $currency;
    }

    public static function fromArray(array $input): self
    {
        self::assertExactFields($input);

        $planCode = self::assertPlanCode($input['plan_code']);
        if (!is_int($input['plan_version']) || $input['plan_version'] < 1) {
            throw new InvalidArgumentException('plan_version must be a positive integer');
        }
        $expectedAmount = Money::assertAmount($input['expected_amount'], 'expected_amount');
        $currency = self::assertCurrency($input['currency']);

        return new self($planCode, $input['plan_version'], $expectedAmount, $currency);
    }

    public function planCode(): string
    {
        return $this->planCode;
    }

    public function planVersion(): int
    {
        return $this->planVersion;
    }

    public function expectedAmount(): string
    {
        return $this->expectedAmount;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    public function toCanonicalArray(): array
    {
        return [
            'currency' => $this->currency,
            'expected_amount' => $this->expectedAmount,
            'plan_code' => $this->planCode,
            'plan_version' => $this->planVersion,
        ];
    }

    /**
     * @param mixed $value
     */
    public static function assertPlanCode($value): string
    {
        if (!is_string($value)
            || strlen($value) < 1
            || strlen($value) > 32
            || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/D', $value)) {
            throw new InvalidArgumentException('plan_code is invalid');
        }

        return $value;
    }

    /**
     * @param mixed $value
     */
    public static function assertCurrency($value): string
    {
        if (!is_string($value) || !preg_match('/^[A-Z]{3}$/D', $value)) {
            throw new InvalidArgumentException('currency must be an uppercase ISO 4217 code');
        }

        return $value;
    }

    private static function assertExactFields(array $input): void
    {
        foreach (array_keys($input) as $field) {
            if (!is_string($field) || !in_array($field, self::FIELDS, true)) {
                throw new InvalidArgumentException('Membership checkout request contains an unknown field');
            }
        }
        foreach (self::FIELDS as $field) {
            if (!array_key_exists($field, $input)) {
                throw new InvalidArgumentException(sprintf('Membership checkout field %s is required', $field));
            }
        }
    }
}
