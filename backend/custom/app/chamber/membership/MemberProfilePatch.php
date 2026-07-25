<?php

declare(strict_types=1);

namespace app\chamber\membership;

use app\chamber\exceptions\MemberTransactionException;

final class MemberProfilePatch
{
    private const STRING_LIMITS = [
        'real_name' => 40,
        'class_name' => 80,
        'industry' => 80,
        'company_name' => 120,
        'job_title' => 80,
        'main_business' => 500,
        'province' => 40,
        'city' => 40,
        'bio' => 1000,
    ];

    private const LIST_LIMITS = [
        'resources' => 100,
        'needs' => 100,
        'interests' => 60,
        'expertise' => 60,
    ];

    private const MAX_LIST_ITEMS = 30;

    /** @var array */
    private $values;

    private function __construct(array $values)
    {
        $this->values = $values;
    }

    public static function fromArray(array $input): self
    {
        if ($input === []) {
            self::reject([['field' => 'body', 'code' => 'min_properties']]);
        }

        $errors = [];
        $values = [];
        foreach ($input as $field => $value) {
            if (!is_string($field) || !self::isAllowedField($field)) {
                $errors[] = [
                    'field' => self::safeFieldName($field, 'body'),
                    'code' => 'unknown_field',
                ];
                continue;
            }

            if (array_key_exists($field, self::STRING_LIMITS)) {
                $error = self::validateString($value, self::STRING_LIMITS[$field], $field === 'real_name');
                if ($error !== null) {
                    $errors[] = ['field' => $field, 'code' => $error];
                    continue;
                }
                $values[$field] = $value;
                continue;
            }

            if ($field === 'avatar_object_key') {
                if (!is_string($value)) {
                    $errors[] = ['field' => $field, 'code' => 'invalid_type'];
                } elseif (!self::isValidObjectStorageKey($value)) {
                    $errors[] = ['field' => $field, 'code' => 'invalid_format'];
                } else {
                    $values[$field] = $value;
                }
                continue;
            }

            if ($field === 'graduation_year') {
                if (!is_int($value)) {
                    $errors[] = ['field' => $field, 'code' => 'invalid_type'];
                } elseif ($value < 1900 || $value > 2106) {
                    $errors[] = ['field' => $field, 'code' => 'out_of_range'];
                } else {
                    $values[$field] = $value;
                }
                continue;
            }

            if (array_key_exists($field, self::LIST_LIMITS)) {
                $listErrors = self::validateStringList($field, $value, self::LIST_LIMITS[$field]);
                if ($listErrors !== []) {
                    $errors = array_merge($errors, $listErrors);
                } else {
                    $values[$field] = $value;
                }
                continue;
            }

            if ($field === 'privacy') {
                $privacyErrors = self::validatePrivacy($value);
                if ($privacyErrors !== []) {
                    $errors = array_merge($errors, $privacyErrors);
                } else {
                    $values[$field] = $value;
                }
            }
        }

        if ($errors !== []) {
            self::reject($errors);
        }

        return new self($values);
    }

    public function has(string $field): bool
    {
        return array_key_exists($field, $this->values);
    }

    public function value(string $field)
    {
        return $this->has($field) ? $this->values[$field] : null;
    }

    public function values(): array
    {
        return $this->values;
    }

    public function toCanonicalArray(): array
    {
        return $this->values;
    }

    public static function stringLimits(): array
    {
        return self::STRING_LIMITS;
    }

    public static function listLimits(): array
    {
        return self::LIST_LIMITS;
    }

    public static function isValidUtf8String($value, int $maxLength, bool $nonBlank = false): bool
    {
        if (!is_string($value) || preg_match('//u', $value) !== 1) {
            return false;
        }
        if ($nonBlank && trim($value) === '') {
            return false;
        }

        return self::utf8Length($value) <= $maxLength;
    }

    public static function isValidObjectStorageKey(string $value): bool
    {
        if (strlen($value) < 1 || strlen($value) > 255) {
            return false;
        }

        return preg_match(
            '~^(?!https?://)(?!/)(?!.*//)(?!.*(?:^|/)\.{1,2}(?:/|$))(?!.*\/$)[A-Za-z0-9][A-Za-z0-9._/-]*$~D',
            $value
        ) === 1;
    }

    public static function isList(array $value): bool
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

    private static function isAllowedField(string $field): bool
    {
        return array_key_exists($field, self::STRING_LIMITS)
            || array_key_exists($field, self::LIST_LIMITS)
            || in_array($field, ['avatar_object_key', 'graduation_year', 'privacy'], true);
    }

    private static function validateString($value, int $maxLength, bool $nonBlank): ?string
    {
        if (!is_string($value)) {
            return 'invalid_type';
        }
        if (preg_match('//u', $value) !== 1) {
            return 'invalid_encoding';
        }
        if ($nonBlank && trim($value) === '') {
            return 'required';
        }
        if (self::utf8Length($value) > $maxLength) {
            return 'too_long';
        }

        return null;
    }

    private static function validateStringList(string $field, $value, int $itemMaxLength): array
    {
        if (!is_array($value) || !self::isList($value)) {
            return [['field' => $field, 'code' => 'invalid_type']];
        }
        if (count($value) > self::MAX_LIST_ITEMS) {
            return [['field' => $field, 'code' => 'too_many_items']];
        }

        $errors = [];
        foreach ($value as $index => $item) {
            $itemField = sprintf('%s[%d]', $field, $index);
            if (!is_string($item)) {
                $errors[] = ['field' => $itemField, 'code' => 'invalid_type'];
            } elseif (preg_match('//u', $item) !== 1) {
                $errors[] = ['field' => $itemField, 'code' => 'invalid_encoding'];
            } elseif (trim($item) === '') {
                $errors[] = ['field' => $itemField, 'code' => 'required'];
            } elseif (self::utf8Length($item) > $itemMaxLength) {
                $errors[] = ['field' => $itemField, 'code' => 'too_long'];
            }
        }

        return $errors;
    }

    private static function validatePrivacy($value): array
    {
        if (!is_array($value)) {
            return [['field' => 'privacy', 'code' => 'invalid_type']];
        }

        $errors = [];
        foreach ($value as $field => $scope) {
            if (!is_string($field) || !MemberProfilePrivacy::isField($field)) {
                $errors[] = [
                    'field' => self::safeFieldName($field, 'privacy'),
                    'code' => 'unknown_field',
                ];
            } elseif (!is_string($scope)) {
                $errors[] = ['field' => 'privacy.' . $field, 'code' => 'invalid_type'];
            } elseif (!MemberProfilePrivacy::isScope($scope)) {
                $errors[] = ['field' => 'privacy.' . $field, 'code' => 'invalid_value'];
            }
        }

        return $errors;
    }

    private static function safeFieldName($field, string $fallback): string
    {
        if (!is_string($field)
            || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,100}$/D', $field)) {
            return $fallback;
        }

        return $fallback === 'privacy' ? 'privacy.' . $field : $field;
    }

    private static function utf8Length(string $value): int
    {
        if (function_exists('mb_strlen')) {
            return mb_strlen($value, 'UTF-8');
        }

        $matches = preg_match_all('/./us', $value, $unused);

        return is_int($matches) ? $matches : strlen($value);
    }

    private static function reject(array $errors): void
    {
        throw new MemberTransactionException(
            422,
            'request_validation_failed',
            'Member profile patch validation failed',
            array_values($errors)
        );
    }
}
