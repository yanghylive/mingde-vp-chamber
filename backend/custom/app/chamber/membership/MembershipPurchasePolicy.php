<?php

declare(strict_types=1);

namespace app\chamber\membership;

use InvalidArgumentException;

final class MembershipPurchasePolicy
{
    public const VERIFICATION_REQUIRED = 'membership_verification_required';
    public const PLAN_UNAVAILABLE = 'membership_plan_unavailable';
    public const DOWNGRADE_NOT_ALLOWED = 'membership_downgrade_not_allowed';

    public static function ineligibleReason(
        MembershipPlanSnapshot $plan,
        $graduateApproved,
        $effectiveTier,
        int $now
    ): ?string {
        if (!is_bool($graduateApproved)) {
            throw new InvalidArgumentException('Graduate approval evidence must be boolean');
        }
        MemberTier::assertValid($effectiveTier);
        if ($now <= 0) {
            throw new InvalidArgumentException('Purchase evaluation time must be positive');
        }

        if (!$plan->isAvailableAt($now)) {
            return self::PLAN_UNAVAILABLE;
        }
        if (!$graduateApproved || $effectiveTier === MemberTier::L1) {
            return self::VERIFICATION_REQUIRED;
        }
        if ($effectiveTier === MemberTier::L4 && $plan->tier() === MemberTier::L3) {
            return self::DOWNGRADE_NOT_ALLOWED;
        }

        return null;
    }

    public static function isEligible(
        MembershipPlanSnapshot $plan,
        $graduateApproved,
        $effectiveTier,
        int $now
    ): bool {
        return self::ineligibleReason($plan, $graduateApproved, $effectiveTier, $now) === null;
    }

    public static function assertRequestMatchesPlan(
        MembershipCheckoutRequest $request,
        MembershipPlanSnapshot $plan
    ): void {
        if ($request->planCode() !== $plan->code()
            || $request->planVersion() !== $plan->version()
            || $request->expectedAmount() !== $plan->price()
            || $request->currency() !== $plan->currency()) {
            throw new InvalidArgumentException('Membership checkout does not match the selected plan snapshot');
        }
    }
}
