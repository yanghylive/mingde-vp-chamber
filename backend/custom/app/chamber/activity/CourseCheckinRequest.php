<?php

declare(strict_types=1);

namespace app\chamber\activity;

use app\chamber\exceptions\MemberTransactionException;

/** 会员扫码/输码签到请求 DTO。 */
final class CourseCheckinRequest
{
    /** @var string */
    private $token;

    /** @var int */
    private $bookingId;

    private function __construct(string $token, int $bookingId)
    {
        $this->token = $token;
        $this->bookingId = $bookingId;
    }

    public static function fromArray(array $payload): self
    {
        foreach (array_keys($payload) as $field) {
            if (!is_string($field) || !in_array($field, ['token', 'booking_id'], true)) {
                $name = is_string($field) ? $field : 'body';
                throw self::validation($name, 'unknown_field', 'Unknown course check-in field: ' . $name);
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

        $hasBookingId = array_key_exists('booking_id', $payload);
        $bookingId = $payload['booking_id'] ?? 0;
        if (is_string($bookingId) && preg_match('/^[1-9][0-9]*$/D', $bookingId) === 1) {
            $parsed = (int) $bookingId;
            if ((string) $parsed === $bookingId) {
                $bookingId = $parsed;
            }
        }
        if (!is_int($bookingId) || ($hasBookingId && $bookingId <= 0)) {
            throw self::validation('booking_id', 'invalid_value', 'booking_id must be a positive integer');
        }

        return new self($token, $bookingId);
    }

    public function token(): string
    {
        return $this->token;
    }

    public function bookingId(): int
    {
        return $this->bookingId;
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
