<?php

namespace app\chamber\tenancy;

use InvalidArgumentException;

final class TenantRecord
{
    /** @var int */
    private $tenantId;

    /** @var string */
    private $tenantSlug;

    /** @var int */
    private $channelId;

    /** @var string */
    private $channelSlug;

    /** @var bool */
    private $active;

    public function __construct(
        int $tenantId,
        string $tenantSlug,
        int $channelId,
        string $channelSlug,
        bool $active
    ) {
        if ($tenantId < 1 || $channelId < 1) {
            throw new InvalidArgumentException('Tenant and channel IDs must be positive integers');
        }

        self::assertTenantSlug($tenantSlug);
        self::assertChannelCode($channelSlug);

        $this->tenantId = $tenantId;
        $this->tenantSlug = strtolower($tenantSlug);
        $this->channelId = $channelId;
        $this->channelSlug = strtolower($channelSlug);
        $this->active = $active;
    }

    public function tenantId(): int
    {
        return $this->tenantId;
    }

    public function tenantSlug(): string
    {
        return $this->tenantSlug;
    }

    public function channelId(): int
    {
        return $this->channelId;
    }

    public function channelSlug(): string
    {
        return $this->channelSlug;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function sameScope(TenantRecord $other): bool
    {
        return $this->tenantId === $other->tenantId()
            && $this->channelId === $other->channelId();
    }

    private static function assertTenantSlug(string $slug): void
    {
        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/i', $slug)) {
            throw new InvalidArgumentException('Invalid tenant slug');
        }
    }

    private static function assertChannelCode(string $code): void
    {
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/i', $code)) {
            throw new InvalidArgumentException('Invalid channel code');
        }
    }
}
