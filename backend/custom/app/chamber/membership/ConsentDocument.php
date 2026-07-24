<?php

declare(strict_types=1);

namespace app\chamber\membership;

use InvalidArgumentException;

final class ConsentDocument
{
    private const MAX_CONTENT_BYTES = 1048576;

    /** @var string */
    private $code;

    /** @var string */
    private $version;

    /** @var string */
    private $contentHash;

    public function __construct(string $code, string $version, string $content)
    {
        $this->code = self::identifier($code, 'document_code', 32);
        $this->version = self::identifier($version, 'document_version', 64);
        if (trim($content) === '' || strlen($content) > self::MAX_CONTENT_BYTES) {
            throw new InvalidArgumentException('Consent document content is invalid');
        }
        $this->contentHash = hash('sha256', $content);
    }

    public function code(): string
    {
        return $this->code;
    }

    public function version(): string
    {
        return $this->version;
    }

    public function contentHash(): string
    {
        return $this->contentHash;
    }

    private static function identifier(string $value, string $field, int $maxLength): string
    {
        if (strlen($value) < 1
            || strlen($value) > $maxLength
            || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/D', $value)) {
            throw new InvalidArgumentException(sprintf('%s is invalid', $field));
        }

        return $value;
    }
}
