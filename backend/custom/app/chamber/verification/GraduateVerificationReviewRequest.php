<?php

declare(strict_types=1);

namespace app\chamber\verification;

use app\chamber\membership\GraduateVerificationState;

final class GraduateVerificationReviewRequest
{
    public const APPROVE = 'approve';
    public const RETURN_APPLICATION = 'return';
    public const REJECT = 'reject';
    public const REVOKE = 'revoke';

    private const TARGET_STATES = [
        self::APPROVE => GraduateVerificationState::APPROVED,
        self::RETURN_APPLICATION => GraduateVerificationState::RETURNED,
        self::REJECT => GraduateVerificationState::REJECTED,
        self::REVOKE => GraduateVerificationState::REVOKED,
    ];

    /** @var string */
    private $action;

    /** @var string */
    private $note;

    private function __construct()
    {
    }

    public static function fromArray(array $payload): self
    {
        foreach (array_keys($payload) as $field) {
            if (!is_string($field) || !in_array($field, ['action', 'note'], true)) {
                $name = is_string($field) ? $field : 'body';
                throw new GraduateVerificationValidationException(
                    $name,
                    'unknown_field',
                    sprintf('Unknown graduate verification review field: %s', $name)
                );
            }
        }

        if (!isset($payload['action']) || !is_string($payload['action'])
            || !array_key_exists($payload['action'], self::TARGET_STATES)) {
            throw new GraduateVerificationValidationException(
                'action',
                'invalid_value',
                'action must be approve, return, reject, or revoke'
            );
        }

        $note = array_key_exists('note', $payload) ? $payload['note'] : '';
        if (!is_string($note)) {
            throw new GraduateVerificationValidationException('note', 'invalid_type', 'note must be a string');
        }
        $note = trim($note);
        $length = function_exists('mb_strlen') ? mb_strlen($note, 'UTF-8') : strlen($note);
        if ($length > 500) {
            throw new GraduateVerificationValidationException(
                'note',
                'invalid_length',
                'note must contain at most 500 characters'
            );
        }
        if ($payload['action'] !== self::APPROVE && $note === '') {
            throw new GraduateVerificationValidationException(
                'note',
                'required',
                'note is required for return, reject, and revoke actions'
            );
        }

        $request = new self();
        $request->action = $payload['action'];
        $request->note = $note;

        return $request;
    }

    public function action(): string
    {
        return $this->action;
    }

    public function note(): string
    {
        return $this->note;
    }

    public function targetState(): string
    {
        return self::TARGET_STATES[$this->action];
    }

    public function toCanonicalArray(): array
    {
        return [
            'action' => $this->action,
            'note' => $this->note,
        ];
    }
}
