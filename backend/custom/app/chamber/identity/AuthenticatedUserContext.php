<?php

namespace app\chamber\identity;

use ArrayAccess;
use InvalidArgumentException;

/**
 * Request-scoped identity facts only. Raw tokens and user PII must never be stored here.
 */
final class AuthenticatedUserContext
{
    public const CONTAINER_KEY = 'chamber.authenticated_user_context';

    /** @var int */
    private $uid;

    /** @var bool */
    private $phoneBound;

    /** @var string */
    private $tokenType;

    public function __construct(int $uid, bool $phoneBound, string $tokenType)
    {
        if ($uid <= 0) {
            throw new InvalidArgumentException('Authenticated user ID must be positive');
        }
        if ($tokenType !== 'api') {
            throw new InvalidArgumentException('Authenticated token type must be the CRMEB user API type');
        }

        $this->uid = $uid;
        $this->phoneBound = $phoneBound;
        $this->tokenType = $tokenType;
    }

    public static function fromAuthInfo(array $authInfo): self
    {
        if (!array_key_exists('user', $authInfo) || !isset($authInfo['tokenData']) || !is_array($authInfo['tokenData'])) {
            throw new InvalidArgumentException('CRMEB authentication result is invalid');
        }

        $uid = self::positiveInteger(self::value($authInfo['user'], 'uid'), 'user uid');
        $tokenUid = self::positiveInteger($authInfo['tokenData']['uid'] ?? null, 'token uid');
        if ($uid !== $tokenUid) {
            throw new InvalidArgumentException('CRMEB authentication identities do not match');
        }

        $tokenType = $authInfo['tokenData']['type'] ?? null;
        if (!is_string($tokenType)) {
            throw new InvalidArgumentException('CRMEB authentication token type is invalid');
        }
        $phone = self::value($authInfo['user'], 'phone');
        if ($phone !== null && !is_string($phone)) {
            throw new InvalidArgumentException('CRMEB authentication phone binding is invalid');
        }

        return new self($uid, is_string($phone) && trim($phone) !== '', $tokenType);
    }

    public function uid(): int
    {
        return $this->uid;
    }

    public function phoneBound(): bool
    {
        return $this->phoneBound;
    }

    public function tokenType(): string
    {
        return $this->tokenType;
    }

    public function toArray(): array
    {
        return [
            'uid' => $this->uid,
            'phone_bound' => $this->phoneBound,
            'token_type' => $this->tokenType,
        ];
    }

    private static function value($source, string $key)
    {
        if (is_array($source)) {
            return array_key_exists($key, $source) ? $source[$key] : null;
        }
        if ($source instanceof ArrayAccess && $source->offsetExists($key)) {
            return $source[$key];
        }
        if (is_object($source) && isset($source->{$key})) {
            return $source->{$key};
        }

        return null;
    }

    private static function positiveInteger($value, string $field): int
    {
        if (is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value)) {
            $integer = (int) $value;
            if ((string) $integer !== $value) {
                throw new InvalidArgumentException(sprintf('CRMEB authentication %s is invalid', $field));
            }
            $value = $integer;
        }
        if (!is_int($value) || $value <= 0) {
            throw new InvalidArgumentException(sprintf('CRMEB authentication %s is invalid', $field));
        }

        return $value;
    }
}
