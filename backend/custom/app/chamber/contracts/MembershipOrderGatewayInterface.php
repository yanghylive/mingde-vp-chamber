<?php

declare(strict_types=1);

namespace app\chamber\contracts;

use app\chamber\membership\MembershipPlanSnapshot;

interface MembershipOrderGatewayInterface
{
    public function assertPlanProduct(MembershipPlanSnapshot $plan): void;

    /**
     * Returns the persisted CRMEB order snapshot, including cart_info, when it exists.
     */
    public function findByCheckoutKey(int $uid, string $checkoutKey): ?array;

    /**
     * Creates and returns the persisted CRMEB order snapshot, including cart_info.
     */
    public function create(
        array $authenticatedUser,
        MembershipPlanSnapshot $plan,
        string $checkoutKey
    ): array;

    /**
     * Validates a CRMEB snapshot and returns the stable Chamber order DTO.
     */
    public function assertOrderMatches(
        array $order,
        MembershipPlanSnapshot $plan,
        int $uid,
        string $checkoutKey
    ): array;
}
