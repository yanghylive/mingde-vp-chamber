<?php

declare(strict_types=1);

namespace app\chamber\membership;

use InvalidArgumentException;

/** 课时划扣规则：私教=时长；小班=时长；家庭=单人1.5/多人各1；下场=3（或现金付费）。 */
final class CourseConsumptionPolicy
{
    public const TYPE_PRIVATE = 'private';
    public const TYPE_GROUP = 'group';
    public const TYPE_FAMILY = 'family';
    public const TYPE_ONCOURSE = 'oncourse';

    /**
     * @return array{required_hours:string, rule:string}
     */
    public static function resolve(string $courseType, int $participantCount, string $sessionDurationHours): array
    {
        $duration = self::decimal($sessionDurationHours, 'session_duration_hours');
        switch ($courseType) {
            case self::TYPE_PRIVATE:
                return ['required_hours' => $duration, 'rule' => 'private'];
            case self::TYPE_GROUP:
                return ['required_hours' => $duration, 'rule' => 'group'];
            case self::TYPE_FAMILY:
                if ($participantCount <= 1) {
                    return ['required_hours' => self::decimal('1.50'), 'rule' => 'family_single'];
                }

                return [
                    'required_hours' => self::decimal(number_format($participantCount * 1.0, 2, '.', ''), 'participant_count'),
                    'rule' => 'family_multi',
                ];
            case self::TYPE_ONCOURSE:
                return ['required_hours' => self::decimal('3.00'), 'rule' => 'oncourse'];
            default:
                throw new InvalidArgumentException('Unknown course_type: ' . $courseType);
        }
    }

    public static function isFamily(string $rule): bool
    {
        return $rule === 'family_single' || $rule === 'family_multi';
    }

    private static function decimal(string $value, string $field = ''): string
    {
        if (!preg_match('/^[0-9]+(?:\.[0-9]{1,2})?$/D', $value)) {
            throw new InvalidArgumentException('Invalid decimal value' . ($field !== '' ? ' for ' . $field : ''));
        }

        return number_format((float) $value, 2, '.', '');
    }
}
