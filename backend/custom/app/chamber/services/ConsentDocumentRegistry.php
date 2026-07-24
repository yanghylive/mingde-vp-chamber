<?php

declare(strict_types=1);

namespace app\chamber\services;

use app\chamber\exceptions\MemberTransactionException;
use app\chamber\membership\ConsentDocument;
use InvalidArgumentException;

final class ConsentDocumentRegistry
{
    private const DEVELOPMENT_ENVIRONMENTS = ['dev', 'development', 'local', 'test', 'testing'];

    /** @var array */
    private $documents = [];

    /** @var string */
    private $privacyDigestSalt;

    public function __construct(array $values)
    {
        $this->privacyDigestSalt = self::optionalString(
            $values,
            'privacy_digest_salt'
        );

        $documentsJson = self::optionalString($values, 'documents_json');
        if ($documentsJson !== '') {
            $encodedRecords = trim($documentsJson);
            if ($encodedRecords === '' || substr($encodedRecords, 0, 1) !== '[') {
                throw new InvalidArgumentException('CHAMBER_CONSENT_DOCUMENTS_JSON must be a JSON array');
            }
            $records = json_decode($encodedRecords, true);
            if (!is_array($records) || json_last_error() !== JSON_ERROR_NONE || !self::isList($records)) {
                throw new InvalidArgumentException('CHAMBER_CONSENT_DOCUMENTS_JSON must be a JSON array');
            }
            foreach ($records as $record) {
                $this->register($record, false);
            }
        }

        $localFixture = $values['local_fixture'] ?? null;
        if ($localFixture !== null) {
            $environment = strtolower(self::optionalString($values, 'environment', 'production'));
            if (!in_array($environment, self::DEVELOPMENT_ENVIRONMENTS, true)) {
                throw new InvalidArgumentException('Local consent fixture is forbidden outside development');
            }
            $this->register($localFixture, true);
        }
    }

    public function resolve(string $tenantSlug, string $code, string $version): ConsentDocument
    {
        if (!isset($this->documents[$tenantSlug][$code])) {
            throw $this->staleDocument();
        }

        $document = $this->documents[$tenantSlug][$code];
        if (!hash_equals($document->version(), $version)) {
            throw $this->staleDocument();
        }

        return $document;
    }

    public function privacyDigest(string $raw): string
    {
        if ($raw === '' || $this->privacyDigestSalt === '') {
            return '';
        }
        if (strlen($this->privacyDigestSalt) < 32) {
            throw new InvalidArgumentException('CHAMBER_PRIVACY_DIGEST_SALT must contain at least 32 bytes');
        }

        return hash_hmac('sha256', "chamber-privacy-digest-v1\0" . $raw, $this->privacyDigestSalt);
    }

    /**
     * @param mixed $record
     */
    private function register($record, bool $localFixture): void
    {
        if (!is_array($record) || self::isList($record)) {
            throw new InvalidArgumentException('Each consent document configuration must be a JSON object');
        }

        $expectedFields = ['tenant_slug', 'document_code', 'document_version', 'content'];
        if ($localFixture) {
            $expectedFields[] = 'source';
        }
        self::assertExactFields($record, $expectedFields);

        if ($localFixture && $record['source'] !== 'local_fixture') {
            throw new InvalidArgumentException('Local consent fixture must be explicitly marked local_fixture');
        }

        $tenantSlug = self::tenantSlug($record['tenant_slug']);
        if (!is_string($record['document_code'])
            || !is_string($record['document_version'])
            || !is_string($record['content'])) {
            throw new InvalidArgumentException('Consent document configuration contains an invalid value type');
        }

        $document = new ConsentDocument(
            $record['document_code'],
            $record['document_version'],
            $record['content']
        );
        if (isset($this->documents[$tenantSlug][$document->code()])) {
            throw new InvalidArgumentException('Consent registry contains a duplicate tenant document code');
        }
        $this->documents[$tenantSlug][$document->code()] = $document;
    }

    private function staleDocument(): MemberTransactionException
    {
        return new MemberTransactionException(
            409,
            'consent_document_stale',
            'Consent document version is stale'
        );
    }

    private static function optionalString(array $values, string $field, string $default = ''): string
    {
        if (!array_key_exists($field, $values)) {
            return $default;
        }
        if (!is_string($values[$field])) {
            throw new InvalidArgumentException(sprintf('%s configuration must be a string', $field));
        }

        return $values[$field];
    }

    /**
     * @param mixed $value
     */
    private static function tenantSlug($value): string
    {
        if (!is_string($value) || !preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/D', $value)) {
            throw new InvalidArgumentException('Consent document tenant_slug is invalid');
        }

        return $value;
    }

    private static function assertExactFields(array $value, array $expectedFields): void
    {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expectedFields, SORT_STRING);
        if ($actual !== $expectedFields) {
            throw new InvalidArgumentException('Consent document configuration fields are invalid');
        }
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
