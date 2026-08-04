<?php

declare(strict_types=1);

namespace app\chamber\membership;

use InvalidArgumentException;

final class MemberProfileSnapshot
{
    private const LIST_STORAGE_FIELDS = [
        'resources' => 'resources_json',
        'needs' => 'needs_json',
        'interests' => 'interests_json',
        'expertise' => 'expertise_json',
    ];

    /** @var int */
    private $profileId;

    /** @var int */
    private $tenantId;

    /** @var int */
    private $memberId;

    /** @var int */
    private $uid;

    /** @var array */
    private $values;

    /** @var MemberProfilePrivacy */
    private $privacy;

    /** @var int */
    private $profileStatus;

    /** @var int */
    private $updatedAt;

    private function __construct()
    {
    }

    public static function fromRow(MemberContext $member, array $row): self
    {
        $snapshot = new self();
        $snapshot->profileId = self::positiveInteger($row, 'id');
        $snapshot->tenantId = self::positiveInteger($row, 'tenant_id');
        $snapshot->memberId = self::positiveInteger($row, 'member_id');
        $snapshot->uid = self::positiveInteger($row, 'uid');

        if ($snapshot->tenantId !== $member->tenantId()
            || $snapshot->memberId !== $member->memberId()
            || $snapshot->uid !== $member->uid()) {
            throw new InvalidArgumentException('Member profile identity is outside the member context');
        }
        if (!$member->hasProfile()) {
            throw new InvalidArgumentException('Member profile is unavailable');
        }

        $values = [];
        foreach (MemberProfilePatch::stringLimits() as $field => $maxLength) {
            $value = self::stringField($row, $field);
            if (!MemberProfilePatch::isValidUtf8String($value, $maxLength)) {
                throw new InvalidArgumentException(sprintf('Stored member profile field %s is invalid', $field));
            }
            $values[$field] = $value;
        }

        $avatarObjectKey = self::stringField($row, 'avatar_object_key');
        if ($avatarObjectKey !== '' && !MemberProfilePatch::isValidObjectStorageKey($avatarObjectKey)) {
            throw new InvalidArgumentException('Stored member profile avatar object key is invalid');
        }
        $values['avatar_object_key'] = $avatarObjectKey;

        $graduationYear = self::nonNegativeInteger($row, 'graduation_year');
        if ($graduationYear !== 0 && ($graduationYear < 1900 || $graduationYear > 2106)) {
            throw new InvalidArgumentException('Stored member profile graduation year is invalid');
        }
        $values['graduation_year'] = $graduationYear;

        foreach (MemberProfilePatch::listLimits() as $field => $itemMaxLength) {
            $values[$field] = self::decodeStringList(
                self::nullableField($row, self::LIST_STORAGE_FIELDS[$field]),
                $field,
                $itemMaxLength
            );
        }

        $snapshot->values = self::orderedValues($values);
        $snapshot->privacy = MemberProfilePrivacy::fromStoredJson(
            self::nullableField($row, 'privacy_json')
        );
        $snapshot->profileStatus = self::nonNegativeInteger($row, 'profile_status');
        if (!in_array($snapshot->profileStatus, [0, 1, 2], true)) {
            throw new InvalidArgumentException('Stored member profile status is invalid');
        }
        if (self::nonNegativeInteger($row, 'is_del') !== 0) {
            throw new InvalidArgumentException('Stored member profile is deleted');
        }
        $snapshot->updatedAt = self::nonNegativeInteger($row, 'update_time');

        return $snapshot;
    }

    public function apply(MemberProfilePatch $patch, int $now): self
    {
        if ($now < 0 || $now > 4294967295) {
            throw new InvalidArgumentException('Member profile update time is invalid');
        }

        $copy = clone $this;
        foreach ($patch->values() as $field => $value) {
            if ($field === 'privacy') {
                $copy->privacy = $copy->privacy->withPatch($value);
            } else {
                $copy->values[$field] = $value;
            }
        }

        if ($copy->profileStatus !== 0) {
            $copy->profileStatus = trim($copy->values['real_name']) === '' ? 2 : 1;
        }
        if (!$copy->sameContentAs($this)) {
            $copy->updatedAt = $now;
        }

        return $copy;
    }

    public function databaseChangesFrom(self $previous): array
    {
        $this->assertSameIdentity($previous);
        $changes = [];

        foreach (MemberProfilePatch::stringLimits() as $field => $unused) {
            if ($this->values[$field] !== $previous->values[$field]) {
                $changes[$field] = $this->values[$field];
            }
        }
        if ($this->values['avatar_object_key'] !== $previous->values['avatar_object_key']) {
            $changes['avatar_object_key'] = $this->values['avatar_object_key'];
        }
        if ($this->values['graduation_year'] !== $previous->values['graduation_year']) {
            $changes['graduation_year'] = $this->values['graduation_year'];
        }
        foreach (self::LIST_STORAGE_FIELDS as $field => $storageField) {
            if ($this->values[$field] !== $previous->values[$field]) {
                $changes[$storageField] = self::encodeJson($this->values[$field]);
            }
        }
        if ($this->privacy->toArray() !== $previous->privacy->toArray()) {
            $changes['privacy_json'] = $this->privacy->toJson();
        }
        if ($this->profileStatus !== $previous->profileStatus) {
            $changes['profile_status'] = $this->profileStatus;
        }
        if ($changes !== []) {
            $changes['update_time'] = $this->updatedAt;
        }

        return $changes;
    }

    public function profileId(): int
    {
        return $this->profileId;
    }

    public function tenantId(): int
    {
        return $this->tenantId;
    }

    public function memberId(): int
    {
        return $this->memberId;
    }

    public function uid(): int
    {
        return $this->uid;
    }

    public function profileComplete(): bool
    {
        return in_array($this->profileStatus, [0, 1], true);
    }

    public function updatedAt(): int
    {
        return $this->updatedAt;
    }

    public function toArray(): array
    {
        return [
            'real_name' => $this->values['real_name'],
            'avatar_object_key' => $this->values['avatar_object_key'],
            'class_name' => $this->values['class_name'],
            'graduation_year' => $this->values['graduation_year'],
            'industry' => $this->values['industry'],
            'company_name' => $this->values['company_name'],
            'job_title' => $this->values['job_title'],
            'main_business' => $this->values['main_business'],
            'province' => $this->values['province'],
            'city' => $this->values['city'],
            'bio' => $this->values['bio'],
            'resources' => $this->values['resources'],
            'needs' => $this->values['needs'],
            'interests' => $this->values['interests'],
            'expertise' => $this->values['expertise'],
            'privacy' => $this->privacy->toArray(),
            'profile_complete' => $this->profileComplete(),
            'updated_at' => $this->updatedAt,
        ];
    }

    private function sameContentAs(self $other): bool
    {
        return $this->values === $other->values
            && $this->privacy->toArray() === $other->privacy->toArray()
            && $this->profileStatus === $other->profileStatus;
    }

    private function assertSameIdentity(self $other): void
    {
        if ($this->profileId !== $other->profileId
            || $this->tenantId !== $other->tenantId
            || $this->memberId !== $other->memberId
            || $this->uid !== $other->uid) {
            throw new InvalidArgumentException('Member profile snapshots do not share an identity');
        }
    }

    private static function orderedValues(array $values): array
    {
        return [
            'real_name' => $values['real_name'],
            'avatar_object_key' => $values['avatar_object_key'],
            'class_name' => $values['class_name'],
            'graduation_year' => $values['graduation_year'],
            'industry' => $values['industry'],
            'company_name' => $values['company_name'],
            'job_title' => $values['job_title'],
            'main_business' => $values['main_business'],
            'province' => $values['province'],
            'city' => $values['city'],
            'bio' => $values['bio'],
            'resources' => $values['resources'],
            'needs' => $values['needs'],
            'interests' => $values['interests'],
            'expertise' => $values['expertise'],
        ];
    }

    private static function decodeStringList($encoded, string $field, int $itemMaxLength): array
    {
        if ($encoded === null) {
            return [];
        }
        if (!is_string($encoded) || $encoded === '') {
            throw new InvalidArgumentException(sprintf('Stored member profile field %s is invalid JSON', $field));
        }

        $decoded = json_decode($encoded, true);
        if (!is_array($decoded)
            || json_last_error() !== JSON_ERROR_NONE
            || !MemberProfilePatch::isList($decoded)
            || count($decoded) > 30) {
            throw new InvalidArgumentException(sprintf('Stored member profile field %s is invalid', $field));
        }
        foreach ($decoded as $item) {
            if (!MemberProfilePatch::isValidUtf8String($item, $itemMaxLength, true)) {
                throw new InvalidArgumentException(sprintf('Stored member profile field %s is invalid', $field));
            }
        }

        return $decoded;
    }

    private static function encodeJson(array $value): string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($encoded) || json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidArgumentException('Member profile field cannot be encoded');
        }

        return $encoded;
    }

    private static function nullableField(array $row, string $field)
    {
        if (!array_key_exists($field, $row)) {
            throw new InvalidArgumentException(sprintf('Missing member profile field %s', $field));
        }

        return $row[$field];
    }

    private static function stringField(array $row, string $field): string
    {
        if (!array_key_exists($field, $row) || !is_string($row[$field])) {
            throw new InvalidArgumentException(sprintf('Member profile field %s must be a string', $field));
        }

        return $row[$field];
    }

    private static function positiveInteger(array $row, string $field): int
    {
        $value = self::nonNegativeInteger($row, $field);
        if ($value <= 0) {
            throw new InvalidArgumentException(sprintf('Member profile field %s must be positive', $field));
        }

        return $value;
    }

    private static function nonNegativeInteger(array $row, string $field): int
    {
        if (!array_key_exists($field, $row) || !is_int($row[$field]) || $row[$field] < 0) {
            throw new InvalidArgumentException(sprintf('Member profile field %s must be a non-negative integer', $field));
        }

        return $row[$field];
    }
}
