<?php

declare(strict_types=1);

namespace app\chamber\activity;

use app\chamber\membership\CourseConsumptionPolicy;
use InvalidArgumentException;

/** 可约课次值对象。 */
final class CourseSessionSnapshot
{
    /** @var array */
    private $data;

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public static function fromArray(array $row): self
    {
        $required = [
            'id', 'course_type', 'title', 'start_time', 'end_time', 'duration_hours',
            'capacity', 'booked_count', 'status',
        ];
        foreach ($required as $field) {
            if (!array_key_exists($field, $row)) {
                throw new InvalidArgumentException('course_session.' . $field . ' is required');
            }
        }
        $courseType = (string) $row['course_type'];
        if (!in_array($courseType, [
            CourseConsumptionPolicy::TYPE_PRIVATE,
            CourseConsumptionPolicy::TYPE_GROUP,
            CourseConsumptionPolicy::TYPE_FAMILY,
            CourseConsumptionPolicy::TYPE_ONCOURSE,
        ], true)) {
            throw new InvalidArgumentException('course_session.course_type is invalid');
        }
        $location = is_array($row['location_json'] ?? null) ? $row['location_json'] : [];

        return new self([
            'id' => (int) $row['id'],
            'course_type' => $courseType,
            'coach_id' => (int) ($row['coach_id'] ?? 0),
            'title' => (string) $row['title'],
            'start_time' => (int) $row['start_time'],
            'end_time' => (int) $row['end_time'],
            'duration_hours' => self::decimal((string) $row['duration_hours'], 'duration_hours'),
            'capacity' => (int) $row['capacity'],
            'booked_count' => (int) $row['booked_count'],
            'location_name' => (string) ($location['name'] ?? ''),
            'address' => (string) ($location['address'] ?? ''),
            'longitude' => (string) ($location['longitude'] ?? ''),
            'latitude' => (string) ($location['latitude'] ?? ''),
            'price_per_session' => self::decimal((string) ($row['price_per_session'] ?? '0.00'), 'price_per_session'),
            'applicable_package_id' => (int) ($row['applicable_package_id'] ?? 0),
            'product_id' => (int) ($row['product_id'] ?? 0),
            'status' => (int) $row['status'],
        ]);
    }

    public function id(): int { return $this->data['id']; }
    public function courseType(): string { return $this->data['course_type']; }
    public function coachId(): int { return $this->data['coach_id']; }
    public function title(): string { return $this->data['title']; }
    public function startTime(): int { return $this->data['start_time']; }
    public function endTime(): int { return $this->data['end_time']; }
    public function durationHours(): string { return $this->data['duration_hours']; }
    public function capacity(): int { return $this->data['capacity']; }
    public function bookedCount(): int { return $this->data['booked_count']; }
    public function locationName(): string { return $this->data['location_name']; }
    public function address(): string { return $this->data['address']; }
    public function longitude(): string { return $this->data['longitude']; }
    public function latitude(): string { return $this->data['latitude']; }
    public function pricePerSession(): string { return $this->data['price_per_session']; }
    public function applicablePackageId(): int { return $this->data['applicable_package_id']; }
    public function productId(): int { return $this->data['product_id']; }
    public function status(): int { return $this->data['status']; }

    public function toPublicArray(): array
    {
        return [
            'id' => $this->data['id'],
            'course_type' => $this->data['course_type'],
            'coach_id' => $this->data['coach_id'],
            'title' => $this->data['title'],
            'start_time' => $this->data['start_time'],
            'end_time' => $this->data['end_time'],
            'duration_hours' => $this->data['duration_hours'],
            'capacity' => $this->data['capacity'],
            'booked_count' => $this->data['booked_count'],
            'location' => [
                'name' => $this->data['location_name'],
                'address' => $this->data['address'],
                'longitude' => $this->data['longitude'],
                'latitude' => $this->data['latitude'],
            ],
            'price_per_session' => $this->data['price_per_session'],
            'applicable_package_id' => $this->data['applicable_package_id'],
            'status' => $this->data['status'],
        ];
    }

    private static function decimal(string $value, string $field): string
    {
        if (!preg_match('/^[0-9]+(?:\.[0-9]{1,2})?$/D', $value)) {
            throw new InvalidArgumentException('course_session.' . $field . ' must be a decimal');
        }

        return number_format((float) $value, 2, '.', '');
    }
}
