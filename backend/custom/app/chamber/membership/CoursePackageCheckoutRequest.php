<?php

declare(strict_types=1);

namespace app\chamber\membership;

use InvalidArgumentException;

/** 购买课时包请求 DTO（类比 MembershipCheckoutRequest）。 */
final class CoursePackageCheckoutRequest
{
    private const FIELDS = ['package_id', 'coach_tier', 'expected_amount', 'currency'];

    /** @var int */
    private $packageId;

    /** @var int */
    private $coachTier;

    /** @var string|null */
    private $expectedAmount;

    /** @var string */
    private $currency;

    private function __construct(int $packageId, int $coachTier, ?string $expectedAmount, string $currency)
    {
        $this->packageId = $packageId;
        $this->coachTier = $coachTier;
        $this->expectedAmount = $expectedAmount;
        $this->currency = $currency;
    }

    public static function fromArray(array $input): self
    {
        self::assertExactFields($input);
        $packageId = self::assertPositiveInt($input['package_id'], 'package_id');
        $coachTier = self::assertIntRange($input['coach_tier'], 'coach_tier', 0, 3);
        $expectedAmount = (isset($input['expected_amount']) && $input['expected_amount'] !== null)
            ? self::assertDecimal($input['expected_amount'], 'expected_amount')
            : null;
        $currency = isset($input['currency']) ? self::assertCurrency($input['currency']) : 'CNY';

        return new self($packageId, $coachTier, $expectedAmount, $currency);
    }

    public function packageId(): int
    {
        return $this->packageId;
    }

    public function coachTier(): int
    {
        return $this->coachTier;
    }

    public function expectedAmount(): ?string
    {
        return $this->expectedAmount;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    public function toIdempotencyArray(): array
    {
        return [
            'package_id' => $this->packageId,
            'coach_tier' => $this->coachTier,
            'expected_amount' => $this->expectedAmount,
            'currency' => $this->currency,
        ];
    }

    private static function assertExactFields(array $input): void
    {
        foreach (array_keys($input) as $field) {
            if (!is_string($field) || !in_array($field, self::FIELDS, true)) {
                throw new InvalidArgumentException('course package checkout request contains an unknown field');
            }
        }
        if (!array_key_exists('package_id', $input)) {
            throw new InvalidArgumentException('package_id is required');
        }
    }

    private static function assertPositiveInt($value, string $field): int
    {
        if (is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value) === 1) {
            $value = (int) $value;
        }
        if (!is_int($value) || $value <= 0) {
            throw new InvalidArgumentException($field . ' must be a positive integer');
        }

        return $value;
    }

    private static function assertIntRange($value, string $field, int $min, int $max): int
    {
        if (is_string($value) && preg_match('/^(0|[1-9][0-9]*)$/D', $value) === 1) {
            $value = (int) $value;
        }
        if (!is_int($value) || $value < $min || $value > $max) {
            throw new InvalidArgumentException($field . ' must be between ' . $min . ' and ' . $max);
        }

        return $value;
    }

    private static function assertDecimal($value, string $field): string
    {
        if (!is_string($value) || !preg_match('/^[0-9]+(?:\.[0-9]{1,2})?$/D', $value)) {
            throw new InvalidArgumentException($field . ' must be a decimal amount');
        }

        return number_format((float) $value, 2, '.', '');
    }

    private static function assertCurrency($value): string
    {
        if (!is_string($value) || !preg_match('/^[A-Z]{3}$/D', $value)) {
            throw new InvalidArgumentException('currency must be an uppercase ISO 4217 code');
        }

        return $value;
    }
}
