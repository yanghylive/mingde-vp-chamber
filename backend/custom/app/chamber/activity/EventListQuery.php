<?php

declare(strict_types=1);

namespace app\chamber\activity;

use app\chamber\exceptions\MemberTransactionException;

/** Strict user-facing event list filters. */
final class EventListQuery
{
    private const EVENT_TYPES = ['growth', 'industry', 'public_welfare'];
    private const EVENT_STATUSES = [
        'draft' => EventEligibility::EVENT_DRAFT,
        'published' => EventEligibility::EVENT_PUBLISHED,
        'registration_closed' => EventEligibility::EVENT_REGISTRATION_CLOSED,
        'ended' => EventEligibility::EVENT_ENDED,
        'cancelled' => EventEligibility::EVENT_CANCELLED,
    ];

    /** @var string|null */
    private $eventType;

    /** @var string|null */
    private $status;

    /** @var string */
    private $tag;

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
            if (!is_string($field) || !in_array($field, ['event_type', 'status', 'tag', 'page', 'limit'], true)) {
                $name = is_string($field) ? $field : 'query';
                throw self::validation($name, 'unknown_field', 'Unknown event query field: ' . $name);
            }
        }

        $instance = new self();
        $instance->eventType = self::optionalEnum(
            $query['event_type'] ?? null,
            'event_type',
            self::EVENT_TYPES
        );
        $instance->status = self::optionalEnum(
            $query['status'] ?? null,
            'status',
            array_keys(self::EVENT_STATUSES)
        );
        $instance->tag = self::parseTag($query['tag'] ?? null);
        $instance->page = self::boundedInteger($query['page'] ?? null, 'page', 1, PHP_INT_MAX, 1);
        $instance->limit = self::boundedInteger($query['limit'] ?? null, 'limit', 1, 100, 20);

        return $instance;
    }

    public function eventType(): ?string
    {
        return $this->eventType;
    }

    public function status(): ?string
    {
        return $this->status;
    }

    public function databaseStatus(): ?int
    {
        return $this->status === null ? null : self::EVENT_STATUSES[$this->status];
    }

    public function tag(): string
    {
        return $this->tag;
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

    private static function parseTag($value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if (!is_string($value)) {
            throw self::validation('tag', 'invalid_type', 'tag must be a string');
        }
        $value = trim($value);
        $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
        if ($value === '' || $length > 40) {
            throw self::validation('tag', 'invalid_length', 'tag must contain between 1 and 40 characters');
        }

        return $value;
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
