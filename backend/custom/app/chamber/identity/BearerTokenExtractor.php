<?php

namespace app\chamber\identity;

use InvalidArgumentException;

final class BearerTokenExtractor
{
    private const TOKEN_PATTERN = '/^Bearer ([A-Za-z0-9\-._~+\/]+=*)$/D';

    public static function fromHeaders($authorization, $alternateAuthorization): string
    {
        $tokens = [];
        foreach ([$authorization, $alternateAuthorization] as $header) {
            if ($header === null) {
                continue;
            }
            if (!is_string($header) || strlen($header) > 4096) {
                throw new InvalidArgumentException('Authorization header is invalid');
            }
            if (!preg_match(self::TOKEN_PATTERN, $header, $matches)) {
                throw new InvalidArgumentException('Authorization header must use Bearer token syntax');
            }
            $tokens[] = $matches[1];
        }

        if ($tokens === []) {
            throw new InvalidArgumentException('Authorization header is required');
        }
        if (count($tokens) === 2 && !hash_equals($tokens[0], $tokens[1])) {
            throw new InvalidArgumentException('Authorization headers conflict');
        }

        return $tokens[0];
    }
}
