<?php

declare(strict_types=1);

namespace app\chamber\membership;

use app\chamber\commerce\Money;
use InvalidArgumentException;

final class MembershipPlanSnapshot
{
    private const FIELDS = [
        'id',
        'tenant_id',
        'channel_id',
        'code',
        'version',
        'name',
        'tier',
        'purchase_enabled',
        'price',
        'currency',
        'term_months',
        'product_id',
        'product_attr_unique',
        'benefits',
        'renewal_policy',
        'upgrade_policy',
        'refund_policy',
        'status',
        'effective_time',
        'end_time',
    ];

    /** @var array */
    private $values;

    private function __construct(array $values)
    {
        $this->values = $values;
    }

    public static function fromArray(array $input): self
    {
        self::assertExactFields($input);

        foreach (['id', 'tenant_id', 'channel_id', 'version', 'product_id'] as $field) {
            self::assertPositiveInteger($input[$field], $field);
        }
        foreach (['effective_time', 'end_time'] as $field) {
            self::assertNonNegativeInteger($input[$field], $field);
        }
        if (!is_int($input['term_months']) || $input['term_months'] !== 12) {
            throw new InvalidArgumentException('term_months must be 12 for the yearly membership contract');
        }
        if (!is_bool($input['purchase_enabled'])) {
            throw new InvalidArgumentException('purchase_enabled must be boolean');
        }
        if (!is_int($input['status']) || !in_array($input['status'], [0, 1, 2, 3], true)) {
            throw new InvalidArgumentException('Membership plan status is invalid');
        }
        if ($input['end_time'] !== 0 && $input['end_time'] <= $input['effective_time']) {
            throw new InvalidArgumentException('Membership plan availability interval is invalid');
        }

        $tier = MemberTier::assertValid($input['tier']);
        if (!in_array($tier, [MemberTier::L2, MemberTier::L3], true)) {
            throw new InvalidArgumentException('Membership plan tier must be L2 or L3');
        }
        $code = MembershipCheckoutRequest::assertPlanCode($input['code']);
        $name = self::assertDisplayString($input['name'], 'name', 80);
        $price = Money::assertAmount($input['price'], 'price');
        if ($price === '0.00') {
            throw new InvalidArgumentException('Membership plan price must be greater than zero');
        }
        $currency = MembershipCheckoutRequest::assertCurrency($input['currency']);
        $productAttrUnique = self::assertIdentifier($input['product_attr_unique'], 'product_attr_unique', 20);
        $benefits = self::assertBenefits($input['benefits']);
        $renewalPolicy = self::assertPolicy($input['renewal_policy'], 'renewal_policy');
        $upgradePolicy = self::assertPolicy($input['upgrade_policy'], 'upgrade_policy');
        $refundPolicy = self::assertPolicy($input['refund_policy'], 'refund_policy');

        return new self([
            'id' => $input['id'],
            'tenant_id' => $input['tenant_id'],
            'channel_id' => $input['channel_id'],
            'code' => $code,
            'version' => $input['version'],
            'name' => $name,
            'tier' => $tier,
            'purchase_enabled' => $input['purchase_enabled'],
            'price' => $price,
            'currency' => $currency,
            'term_months' => $input['term_months'],
            'product_id' => $input['product_id'],
            'product_attr_unique' => $productAttrUnique,
            'benefits' => $benefits,
            'renewal_policy' => $renewalPolicy,
            'upgrade_policy' => $upgradePolicy,
            'refund_policy' => $refundPolicy,
            'status' => $input['status'],
            'effective_time' => $input['effective_time'],
            'end_time' => $input['end_time'],
        ]);
    }

    public function id(): int
    {
        return $this->values['id'];
    }

    public function tenantId(): int
    {
        return $this->values['tenant_id'];
    }

    public function channelId(): int
    {
        return $this->values['channel_id'];
    }

    public function code(): string
    {
        return $this->values['code'];
    }

    public function version(): int
    {
        return $this->values['version'];
    }

    public function name(): string
    {
        return $this->values['name'];
    }

    public function tier(): string
    {
        return $this->values['tier'];
    }

    public function price(): string
    {
        return $this->values['price'];
    }

    public function currency(): string
    {
        return $this->values['currency'];
    }

    public function termMonths(): int
    {
        return $this->values['term_months'];
    }

    public function productId(): int
    {
        return $this->values['product_id'];
    }

    public function productAttrUnique(): string
    {
        return $this->values['product_attr_unique'];
    }

    public function isAvailableAt(int $now): bool
    {
        if ($now <= 0) {
            throw new InvalidArgumentException('Availability evaluation time must be positive');
        }

        return $this->values['status'] === 1
            && $this->values['purchase_enabled']
            && $this->values['effective_time'] <= $now
            && ($this->values['end_time'] === 0 || $now < $this->values['end_time']);
    }

    public function toPublicArray(bool $eligible, ?string $ineligibleReason): array
    {
        if ($eligible !== ($ineligibleReason === null)) {
            throw new InvalidArgumentException('Membership plan eligibility result is inconsistent');
        }
        if ($ineligibleReason !== null && !in_array($ineligibleReason, [
            MembershipPurchasePolicy::PLAN_UNAVAILABLE,
            MembershipPurchasePolicy::DOWNGRADE_NOT_ALLOWED,
        ], true)) {
            throw new InvalidArgumentException('Membership plan ineligible reason is invalid');
        }

        return [
            'code' => $this->code(),
            'version' => $this->version(),
            'name' => $this->name(),
            'tier' => $this->tier(),
            'price' => $this->price(),
            'currency' => $this->currency(),
            'duration_value' => 1,
            'duration_unit' => 'year',
            'benefits' => $this->values['benefits'],
            'eligible' => $eligible,
            'ineligible_reason' => $ineligibleReason,
        ];
    }

    public function priceSnapshot(): array
    {
        return [
            'currency' => $this->currency(),
            'list_amount' => $this->price(),
            'payable_amount' => $this->price(),
            'plan_code' => $this->code(),
            'plan_version' => $this->version(),
        ];
    }

    public function entitlementSnapshot(): array
    {
        return [
            'benefits' => $this->values['benefits'],
            'plan_code' => $this->code(),
            'plan_version' => $this->version(),
            'term_months' => $this->termMonths(),
            'tier' => $this->tier(),
        ];
    }

    public function renewalPolicySnapshot(): array
    {
        return $this->values['renewal_policy'];
    }

    public function upgradePolicySnapshot(): array
    {
        return $this->values['upgrade_policy'];
    }

    public function refundPolicySnapshot(): array
    {
        return $this->values['refund_policy'];
    }

    private static function assertExactFields(array $input): void
    {
        foreach (array_keys($input) as $field) {
            if (!is_string($field) || !in_array($field, self::FIELDS, true)) {
                throw new InvalidArgumentException('Membership plan snapshot contains an unknown field');
            }
        }
        foreach (self::FIELDS as $field) {
            if (!array_key_exists($field, $input)) {
                throw new InvalidArgumentException(sprintf('Membership plan snapshot field %s is required', $field));
            }
        }
    }

    /**
     * @param mixed $value
     */
    private static function assertPositiveInteger($value, string $field): void
    {
        if (!is_int($value) || $value <= 0 || $value > 4294967295) {
            throw new InvalidArgumentException(sprintf('%s must be a positive integer', $field));
        }
    }

    /**
     * @param mixed $value
     */
    private static function assertNonNegativeInteger($value, string $field): void
    {
        if (!is_int($value) || $value < 0 || $value > 4294967295) {
            throw new InvalidArgumentException(sprintf('%s must be a non-negative integer', $field));
        }
    }

    /**
     * @param mixed $value
     */
    private static function assertDisplayString($value, string $field, int $maxLength): string
    {
        $length = is_string($value)
            ? (function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value))
            : 0;
        if (!is_string($value)
            || $length < 1
            || $length > $maxLength
            || trim($value) === '') {
            throw new InvalidArgumentException(sprintf('%s is invalid', $field));
        }

        return $value;
    }

    /**
     * @param mixed $value
     */
    private static function assertIdentifier($value, string $field, int $maxLength): string
    {
        if (!is_string($value)
            || strlen($value) < 1
            || strlen($value) > $maxLength
            || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/D', $value)) {
            throw new InvalidArgumentException(sprintf('%s is invalid', $field));
        }

        return $value;
    }

    /**
     * @param mixed $value
     */
    private static function assertBenefits($value): array
    {
        if (!is_array($value) || !self::isList($value) || count($value) > 50) {
            throw new InvalidArgumentException('benefits must be a JSON array with at most 50 entries');
        }

        $normalized = [];
        foreach ($value as $benefit) {
            $normalized[] = self::assertDisplayString($benefit, 'benefit', 200);
        }

        return $normalized;
    }

    /**
     * @param mixed $value
     */
    private static function assertPolicy($value, string $field): array
    {
        if (!is_array($value) || ($value !== [] && self::isList($value))) {
            throw new InvalidArgumentException(sprintf('%s must be a JSON object', $field));
        }

        BootstrapIdempotency::canonicalJson($value);

        return $value;
    }

    private static function isList(array $value): bool
    {
        $expected = 0;
        foreach ($value as $key => $unused) {
            if ($key !== $expected) {
                return false;
            }
            $expected++;
        }

        return true;
    }
}
