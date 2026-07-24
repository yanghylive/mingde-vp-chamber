<?php

namespace app\chamber\exceptions;

use InvalidArgumentException;
use RuntimeException;

final class MemberTransactionException extends RuntimeException
{
    /** @var int */
    private $httpStatus;

    /** @var string */
    private $reason;

    /** @var array */
    private $fieldErrors;

    public function __construct(int $httpStatus, string $reason, string $message, array $fieldErrors = [])
    {
        if ($httpStatus < 400 || $httpStatus > 599) {
            throw new InvalidArgumentException('Member transaction HTTP status must be between 400 and 599');
        }
        if (!self::isErrorCode($reason)) {
            throw new InvalidArgumentException('Member transaction reason is invalid');
        }
        if ($message === '' || strlen($message) > 500) {
            throw new InvalidArgumentException('Member transaction message is invalid');
        }
        if (count($fieldErrors) > 50 || $fieldErrors !== array_values($fieldErrors)) {
            throw new InvalidArgumentException('Member transaction field errors must be a list of at most 50 items');
        }

        foreach ($fieldErrors as $fieldError) {
            if (!is_array($fieldError)) {
                throw new InvalidArgumentException('Member transaction field error must be an object');
            }
            $keys = array_keys($fieldError);
            sort($keys);
            if ($keys !== ['code', 'field']) {
                throw new InvalidArgumentException('Member transaction field error has unknown properties');
            }
            if (!is_string($fieldError['field']) || !preg_match(
                '/^[A-Za-z0-9][A-Za-z0-9_.\[\]-]{0,127}$/D',
                $fieldError['field']
            )) {
                throw new InvalidArgumentException('Member transaction field name is invalid');
            }
            if (!is_string($fieldError['code']) || !self::isErrorCode($fieldError['code'])) {
                throw new InvalidArgumentException('Member transaction field error code is invalid');
            }
        }

        parent::__construct($message, $httpStatus);
        $this->httpStatus = $httpStatus;
        $this->reason = $reason;
        $this->fieldErrors = $fieldErrors;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function fieldErrors(): array
    {
        return $this->fieldErrors;
    }

    private static function isErrorCode(string $value): bool
    {
        return strlen($value) <= 64 && preg_match('/^[a-z][a-z0-9_]*$/D', $value) === 1;
    }
}
