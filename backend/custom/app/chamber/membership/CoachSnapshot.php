<?php

declare(strict_types=1);

namespace app\chamber\membership;

use InvalidArgumentException;

/** 教练档案值对象。 */
final class CoachSnapshot
{
    /** @var array */
    private $data;

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public static function fromArray(array $row): self
    {
        foreach (['id', 'name', 'tier', 'status'] as $field) {
            if (!array_key_exists($field, $row)) {
                throw new InvalidArgumentException('coach.' . $field . ' is required');
            }
        }
        $tier = (int) $row['tier'];
        if ($tier < 1 || $tier > 3) {
            throw new InvalidArgumentException('coach.tier must be 1..3');
        }

        return new self([
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'tier' => $tier,
            'title' => (string) ($row['title'] ?? ''),
            'avatar' => (string) ($row['avatar'] ?? ''),
            'bio' => (string) ($row['bio'] ?? ''),
            'status' => (int) $row['status'],
        ]);
    }

    public function id(): int { return $this->data['id']; }
    public function name(): string { return $this->data['name']; }
    public function tier(): int { return $this->data['tier']; }
    public function title(): string { return $this->data['title']; }
    public function avatar(): string { return $this->data['avatar']; }
    public function bio(): string { return $this->data['bio']; }
    public function status(): int { return $this->data['status']; }

    public function toPublicArray(): array
    {
        return [
            'id' => $this->data['id'],
            'name' => $this->data['name'],
            'tier' => $this->data['tier'],
            'title' => $this->data['title'],
            'avatar' => $this->data['avatar'],
            'bio' => $this->data['bio'],
            'status' => $this->data['status'],
        ];
    }
}
