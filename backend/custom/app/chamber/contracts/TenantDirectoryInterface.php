<?php

namespace app\chamber\contracts;

use app\chamber\tenancy\TenantRecord;

interface TenantDirectoryInterface
{
    /**
     * @return TenantRecord|null
     */
    public function findByHost(string $host);

    /**
     * @return TenantRecord|null
     */
    public function findBySlugs(string $tenantSlug, string $channelSlug);
}
