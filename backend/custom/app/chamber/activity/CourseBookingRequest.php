<?php

declare(strict_types=1);

namespace app\chamber\activity;

use InvalidArgumentException;

/** 约课请求 DTO（家庭卡需传参与人数）。 */
final class CourseBookingRequest
{
    private const FIELDS = ['participants', 'remark'];

    /** @var int */
    private $participants;

    /** @var string */
    private $remark;

    private function __construct(int $participants, string $remark)
    {
        $this->participants = $participants;
        $this->remark = $remark;
    }

    public static function fromArray(array $input): self
    {
        foreach (array_keys($input) as $field) {
            if (!is_string($field) || !in_array($field, self::FIELDS, true)) {
                throw new InvalidArgumentException('course booking request contains an unknown field');
            }
        }
        $participants = isset($input['participants']) && $input['participants'] !== null
            ? self::assertIntRange($input['participants'], 'participants', 1, 20)
            : 1;
        $remark = isset($input['remark']) && is_string($input['remark'])
            ? mb_substr(trim($input['remark']), 0, 200)
            : '';

        return new self($participants, $remark);
    }

    public function participants(): int
    {
        return $this->participants;
    }

    public function remark(): string
    {
        return $this->remark;
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
}
