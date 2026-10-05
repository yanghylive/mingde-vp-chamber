<?php

declare(strict_types=1);

namespace app\chamber\membership;

use InvalidArgumentException;

/** 课时包 SKU 值对象；私教包含分级价 tiers，小班/家庭/下场为固定总价。 */
final class CoursePackageSnapshot
{
    /** @var array */
    private $data;

    /** @var array<int, array{coach_tier:int, price_per_hour:string, total_price:string}> */
    private $tiers;

    /**
     * @param array $tiers
     */
    public function __construct(array $data, array $tiers)
    {
        $this->data = $data;
        $this->tiers = $tiers;
    }

    public static function fromArray(array $row): self
    {
        $required = [
            'id', 'tenant_id', 'channel_id', 'code', 'version', 'name', 'course_type',
            'total_hours', 'validity_months', 'price', 'currency', 'product_id',
            'product_attr_unique', 'status', 'effective_time', 'end_time',
        ];
        foreach ($required as $field) {
            if (!array_key_exists($field, $row)) {
                throw new InvalidArgumentException('course_package.' . $field . ' is required');
            }
        }
        $courseType = (string) $row['course_type'];
        if (!in_array($courseType, [
            CourseConsumptionPolicy::TYPE_PRIVATE,
            CourseConsumptionPolicy::TYPE_GROUP,
            CourseConsumptionPolicy::TYPE_FAMILY,
            CourseConsumptionPolicy::TYPE_ONCOURSE,
        ], true)) {
            throw new InvalidArgumentException('course_package.course_type is invalid');
        }
        $tiers = [];
        $rawTiers = $row['tiers'] ?? [];
        if (!is_array($rawTiers)) {
            throw new InvalidArgumentException('course_package.tiers must be an array');
        }
        foreach ($rawTiers as $tier) {
            if (!is_array($tier)
                || !isset($tier['coach_tier'], $tier['price_per_hour'], $tier['total_price'])) {
                throw new InvalidArgumentException('course_package.tier is invalid');
            }
            $tiers[(int) $tier['coach_tier']] = [
                'coach_tier' => (int) $tier['coach_tier'],
                'price_per_hour' => self::decimal((string) $tier['price_per_hour'], 'price_per_hour'),
                'total_price' => self::decimal((string) $tier['total_price'], 'total_price'),
            ];
        }

        return new self([
            'id' => (int) $row['id'],
            'tenant_id' => (int) $row['tenant_id'],
            'channel_id' => (int) $row['channel_id'],
            'code' => (string) $row['code'],
            'version' => (int) $row['version'],
            'name' => (string) $row['name'],
            'course_type' => $courseType,
            'total_hours' => self::decimal((string) $row['total_hours'], 'total_hours'),
            'validity_months' => (int) $row['validity_months'],
            'session_duration_hours' => self::decimal(
                (string) ($row['session_duration_hours'] ?? '1.00'),
                'session_duration_hours'
            ),
            'frequency' => (string) ($row['frequency'] ?? ''),
            'min_participants' => (int) ($row['min_participants'] ?? 1),
            'max_participants' => (int) ($row['max_participants'] ?? 1),
            'price' => self::decimal((string) $row['price'], 'price'),
            'stock' => (int) ($row['stock'] ?? -1),
            'currency' => (string) ($row['currency'] ?? 'CNY'),
            'product_id' => (int) $row['product_id'],
            'product_attr_unique' => (string) $row['product_attr_unique'],
            'benefits' => is_array($row['benefits'] ?? null) ? $row['benefits'] : [],
            'refund_policy' => is_array($row['refund_policy'] ?? null) ? $row['refund_policy'] : [],
            'status' => (int) $row['status'],
            'effective_time' => (int) $row['effective_time'],
            'end_time' => (int) $row['end_time'],
        ], $tiers);
    }

    public function id(): int { return $this->data['id']; }
    public function tenantId(): int { return $this->data['tenant_id']; }
    public function channelId(): int { return $this->data['channel_id']; }
    public function code(): string { return $this->data['code']; }
    public function version(): int { return $this->data['version']; }
    public function name(): string { return $this->data['name']; }
    public function courseType(): string { return $this->data['course_type']; }
    public function totalHours(): string { return $this->data['total_hours']; }
    public function validityMonths(): int { return $this->data['validity_months']; }
    public function sessionDurationHours(): string { return $this->data['session_duration_hours']; }
    public function frequency(): string { return $this->data['frequency']; }
    public function minParticipants(): int { return $this->data['min_participants']; }
    public function maxParticipants(): int { return $this->data['max_participants']; }
    public function price(): string { return $this->data['price']; }
    public function stock(): int { return $this->data['stock']; }
    public function currency(): string { return $this->data['currency']; }
    public function productId(): int { return $this->data['product_id']; }
    public function productAttrUnique(): string { return $this->data['product_attr_unique']; }
    public function benefits(): array { return $this->data['benefits']; }
    public function refundPolicy(): array { return $this->data['refund_policy']; }
    public function status(): int { return $this->data['status']; }
    public function effectiveTime(): int { return $this->data['effective_time']; }
    public function endTime(): int { return $this->data['end_time']; }

    /** @return array<int, array{coach_tier:int, price_per_hour:string, total_price:string}> */
    public function tiers(): array { return $this->tiers; }

    /** 取某教练等级对应的总价；无分级价时返回包基准价。 */
    public function tierTotalPrice(int $coachTier): string
    {
        if (isset($this->tiers[$coachTier])) {
            return $this->tiers[$coachTier]['total_price'];
        }

        return $this->data['price'];
    }

    /** @return array */
    public function toPublicArray(?string $eligibleReason = null): array
    {
        return [
            'id' => $this->data['id'],
            'product_id' => $this->data['product_id'],
            'code' => $this->data['code'],
            'version' => $this->data['version'],
            'name' => $this->data['name'],
            'course_type' => $this->data['course_type'],
            'total_hours' => $this->data['total_hours'],
            'validity_months' => $this->data['validity_months'],
            'session_duration_hours' => $this->data['session_duration_hours'],
            'frequency' => $this->data['frequency'],
            'min_participants' => $this->data['min_participants'],
            'max_participants' => $this->data['max_participants'],
            'price' => $this->data['price'],
            'stock' => $this->data['stock'],
            'currency' => $this->data['currency'],
            'benefits' => $this->data['benefits'],
            'refund_policy' => $this->data['refund_policy'],
            'status' => $this->data['status'],
            'effective_time' => $this->data['effective_time'],
            'end_time' => $this->data['end_time'],
            'tiers' => array_values($this->tiers),
            'eligible' => $eligibleReason === null,
            'ineligible_reason' => $eligibleReason,
        ];
    }

    private static function decimal(string $value, string $field): string
    {
        if (!preg_match('/^[0-9]+(?:\.[0-9]{1,2})?$/D', $value)) {
            throw new InvalidArgumentException('course_package.' . $field . ' must be a decimal');
        }

        return number_format((float) $value, 2, '.', '');
    }
}
