<?php

declare(strict_types=1);

namespace app\chamber\membership;

use InvalidArgumentException;

final class MemberBootstrapRequest
{
    private const ALLOWED_FIELDS = ['invite_code', 'consents'];
    private const CONSENT_FIELDS = ['document_code', 'document_version', 'accepted'];
    private const MAX_CONSENTS = 10;

    /** @var string|null */
    private $inviteCode;

    /** @var array */
    private $consents;

    private function __construct(?string $inviteCode, array $consents)
    {
        $this->inviteCode = $inviteCode;
        $this->consents = $consents;
    }

    public static function fromArray(array $input): self
    {
        self::assertKnownFields($input, self::ALLOWED_FIELDS, 'bootstrap request');

        $inviteCode = null;
        if (array_key_exists('invite_code', $input)) {
            if (!is_string($input['invite_code'])
                || !preg_match('/^[A-Za-z0-9]{8,16}$/D', $input['invite_code'])) {
                throw new InvalidArgumentException('invite_code must contain 8 to 16 ASCII letters or digits');
            }
            $inviteCode = $input['invite_code'];
        }

        $consents = [];
        if (array_key_exists('consents', $input)) {
            if (!is_array($input['consents']) || !self::isList($input['consents'])) {
                throw new InvalidArgumentException('consents must be a JSON array');
            }
            if (count($input['consents']) > self::MAX_CONSENTS) {
                throw new InvalidArgumentException('consents cannot contain more than 10 entries');
            }

            $documentCodes = [];
            foreach ($input['consents'] as $consent) {
                if (!is_array($consent) || self::isList($consent)) {
                    throw new InvalidArgumentException('Each consent must be a JSON object');
                }
                self::assertKnownFields($consent, self::CONSENT_FIELDS, 'consent');
                foreach (self::CONSENT_FIELDS as $requiredField) {
                    if (!array_key_exists($requiredField, $consent)) {
                        throw new InvalidArgumentException(sprintf('Consent field %s is required', $requiredField));
                    }
                }

                $documentCode = self::identifier(
                    $consent['document_code'],
                    'document_code',
                    32
                );
                $documentVersion = self::identifier(
                    $consent['document_version'],
                    'document_version',
                    64
                );
                if ($consent['accepted'] !== true) {
                    throw new InvalidArgumentException('accepted must be boolean true');
                }
                if (isset($documentCodes[$documentCode])) {
                    throw new InvalidArgumentException('Each document_code may appear only once');
                }
                $documentCodes[$documentCode] = true;

                $consents[] = [
                    'document_code' => $documentCode,
                    'document_version' => $documentVersion,
                    'accepted' => true,
                ];
            }
        }

        usort($consents, function (array $left, array $right): int {
            $byCode = strcmp($left['document_code'], $right['document_code']);

            return $byCode !== 0
                ? $byCode
                : strcmp($left['document_version'], $right['document_version']);
        });

        return new self($inviteCode, $consents);
    }

    public function inviteCode(): ?string
    {
        return $this->inviteCode;
    }

    public function consents(): array
    {
        return $this->consents;
    }

    public function toCanonicalArray(): array
    {
        return [
            'invite_code' => $this->inviteCode,
            'consents' => $this->consents,
        ];
    }

    private static function assertKnownFields(array $value, array $allowedFields, string $scope): void
    {
        $allowed = array_flip($allowedFields);
        foreach (array_keys($value) as $field) {
            if (!is_string($field) || !isset($allowed[$field])) {
                throw new InvalidArgumentException(sprintf('Unknown %s field', $scope));
            }
        }
    }

    private static function identifier($value, string $field, int $maxLength): string
    {
        if (!is_string($value)
            || strlen($value) < 1
            || strlen($value) > $maxLength
            || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/D', $value)) {
            throw new InvalidArgumentException(sprintf('%s is invalid', $field));
        }

        return $value;
    }

    private static function isList(array $value): bool
    {
        $expected = 0;
        foreach ($value as $key => $unused) {
            if ($key !== $expected) {
                return false;
            }
            $expected++;
        }

        return true;
    }
}
