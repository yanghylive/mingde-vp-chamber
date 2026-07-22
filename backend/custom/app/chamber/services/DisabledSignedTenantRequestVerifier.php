<?php

namespace app\chamber\services;

use app\chamber\contracts\SignedTenantRequestVerifierInterface;
use app\chamber\exceptions\TenantResolutionException;
use app\chamber\tenancy\TenantResolutionInput;

final class DisabledSignedTenantRequestVerifier implements SignedTenantRequestVerifierInterface
{
    public function assertValid(TenantResolutionInput $input): void
    {
        throw new TenantResolutionException(
            TenantResolutionException::SIGNING_UNAVAILABLE,
            'Signed tenant entry is not configured',
            503
        );
    }
}
