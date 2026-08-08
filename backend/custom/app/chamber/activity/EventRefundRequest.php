<?php

declare(strict_types=1);

namespace app\chamber\activity;

use app\chamber\exceptions\MemberTransactionException;

final class EventRefundRequest
{
    /** @var string */
    private $reason;

    private function __construct(string $reason)
    {
        $this->reason = $reason;
    }

    public static function fromArray(array $input): self
    {
        foreach (array_keys($input) as $field) {
            if ($field !== 'reason') {
                throw self::validation('body', 'unknown_field', 'Unknown event refund request field');
            }
        }
        $reason = $input['reason'] ?? null;
        if (!is_string($reason)) {
            throw self::validation('reason', 'invalid_type', 'reason must be a string');
        }
        $reason = trim($reason);
        if ($reason === '' || strlen($reason) > 255) {
            throw self::validation('reason', 'invalid_length', 'reason must contain between 1 and 255 bytes');
        }

        return new self($reason);
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function normalized(): array
    {
        return ['reason' => $this->reason];
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
