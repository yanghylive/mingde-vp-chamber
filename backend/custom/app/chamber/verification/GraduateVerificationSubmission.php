<?php

declare(strict_types=1);

namespace app\chamber\verification;

final class GraduateVerificationSubmission
{
    private const ALLOWED_FIELDS = [
        'class_name',
        'graduation_year',
        'graduation_at',
        'proof_object_keys',
        'supersedes_id',
    ];

    /** @var string */
    private $className;

    /** @var int */
    private $graduationYear;

    /** @var int */
    private $graduationAt;

    /** @var string[] */
    private $proofObjectKeys;

    /** @var int */
    private $supersedesId;

    private function __construct()
    {
    }

    public static function fromArray(array $payload): self
    {
        self::assertAllowedFields($payload);

        $submission = new self();
        $submission->className = self::requiredString($payload, 'class_name', 80);
        $submission->graduationYear = self::boundedInteger($payload, 'graduation_year', 1900, 2106);
        $submission->graduationAt = self::optionalUnsignedTimestamp($payload, 'graduation_at');
        $submission->proofObjectKeys = self::proofObjectKeys($payload);
        $submission->supersedesId = self::optionalPositiveInteger($payload, 'supersedes_id');

        return $submission;
    }

    public function className(): string
    {
        return $this->className;
    }

    public function graduationYear(): int
    {
        return $this->graduationYear;
    }

    public function graduationAt(): int
    {
        return $this->graduationAt;
    }

    public function proofObjectKeys(): array
    {
        return $this->proofObjectKeys;
    }

    public function supersedesId(): int
    {
        return $this->supersedesId;
    }

    public function toCanonicalArray(): array
    {
        return [
            'class_name' => $this->className,
            'graduation_year' => $this->graduationYear,
            'graduation_at' => $this->graduationAt,
            'proof_object_keys' => $this->proofObjectKeys,
            'supersedes_id' => $this->supersedesId,
        ];
    }

    public static function assertObjectStorageKey($value, string $field): string
    {
        if (!is_string($value) || strlen($value) < 1 || strlen($value) > 255
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/-]*$/D', $value) !== 1
            || strpos($value, '//') !== false || substr($value, -1) === '/') {
            throw new GraduateVerificationValidationException(
                $field,
                'invalid_format',
                sprintf('%s must be a private object-storage key', $field)
            );
        }

        foreach (explode('/', $value) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new GraduateVerificationValidationException(
                    $field,
                    'invalid_format',
                    sprintf('%s must be a private object-storage key', $field)
                );
            }
        }

        return $value;
    }

    private static function assertAllowedFields(array $payload): void
    {
        foreach (array_keys($payload) as $field) {
            if (!is_string($field) || !in_array($field, self::ALLOWED_FIELDS, true)) {
                $name = is_string($field) ? $field : 'body';
                throw new GraduateVerificationValidationException(
                    $name,
                    'unknown_field',
                    sprintf('Unknown graduate verification field: %s', $name)
                );
            }
        }
    }

    private static function requiredString(array $payload, string $field, int $maximumLength): string
    {
        if (!array_key_exists($field, $payload) || !is_string($payload[$field])) {
            throw new GraduateVerificationValidationException(
                $field,
                'required',
                sprintf('%s is required and must be a string', $field)
            );
        }

        $value = trim($payload[$field]);
        $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
        if ($value === '' || $length > $maximumLength) {
            throw new GraduateVerificationValidationException(
                $field,
                'invalid_length',
                sprintf('%s must contain between 1 and %d characters', $field, $maximumLength)
            );
        }

        return $value;
    }

    private static function boundedInteger(array $payload, string $field, int $minimum, int $maximum): int
    {
        if (!array_key_exists($field, $payload) || !is_int($payload[$field])
            || $payload[$field] < $minimum || $payload[$field] > $maximum) {
            throw new GraduateVerificationValidationException(
                $field,
                'out_of_range',
                sprintf('%s must be an integer between %d and %d', $field, $minimum, $maximum)
            );
        }

        return $payload[$field];
    }

    private static function optionalUnsignedTimestamp(array $payload, string $field): int
    {
        if (!array_key_exists($field, $payload)) {
            return 0;
        }
        if (!is_int($payload[$field]) || $payload[$field] < 0 || $payload[$field] > 4294967295) {
            throw new GraduateVerificationValidationException(
                $field,
                'out_of_range',
                sprintf('%s must be an unsigned Unix timestamp in seconds', $field)
            );
        }

        return $payload[$field];
    }

    private static function optionalPositiveInteger(array $payload, string $field): int
    {
        if (!array_key_exists($field, $payload)) {
            return 0;
        }
        if (!is_int($payload[$field]) || $payload[$field] <= 0) {
            throw new GraduateVerificationValidationException(
                $field,
                'invalid_value',
                sprintf('%s must be a positive integer', $field)
            );
        }

        return $payload[$field];
    }

    private static function proofObjectKeys(array $payload): array
    {
        $field = 'proof_object_keys';
        if (!array_key_exists($field, $payload) || !is_array($payload[$field])
            || !self::isList($payload[$field]) || count($payload[$field]) < 1
            || count($payload[$field]) > 10) {
            throw new GraduateVerificationValidationException(
                $field,
                'invalid_value',
                'proof_object_keys must be a list containing between 1 and 10 keys'
            );
        }

        $keys = [];
        foreach ($payload[$field] as $index => $key) {
            $key = self::assertObjectStorageKey($key, sprintf('%s[%d]', $field, $index));
            if (isset($keys[$key])) {
                throw new GraduateVerificationValidationException(
                    $field,
                    'duplicate_value',
                    'proof_object_keys must not contain duplicates'
                );
            }
            $keys[$key] = true;
        }

        return array_keys($keys);
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
