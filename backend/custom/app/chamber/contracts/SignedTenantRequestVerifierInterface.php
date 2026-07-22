<?php

namespace app\chamber\contracts;

use app\chamber\tenancy\TenantResolutionInput;

interface SignedTenantRequestVerifierInterface
{
    public function assertValid(TenantResolutionInput $input): void;
}
