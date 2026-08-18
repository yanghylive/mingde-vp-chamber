<?php

declare(strict_types=1);

namespace app\chamber\membership;

use InvalidArgumentException;

final class MembershipPurchasePolicy
{
    public const PLAN_UNAVAILABLE = 'membership_plan_unavailable';
    public const DOWNGRADE_NOT_ALLOWED = 'membership_downgrade_not_allowed';

    public static function ineligibleReason(
        MembershipPlanSnapshot $plan,
        $effectiveTier,
        int $now
    ): ?string {
        MemberTier::assertValid($effectiveTier);
        if ($now <= 0) {
            throw new InvalidArgumentException('Purchase evaluation time must be positive');
        }

        if (!$plan->isAvailableAt($now)) {
            return self::PLAN_UNAVAILABLE;
        }
        // 降级拦截：会员不能购买低于自己当前档位的计划（L4 不能买 L2/L3，L3 不能买 L2）
        if (MemberTier::rank($effectiveTier) > MemberTier::rank($plan->tier())) {
            return self::DOWNGRADE_NOT_ALLOWED;
        }

        return null;
    }

    public static function isEligible(
        MembershipPlanSnapshot $plan,
        $effectiveTier,
        int $now
    ): bool {
        return self::ineligibleReason($plan, $effectiveTier, $now) === null;
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
