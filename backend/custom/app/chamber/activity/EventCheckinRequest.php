<?php

declare(strict_types=1);

namespace app\chamber\activity;

use app\chamber\exceptions\MemberTransactionException;

final class EventCheckinRequest
{
    /** @var string */
    private $token;

    /** @var int */
    private $registrationId;

    private function __construct()
    {
    }

    public static function fromArray(array $payload): self
    {
        foreach (array_keys($payload) as $field) {
            if (!is_string($field) || !in_array($field, ['token', 'registration_id'], true)) {
                $name = is_string($field) ? $field : 'body';
                throw self::validation($name, 'unknown_field', 'Unknown event check-in field: ' . $name);
            }
        }
        $token = $payload['token'] ?? null;
        if (!is_string($token)) {
            throw self::validation('token', 'invalid_type', 'token must be a string');
        }
        $token = trim($token);
        if (strlen($token) < 24 || strlen($token) > 256) {
            throw self::validation('token', 'invalid_length', 'token must contain between 24 and 256 characters');
        }

        $hasRegistrationId = array_key_exists('registration_id', $payload);
        $registrationId = $payload['registration_id'] ?? 0;
        if (is_string($registrationId) && preg_match('/^[1-9][0-9]*$/D', $registrationId) === 1) {
            $parsed = (int) $registrationId;
            if ((string) $parsed === $registrationId) {
                $registrationId = $parsed;
            }
        }
        if (!is_int($registrationId) || ($hasRegistrationId && $registrationId <= 0)) {
            throw self::validation(
                'registration_id',
                'invalid_value',
                'registration_id must be a positive integer'
            );
        }

        $instance = new self();
        $instance->token = $token;
        $instance->registrationId = $registrationId;

        return $instance;
    }

    public function token(): string
    {
        return $this->token;
    }

    public function registrationId(): int
    {
        return $this->registrationId;
    }

    private static function validation(string $field, string $code, string $message): MemberTransactionException
    {
        return new MemberTransactionException(
            422,
            'request_validation_failed',
            $message,
            [['field' => $field, 'code' => $code]]
        );
    }
}
