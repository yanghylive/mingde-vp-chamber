<?php

namespace app\chamber\tenancy;

use app\chamber\exceptions\TenantAccessException;

final class TenantContext
{
    public const CONTAINER_KEY = 'chamber.tenant_context';

    /** @var int */
    private $tenantId;

    /** @var string */
    private $tenantSlug;

    /** @var int */
    private $channelId;

    /** @var string */
    private $channelSlug;

    /** @var string */
    private $source;

    public function __construct(TenantRecord $record, string $source)
    {
        $this->tenantId = $record->tenantId();
        $this->tenantSlug = $record->tenantSlug();
        $this->channelId = $record->channelId();
        $this->channelSlug = $record->channelSlug();
        $this->source = $source;
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

    public function source(): string
    {
        return $this->source;
    }

    public function assertTenant(int $tenantId): void
    {
        if ($this->tenantId !== $tenantId) {
            throw TenantAccessException::crossTenant($this->tenantId, $tenantId);
        }
    }

    public function assertScope(int $tenantId, int $channelId): void
    {
        $this->assertTenant($tenantId);

        if ($this->channelId !== $channelId) {
            throw TenantAccessException::crossChannel($this->channelId, $channelId);
        }
    }

    public function toArray(): array
    {
        return [
            'tenant_id' => $this->tenantId,
            'tenant_slug' => $this->tenantSlug,
            'channel_id' => $this->channelId,
            'channel_slug' => $this->channelSlug,
            'source' => $this->source,
        ];
    }
}
