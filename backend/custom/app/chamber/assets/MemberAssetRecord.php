<?php

declare(strict_types=1);

namespace app\chamber\assets;

use InvalidArgumentException;

final class MemberAssetRecord
{
    public const STATUS_READY = 1;
    public const STATUS_CONSUMED = 2;
    public const STATUS_UNAVAILABLE = 3;

    /** @var array */
    private $row;

    private function __construct(array $row)
    {
        $this->row = $row;
    }

    public static function fromDatabaseRow(array $row): self
    {
        foreach ([
            'id', 'tenant_id', 'channel_id', 'member_id', 'uid', 'byte_size', 'status',
            'used_business_id', 'used_time', 'last_access_time', 'add_time', 'update_time',
        ] as $field) {
            $row[$field] = self::unsignedInteger($row[$field] ?? null, $field);
        }
        if ($row['id'] <= 0 || $row['tenant_id'] <= 0 || $row['channel_id'] <= 0
            || $row['member_id'] <= 0 || $row['uid'] <= 0 || $row['byte_size'] < 1
            || $row['byte_size'] > MemberAssetUpload::MAX_BYTES
            || !in_array($row['status'], [
                self::STATUS_READY,
                self::STATUS_CONSUMED,
                self::STATUS_UNAVAILABLE,
            ], true)) {
            throw new InvalidArgumentException('Stored member asset numeric fields are invalid');
        }

        foreach ([
            'purpose', 'object_key', 'storage_driver', 'original_name', 'mime_type',
            'sha256', 'used_business_type',
        ] as $field) {
            if (!array_key_exists($field, $row) || !is_string($row[$field])) {
                throw new InvalidArgumentException(sprintf('Stored member asset field %s is invalid', $field));
            }
        }
        if ($row['purpose'] !== MemberAssetPurpose::GRADUATE_VERIFICATION_PROOF
            || $row['storage_driver'] !== LocalPrivateAssetStorage::DRIVER
            || strlen($row['original_name']) < 1 || strlen($row['original_name']) > 180
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,175}\.(jpg|png|pdf)$/D', $row['original_name']) !== 1
            || !in_array($row['mime_type'], ['image/jpeg', 'image/png', 'application/pdf'], true)
            || preg_match('/^[0-9a-f]{64}$/D', $row['sha256']) !== 1
            || strlen($row['used_business_type']) > 32) {
            throw new InvalidArgumentException('Stored member asset metadata is invalid');
        }
        LocalPrivateAssetStorage::assertObjectKey($row['object_key'], $row['tenant_id']);

        if ($row['status'] === self::STATUS_READY
            && ($row['used_business_type'] !== '' || $row['used_business_id'] !== 0 || $row['used_time'] !== 0)) {
            throw new InvalidArgumentException('Ready member asset has inconsistent use metadata');
        }
        if ($row['status'] === self::STATUS_CONSUMED
            && ($row['used_business_type'] !== 'graduate_verification'
                || $row['used_business_id'] <= 0 || $row['used_time'] <= 0)) {
            throw new InvalidArgumentException('Consumed member asset has inconsistent use metadata');
        }

        return new self($row);
    }

    public function id(): int
    {
        return $this->row['id'];
    }

    public function tenantId(): int
    {
        return $this->row['tenant_id'];
    }

    public function channelId(): int
    {
        return $this->row['channel_id'];
    }

    public function memberId(): int
    {
        return $this->row['member_id'];
    }

    public function uid(): int
    {
        return $this->row['uid'];
    }

    public function objectKey(): string
    {
        return $this->row['object_key'];
    }

    public function originalName(): string
    {
        return $this->row['original_name'];
    }

    public function mimeType(): string
    {
        return $this->row['mime_type'];
    }

    public function size(): int
    {
        return $this->row['byte_size'];
    }

    public function sha256(): string
    {
        return $this->row['sha256'];
    }

    public function status(): int
    {
        return $this->row['status'];
    }

    public function usedBusinessId(): int
    {
        return $this->row['used_business_id'];
    }

    public function isReusableProof(): bool
    {
        return $this->status() === self::STATUS_READY
            || $this->status() === self::STATUS_CONSUMED;
    }

    public function publicMetadata(): array
    {
        return [
            'id' => $this->id(),
            'object_key' => $this->objectKey(),
            'original_name' => $this->originalName(),
            'mime_type' => $this->mimeType(),
            'size' => $this->size(),
            'available' => $this->status() !== self::STATUS_UNAVAILABLE,
        ];
    }

    private static function unsignedInteger($value, string $field): int
    {
        if (is_string($value) && preg_match('/^(0|[1-9][0-9]*)$/D', $value) === 1) {
            $integer = (int) $value;
            if ((string) $integer === $value) {
                $value = $integer;
            }
        }
        if (!is_int($value) || $value < 0) {
            throw new InvalidArgumentException(sprintf('Stored member asset field %s is invalid', $field));
        }

        return $value;
    }
}
