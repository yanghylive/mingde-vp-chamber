<?php

namespace app\chamber\exceptions;

use RuntimeException;

class TenantAccessException extends RuntimeException
{
    public static function crossTenant(int $expectedTenantId, int $actualTenantId): self
    {
        return new self(sprintf(
            'Cross-tenant access denied: context tenant %d cannot access tenant %d',
            $expectedTenantId,
            $actualTenantId
        ));
    }

    public static function crossChannel(int $expectedChannelId, int $actualChannelId): self
    {
        return new self(sprintf(
            'Cross-channel access denied: context channel %d cannot access channel %d',
            $expectedChannelId,
            $actualChannelId
        ));
    }
}
