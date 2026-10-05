<?php

declare(strict_types=1);

namespace app\chamber\activity;

use app\chamber\exceptions\MemberTransactionException;
use app\chamber\membership\CourseConsumptionPolicy;

/** 会员端课次列表筛选（严格白名单）。 */
final class CourseSessionListQuery
{
    private const COURSE_TYPES = [
        CourseConsumptionPolicy::TYPE_PRIVATE,
        CourseConsumptionPolicy::TYPE_GROUP,
        CourseConsumptionPolicy::TYPE_FAMILY,
        CourseConsumptionPolicy::TYPE_ONCOURSE,
    ];

    private const STATUSES = [
        'bookable' => 1,
        'full' => 2,
        'ended' => 3,
        'cancelled' => 4,
    ];

    /** @var string|null */
    private $courseType;

    /** @var int|null */
    private $status;

    /** @var int */
    private $page;

    /** @var int */
    private $limit;

    private function __construct()
    {
    }

    public static function fromArray(array $query): self
    {
        foreach (array_keys($query) as $field) {
            if (!is_string($field) || !in_array($field, ['course_type', 'status', 'page', 'limit'], true)) {
                $name = is_string($field) ? $field : 'query';
                throw self::validation($name, 'unknown_field', 'Unknown course session query field: ' . $name);
            }
        }

        $instance = new self();
        $instance->courseType = self::optionalEnum(
            $query['course_type'] ?? null,
            'course_type',
            self::COURSE_TYPES
        );
        $instance->status = self::optionalStatus($query['status'] ?? null);
        $instance->page = self::boundedInteger($query['page'] ?? null, 'page', 1, PHP_INT_MAX, 1);
        $instance->limit = self::boundedInteger($query['limit'] ?? null, 'limit', 1, 100, 20);

        return $instance;
    }

    public function courseType(): ?string
    {
        return $this->courseType;
    }

    public function status(): ?int
    {
        return $this->status;
    }

    public function page(): int
    {
        return $this->page;
    }

    public function limit(): int
    {
        return $this->limit;
    }

    private static function optionalEnum($value, string $field, array $allowed): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw self::validation($field, 'invalid_value', $field . ' is invalid');
        }

        return $value;
    }

    private static function optionalStatus($value): ?int
    {
        if ($value === null || $value === '') {
            return 1;
        }
        if (!is_string($value) || !array_key_exists($value, self::STATUSES)) {
            throw self::validation('status', 'invalid_value', 'status is invalid');
        }

        return self::STATUSES[$value];
    }

    private static function boundedInteger($value, string $field, int $minimum, int $maximum, int $default): int
    {
        if ($value === null || $value === '') {
            return $default;
        }
        if (is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value) === 1) {
            $parsed = (int) $value;
            if ((string) $parsed === $value) {
                $value = $parsed;
            }
        }
        if (!is_int($value) || $value < $minimum || $value > $maximum) {
            throw self::validation($field, 'out_of_range', $field . ' is out of range');
        }

        return $value;
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
