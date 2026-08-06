<?php

declare(strict_types=1);

namespace app\chamber\activity;

use app\chamber\exceptions\MemberTransactionException;

final class EventRegistrationListQuery
{
    private const STATUSES = [
        'pending_payment' => 0,
        'registered' => 1,
        'cancelled' => 2,
        'refunded' => 3,
        'waitlisted' => 4,
        'completed' => 5,
    ];

    /** @var string|null */
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
            if (!is_string($field) || !in_array($field, ['status', 'page', 'limit'], true)) {
                $name = is_string($field) ? $field : 'query';
                throw self::validation($name, 'unknown_field', 'Unknown event registration query field: ' . $name);
            }
        }

        $instance = new self();
        $status = $query['status'] ?? null;
        if ($status === null || $status === '') {
            $instance->status = null;
        } elseif (!is_string($status) || !array_key_exists($status, self::STATUSES)) {
            throw self::validation('status', 'invalid_value', 'status is invalid');
        } else {
            $instance->status = $status;
        }
        $instance->page = self::boundedInteger($query['page'] ?? null, 'page', 1, PHP_INT_MAX, 1);
        $instance->limit = self::boundedInteger($query['limit'] ?? null, 'limit', 1, 100, 20);

        return $instance;
    }

    public function status(): ?string
    {
        return $this->status;
    }

    public function databaseStatus(): ?int
    {
        return $this->status === null ? null : self::STATUSES[$this->status];
    }

    public function page(): int
    {
        return $this->page;
    }

    public function limit(): int
    {
        return $this->limit;
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
