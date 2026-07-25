<?php

declare(strict_types=1);

namespace app\chamber\assets;

use app\chamber\exceptions\MemberTransactionException;

final class MemberAssetPurpose
{
    public const GRADUATE_VERIFICATION_PROOF = 'graduate_verification_proof';

    public static function validate($value): string
    {
        if (!is_string($value) || $value !== self::GRADUATE_VERIFICATION_PROOF) {
            throw new MemberTransactionException(
                422,
                'request_validation_failed',
                'purpose must be graduate_verification_proof',
                [['field' => 'purpose', 'code' => 'invalid_value']]
            );
        }

        return $value;
    }
}
