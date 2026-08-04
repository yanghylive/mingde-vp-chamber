<?php

declare(strict_types=1);

namespace app\chamber\verification;

use app\chamber\membership\GraduateVerificationState;

final class GraduateVerificationAdminQuery
{
    /** @var string|null */
    private $status;

    /** @var string */
    private $keyword;

    /** @var int */
    private $page;

    /** @var int */
    private $perPage;

    private function __construct()
    {
    }

    public static function fromArray(array $query): self
    {
        foreach (array_keys($query) as $field) {
            if (!is_string($field) || !in_array($field, ['status', 'keyword', 'page', 'per_page'], true)) {
                $name = is_string($field) ? $field : 'query';
                throw new GraduateVerificationValidationException(
                    $name,
                    'unknown_field',
                    sprintf('Unknown graduate verification query field: %s', $name)
                );
            }
        }

        $instance = new self();
        $instance->status = self::status($query['status'] ?? null);
        $instance->keyword = self::keyword($query['keyword'] ?? null);
        $instance->page = self::boundedInteger($query['page'] ?? null, 'page', 1, PHP_INT_MAX, 1);
        $instance->perPage = self::boundedInteger($query['per_page'] ?? null, 'per_page', 1, 100, 20);

        return $instance;
    }

    public function status(): ?string
    {
        return $this->status;
    }

    public function keyword(): string
    {
        return $this->keyword;
    }

    public function page(): int
    {
        return $this->page;
    }

    public function perPage(): int
    {
        return $this->perPage;
    }

    private static function status($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || !GraduateVerificationState::isValid($value)) {
            throw new GraduateVerificationValidationException(
                'status',
                'invalid_value',
                'status is not a valid graduate verification state'
            );
        }

        return $value;
    }

    private static function boundedInteger($value, string $field, int $minimum, int $maximum, int $default): int
    {
        if ($value === null || $value === '') {
            return $default;
        }
        if (is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value)) {
            $parsed = (int) $value;
            if ((string) $parsed === $value) {
                $value = $parsed;
            }
        }
        if (!is_int($value) || $value < $minimum || $value > $maximum) {
            throw new GraduateVerificationValidationException(
                $field,
                'out_of_range',
                sprintf('%s is out of range', $field)
            );
        }

        return $value;
    }

    private static function keyword($value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if (!is_string($value)) {
            throw new GraduateVerificationValidationException(
                'keyword',
                'invalid_type',
                'keyword must be a string'
            );
        }

        $value = trim($value);
        $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
        if ($length > 80) {
            throw new GraduateVerificationValidationException(
                'keyword',
                'invalid_length',
                'keyword must contain at most 80 characters'
            );
        }

        return $value;
    }
}
