<?php

declare(strict_types=1);

namespace app\chamber\membership;

/** 课时账户值对象（含可选授予明细与账本）。 */
final class CreditAccountSnapshot
{
    /** @var array */
    private $data;

    /** @var array */
    private $grants = [];

    /** @var array */
    private $ledger = [];

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public static function fromRow(array $row): self
    {
        foreach (['id', 'tenant_id', 'owner_type', 'owner_id', 'balance_hours', 'frozen_hours', 'version'] as $field) {
            if (!array_key_exists($field, $row)) {
                throw new \InvalidArgumentException('credit_account.' . $field . ' is required');
            }
        }

        return new self([
            'id' => (int) $row['id'],
            'tenant_id' => (int) $row['tenant_id'],
            'owner_type' => (string) $row['owner_type'],
            'owner_id' => (int) $row['owner_id'],
            'uid' => (int) ($row['uid'] ?? 0),
            'balance_hours' => self::decimal((string) $row['balance_hours'], 'balance_hours'),
            'frozen_hours' => self::decimal((string) $row['frozen_hours'], 'frozen_hours'),
            'version' => (int) $row['version'],
        ]);
    }

    public function withGrants(array $grants): self
    {
        $clone = clone $this;
        $clone->grants = $grants;

        return $clone;
    }

    public function withLedger(array $ledger): self
    {
        $clone = clone $this;
        $clone->ledger = $ledger;

        return $clone;
    }

    public function id(): int { return $this->data['id']; }
    public function tenantId(): int { return $this->data['tenant_id']; }
    public function ownerType(): string { return $this->data['owner_type']; }
    public function ownerId(): int { return $this->data['owner_id']; }
    public function uid(): int { return $this->data['uid']; }
    public function balanceHours(): string { return $this->data['balance_hours']; }
    public function frozenHours(): string { return $this->data['frozen_hours']; }
    public function version(): int { return $this->data['version']; }
    public function grants(): array { return $this->grants; }
    public function ledger(): array { return $this->ledger; }

    public function toPublicArray(): array
    {
        return [
            'id' => $this->data['id'],
            'owner_type' => $this->data['owner_type'],
            'owner_id' => $this->data['owner_id'],
            'uid' => $this->data['uid'],
            'balance_hours' => $this->data['balance_hours'],
            'frozen_hours' => $this->data['frozen_hours'],
            'grants' => $this->grants,
            'ledger' => $this->ledger,
        ];
    }

    private static function decimal(string $value, string $field): string
    {
        if (!preg_match('/^[0-9]+(?:\.[0-9]{1,2})?$/D', $value)) {
            throw new \InvalidArgumentException('credit_account.' . $field . ' must be a decimal');
        }

        return number_format((float) $value, 2, '.', '');
    }
}
