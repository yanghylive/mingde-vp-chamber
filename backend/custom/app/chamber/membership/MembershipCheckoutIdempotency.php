<?php

declare(strict_types=1);

namespace app\chamber\membership;

final class MembershipCheckoutIdempotency
{
    public const OPERATION = 'createMembershipCheckout';
    private const PRINCIPAL_TYPE = 'crmeb_user';

    public static function deriveInternalKey(int $tenantId, int $authenticatedUid, string $callerKey): string
    {
        return BootstrapIdempotency::deriveInternalKey(
            $tenantId,
            self::OPERATION,
            self::PRINCIPAL_TYPE,
            $authenticatedUid,
            $callerKey
        );
    }

    public static function requestHash(
        int $trustedChannelId,
        MembershipCheckoutRequest $request
    ): string {
        return BootstrapIdempotency::requestHash($trustedChannelId, $request->toCanonicalArray());
    }
}
