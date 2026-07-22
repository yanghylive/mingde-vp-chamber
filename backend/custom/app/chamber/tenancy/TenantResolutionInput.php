<?php

namespace app\chamber\tenancy;

use InvalidArgumentException;

final class TenantResolutionInput
{
    public const HEADER_CHANNEL = 'x-chamber-channel';
    public const HEADER_NONCE = 'x-chamber-nonce';
    public const HEADER_SIGNATURE = 'x-chamber-signature';
    public const HEADER_TENANT = 'x-chamber-tenant';
    public const HEADER_TIMESTAMP = 'x-chamber-timestamp';

    /** @var string */
    private $method;

    /** @var string */
    private $host;

    /** @var string */
    private $path;

    /** @var array */
    private $headers;

    public function __construct(string $method, string $host, string $path, array $headers)
    {
        $this->method = strtoupper(trim($method));
        $this->host = self::normalizeHost($host);
        $this->path = self::normalizePath($path);
        $this->headers = self::normalizeHeaders($headers);

        if ($this->method === '') {
            throw new InvalidArgumentException('HTTP method is required');
        }
    }

    public function method(): string
    {
        return $this->method;
    }

    public function host(): string
    {
        return $this->host;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function tenantSlug(): string
    {
        return strtolower($this->header(self::HEADER_TENANT));
    }

    public function channelSlug(): string
    {
        return strtolower($this->header(self::HEADER_CHANNEL));
    }

    public function timestamp(): string
    {
        return $this->header(self::HEADER_TIMESTAMP);
    }

    public function nonce(): string
    {
        return $this->header(self::HEADER_NONCE);
    }

    public function signature(): string
    {
        return strtolower($this->header(self::HEADER_SIGNATURE));
    }

    public function hasSignedCandidate(): bool
    {
        foreach (self::signedHeaderNames() as $name) {
            if ($this->header($name) !== '') {
                return true;
            }
        }

        return false;
    }

    public function hasCompleteSignedCandidate(): bool
    {
        foreach (self::signedHeaderNames() as $name) {
            if ($this->header($name) === '') {
                return false;
            }
        }

        return true;
    }

    public function canonicalPayload(): string
    {
        return implode("\n", [
            $this->method,
            $this->host,
            $this->path,
            $this->tenantSlug(),
            $this->channelSlug(),
            $this->timestamp(),
            $this->nonce(),
        ]);
    }

    private function header(string $name): string
    {
        return isset($this->headers[$name]) ? trim((string) $this->headers[$name]) : '';
    }

    private static function signedHeaderNames(): array
    {
        return [
            self::HEADER_TENANT,
            self::HEADER_CHANNEL,
            self::HEADER_TIMESTAMP,
            self::HEADER_NONCE,
            self::HEADER_SIGNATURE,
        ];
    }

    private static function normalizeHeaders(array $headers): array
    {
        $normalized = [];

        foreach ($headers as $name => $value) {
            $key = str_replace('_', '-', strtolower((string) $name));
            $normalized[$key] = is_array($value) ? reset($value) : $value;
        }

        return $normalized;
    }

    private static function normalizeHost(string $host): string
    {
        $host = strtolower(trim($host));
        if ($host === '') {
            return '';
        }

        if ($host === '::1' || $host === '[::1]') {
            return '::1';
        }

        $parsed = parse_url('http://' . $host, PHP_URL_HOST);
        if (!is_string($parsed) || $parsed === '') {
            throw new InvalidArgumentException('Invalid request host');
        }

        $parsed = rtrim($parsed, '.');
        if (strlen($parsed) > 1 && $parsed[0] === '[' && substr($parsed, -1) === ']') {
            return substr($parsed, 1, -1);
        }

        return $parsed;
    }

    private static function normalizePath(string $path): string
    {
        $parsed = parse_url($path, PHP_URL_PATH);
        $path = is_string($parsed) && $parsed !== '' ? $parsed : '/';

        return '/' . ltrim($path, '/');
    }
}
