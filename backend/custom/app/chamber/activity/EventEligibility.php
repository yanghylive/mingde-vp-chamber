<?php

declare(strict_types=1);

namespace app\chamber\activity;

use app\chamber\exceptions\MemberTransactionException;

/** Pure qualification rules shared by event listing, registration and tests. */
final class EventEligibility
{
    public const EVENT_DRAFT = 0;
    public const EVENT_PUBLISHED = 1;
    public const EVENT_REGISTRATION_CLOSED = 2;
    public const EVENT_ENDED = 3;
    public const EVENT_CANCELLED = 4;

    public static function normalizeRules($value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
                throw new MemberTransactionException(422, 'request_validation_failed', 'eligibility rules are invalid');
            }
            $value = $decoded;
        }
        if (!is_array($value)) {
            throw new MemberTransactionException(422, 'request_validation_failed', 'eligibility rules are invalid');
        }
        $allowed = ['allowed_channel_ids', 'min_points', 'required_roles'];
        foreach (array_keys($value) as $key) {
            if (!in_array($key, $allowed, true)) {
                throw new MemberTransactionException(422, 'request_validation_failed', 'eligibility contains an unknown field');
            }
        }

        $channels = [];
        if (isset($value['allowed_channel_ids'])) {
            if (!is_array($value['allowed_channel_ids'])) {
                throw new MemberTransactionException(422, 'request_validation_failed', 'allowed_channel_ids must be a list');
            }
            foreach ($value['allowed_channel_ids'] as $id) {
                if (!is_int($id) && !(is_string($id) && preg_match('/^[1-9][0-9]*$/D', $id))) {
                    throw new MemberTransactionException(422, 'request_validation_failed', 'allowed_channel_ids contains an invalid id');
                }
                $parsed = (int) $id;
                if ($parsed <= 0) {
                    throw new MemberTransactionException(422, 'request_validation_failed', 'allowed_channel_ids contains an invalid id');
                }
                $channels[$parsed] = true;
            }
        }
        $roles = [];
        if (isset($value['required_roles'])) {
            if (!is_array($value['required_roles'])) {
                throw new MemberTransactionException(422, 'request_validation_failed', 'required_roles must be a list');
            }
            foreach ($value['required_roles'] as $role) {
                if (!is_string($role) || preg_match('/^[a-z][a-z0-9_]{1,31}$/D', $role) !== 1) {
                    throw new MemberTransactionException(422, 'request_validation_failed', 'required_roles contains an invalid code');
                }
                $roles[$role] = true;
            }
        }
        $minPoints = 0;
        if (isset($value['min_points'])) {
            if (!is_int($value['min_points']) && !(is_string($value['min_points']) && preg_match('/^(0|[1-9][0-9]*)$/D', $value['min_points']))) {
                throw new MemberTransactionException(422, 'request_validation_failed', 'min_points must be a non-negative integer');
            }
            $minPoints = (int) $value['min_points'];
        }

        return [
            'allowed_channel_ids' => array_map('intval', array_keys($channels)),
            'min_points' => $minPoints,
            'required_roles' => array_keys($roles),
        ];
    }

    public static function reason(
        array $event,
        array $ticket,
        array $member,
        array $roleCodes,
        int $points,
        int $now,
        bool $hasCapacity = true
    ): ?string {
        $eventStatus = (int) ($event['status'] ?? self::EVENT_DRAFT);
        if (in_array($eventStatus, [self::EVENT_DRAFT, self::EVENT_CANCELLED], true)) {
            return 'event_not_open';
        }
        if ($eventStatus === self::EVENT_ENDED) {
            return 'event_started';
        }
        if ($eventStatus === self::EVENT_REGISTRATION_CLOSED) {
            return 'signup_closed';
        }
        if ((int) ($event['signup_start_time'] ?? 0) > $now) {
            return 'signup_not_open';
        }
        if ((int) ($event['signup_end_time'] ?? 0) > 0 && (int) $event['signup_end_time'] < $now) {
            return 'signup_closed';
        }
        if ((int) ($event['start_time'] ?? 0) <= $now) {
            return 'event_started';
        }
        if ((int) ($ticket['status'] ?? 0) !== 1) {
            return 'signup_closed';
        }
        if ((int) ($ticket['sale_start_time'] ?? 0) > $now) {
            return 'signup_not_open';
        }
        if ((int) ($ticket['sale_end_time'] ?? 0) > 0 && (int) $ticket['sale_end_time'] < $now) {
            return 'signup_closed';
        }
        if (!$hasCapacity) {
            return 'event_full';
        }
        $tier = (int) ($member['tier'] ?? 0);
        $minimumTier = max((int) ($event['min_tier'] ?? 1), (int) ($ticket['min_tier'] ?? 1));
        if ($tier < $minimumTier) {
            return 'membership_tier_required';
        }
        if ($minimumTier >= 2 && (int) ($member['verification_status'] ?? 0) !== 2) {
            return 'membership_verification_required';
        }
        $rules = self::normalizeRules($event['eligibility_json'] ?? []);
        $ticketRules = self::normalizeRules($ticket['eligibility_json'] ?? []);
        $rules = self::mergeRules($rules, $ticketRules);
        if ($rules['allowed_channel_ids'] !== []
            && !in_array((int) ($member['current_channel_id'] ?? 0), $rules['allowed_channel_ids'], true)) {
            return 'channel_not_eligible';
        }
        if ($points < $rules['min_points']) {
            return 'points_required';
        }
        if ($rules['required_roles'] !== [] && count(array_intersect($rules['required_roles'], $roleCodes)) === 0) {
            return 'role_required';
        }

        return null;
    }

    private static function mergeRules(array $event, array $ticket): array
    {
        return [
            'allowed_channel_ids' => $ticket['allowed_channel_ids'] !== []
                ? $ticket['allowed_channel_ids'] : $event['allowed_channel_ids'],
            'min_points' => max($event['min_points'], $ticket['min_points']),
            'required_roles' => array_values(array_unique(array_merge($event['required_roles'], $ticket['required_roles']))),
        ];
    }
}
