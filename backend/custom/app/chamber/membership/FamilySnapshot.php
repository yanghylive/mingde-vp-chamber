<?php

declare(strict_types=1);

namespace app\chamber\membership;

use InvalidArgumentException;

/** 家庭账户值对象（含成员）。 */
final class FamilySnapshot
{
    /** @var array */
    private $data;

    /** @var array */
    private $members = [];

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public static function fromArray(array $row): self
    {
        foreach (['id', 'name', 'owner_member_id', 'owner_uid', 'status'] as $field) {
            if (!array_key_exists($field, $row)) {
                throw new InvalidArgumentException('family.' . $field . ' is required');
            }
        }

        return new self([
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'owner_member_id' => (int) $row['owner_member_id'],
            'owner_uid' => (int) $row['owner_uid'],
            'status' => (int) $row['status'],
            'add_time' => (int) ($row['add_time'] ?? 0),
        ]);
    }

    public function withMembers(array $members): self
    {
        $clone = clone $this;
        $clone->members = $members;

        return $clone;
    }

    public function id(): int { return $this->data['id']; }
    public function name(): string { return $this->data['name']; }
    public function ownerMemberId(): int { return $this->data['owner_member_id']; }
    public function ownerUid(): int { return $this->data['owner_uid']; }
    public function status(): int { return $this->data['status']; }
    public function members(): array { return $this->members; }

    public function toPublicArray(): array
    {
        return [
            'id' => $this->data['id'],
            'name' => $this->data['name'],
            'owner_member_id' => $this->data['owner_member_id'],
            'owner_uid' => $this->data['owner_uid'],
            'status' => $this->data['status'],
            'members' => $this->members,
        ];
    }
}
