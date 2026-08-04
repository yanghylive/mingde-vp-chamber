<?php

declare(strict_types=1);

namespace app\chamber\membership;

use InvalidArgumentException;

final class MemberProfilePrivacy
{
    public const PRIVATE_SCOPE = 'private';
    public const MEMBERS_SCOPE = 'members';
    public const FRIENDS_SCOPE = 'friends';
    public const PUBLIC_SCOPE = 'public';

    private const FIELDS = [
        'avatar_object_key',
        'real_name',
        'class_name',
        'graduation_year',
        'industry',
        'company_name',
        'job_title',
        'main_business',
        'province',
        'city',
        'bio',
        'resources',
        'needs',
        'interests',
        'expertise',
    ];

    private const SCOPES = [
        self::PRIVATE_SCOPE,
        self::MEMBERS_SCOPE,
        self::FRIENDS_SCOPE,
        self::PUBLIC_SCOPE,
    ];

    /** @var array */
    private $scopes;

    private function __construct(array $scopes)
    {
        $this->scopes = $scopes;
    }

    public static function fromStoredJson($encoded): self
    {
        if ($encoded === null) {
            return self::privateByDefault();
        }
        if (!is_string($encoded) || $encoded === '') {
            throw new InvalidArgumentException('Stored member profile privacy must be a JSON object');
        }

        $decoded = json_decode($encoded, true);
        if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidArgumentException('Stored member profile privacy must be a JSON object');
        }

        return self::fromStoredArray($decoded);
    }

    public static function fromStoredArray(array $stored): self
    {
        $scopes = self::defaultScopes();
        foreach ($stored as $field => $scope) {
            if (!is_string($field) || !self::isField($field)) {
                throw new InvalidArgumentException('Stored member profile privacy contains an unknown field');
            }
            if (!is_string($scope) || !self::isScope($scope)) {
                throw new InvalidArgumentException('Stored member profile privacy contains an invalid scope');
            }
            $scopes[$field] = $scope;
        }

        return new self($scopes);
    }

    public static function privateByDefault(): self
    {
        return new self(self::defaultScopes());
    }

    public function withPatch(array $patch): self
    {
        $scopes = $this->scopes;
        foreach ($patch as $field => $scope) {
            if (!is_string($field) || !self::isField($field)) {
                throw new InvalidArgumentException('Member profile privacy patch contains an unknown field');
            }
            if (!is_string($scope) || !self::isScope($scope)) {
                throw new InvalidArgumentException('Member profile privacy patch contains an invalid scope');
            }
            $scopes[$field] = $scope;
        }

        return new self($scopes);
    }

    public function toArray(): array
    {
        return $this->scopes;
    }

    public function toJson(): string
    {
        $encoded = json_encode($this->scopes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($encoded) || json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidArgumentException('Member profile privacy cannot be encoded');
        }

        return $encoded;
    }

    public static function fields(): array
    {
        return self::FIELDS;
    }

    public static function isField(string $field): bool
    {
        return in_array($field, self::FIELDS, true);
    }

    public static function isScope(string $scope): bool
    {
        return in_array($scope, self::SCOPES, true);
    }

    private static function defaultScopes(): array
    {
        return array_fill_keys(self::FIELDS, self::PRIVATE_SCOPE);
    }
}
