<?php

namespace app\chamber\services;

use InvalidArgumentException;

final class TenantRuntimeConfig
{
    /** @var array */
    private $values;

    public function __construct(array $values)
    {
        $this->values = $values;
    }

    public function signingSecret(): string
    {
        return trim((string) ($this->values['signing_secret'] ?? ''));
    }

    public function signatureTtl(): int
    {
        $ttl = (int) ($this->values['signature_ttl'] ?? 300);
        if ($ttl < 30 || $ttl > 900) {
            throw new InvalidArgumentException('Tenant signature TTL must be between 30 and 900 seconds');
        }

        return $ttl;
    }

    public function replayPrefix(): string
    {
        $prefix = trim((string) ($this->values['replay_prefix'] ?? 'chamber:tenant:nonce:'));
        if (!preg_match('/^[A-Za-z0-9:_-]{8,120}$/', $prefix)) {
            throw new InvalidArgumentException('Tenant replay key prefix is invalid');
        }

        return $prefix;
    }

    public function hostMappings(): array
    {
        $mappings = $this->decodeHostMappings((string) ($this->values['host_map_json'] ?? ''));
        $development = $this->isDevelopmentEnvironment() && $this->booleanValue($this->values['app_debug'] ?? false);

        foreach (array_keys($mappings) as $host) {
            if ($this->isLoopback($host) && !$development) {
                throw new InvalidArgumentException('Loopback tenant host mappings are restricted to development');
            }
        }

        if ($this->booleanValue($this->values['dev_localhost_enabled'] ?? false)) {
            if (!$development) {
                throw new InvalidArgumentException('Development localhost mapping requires a development environment and APP_DEBUG=true');
            }

            $scope = [
                'tenant_slug' => $this->validTenantSlug((string) ($this->values['dev_tenant_slug'] ?? '')),
                'channel_slug' => $this->validChannelCode((string) ($this->values['dev_channel_code'] ?? '')),
            ];

            foreach (['localhost', '127.0.0.1', '::1'] as $host) {
                if (isset($mappings[$host]) && $mappings[$host] !== $scope) {
                    throw new InvalidArgumentException(sprintf('Conflicting development host mapping: %s', $host));
                }
                $mappings[$host] = $scope;
            }
        }

        return $mappings;
    }

    public function corsAllowedOrigins(): array
    {
        $value = $this->values['cors_allowed_origins'] ?? '';
        $origins = is_array($value) ? $value : explode(',', (string) $value);
        $allowed = [];

        foreach ($origins as $origin) {
            $origin = trim((string) $origin);
            if ($origin === '') {
                continue;
            }

            $normalized = $this->normalizeOrigin($origin);
            if ($normalized === '') {
                throw new InvalidArgumentException('CHAMBER_CORS_ALLOWED_ORIGINS contains an invalid origin');
            }
            $allowed[$normalized] = true;
        }

        return array_keys($allowed);
    }

    public function corsAllowsCredentials(): bool
    {
        return $this->booleanValue($this->values['cors_allow_credentials'] ?? true);
    }

    public function allowsCorsOrigin(string $origin): bool
    {
        $normalized = $this->normalizeOrigin($origin);

        return $normalized !== '' && in_array($normalized, $this->corsAllowedOrigins(), true);
    }

    private function decodeHostMappings(string $json): array
    {
        if (trim($json) === '') {
            return [];
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidArgumentException('CHAMBER_HOST_MAP_JSON must be a JSON object');
        }

        $mappings = [];
        foreach ($decoded as $host => $scope) {
            if (!is_string($host) || !is_array($scope)) {
                throw new InvalidArgumentException('Each tenant host mapping must contain a host and scope object');
            }

            $normalizedHost = $this->normalizeHost($host);
            if ($normalizedHost === '') {
                throw new InvalidArgumentException('Tenant host mapping contains an invalid host');
            }

            $mapping = [
                'tenant_slug' => $this->validTenantSlug((string) ($scope['tenant_slug'] ?? '')),
                'channel_slug' => $this->validChannelCode((string) ($scope['channel_code'] ?? $scope['channel_slug'] ?? '')),
            ];
            if (isset($mappings[$normalizedHost]) && $mappings[$normalizedHost] !== $mapping) {
                throw new InvalidArgumentException(sprintf('Conflicting tenant host mapping: %s', $normalizedHost));
            }
            $mappings[$normalizedHost] = $mapping;
        }

        return $mappings;
    }

    private function isDevelopmentEnvironment(): bool
    {
        return in_array(strtolower(trim((string) ($this->values['environment'] ?? 'production'))), [
            'dev', 'development', 'local', 'test', 'testing',
        ], true);
    }

    private function booleanValue($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'on', 'yes'], true);
    }

    private function validTenantSlug(string $slug): string
    {
        $slug = strtolower(trim($slug));
        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/', $slug)) {
            throw new InvalidArgumentException('Tenant host mapping contains an invalid tenant slug');
        }

        return $slug;
    }

    private function validChannelCode(string $code): string
    {
        $code = strtolower(trim($code));
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $code)) {
            throw new InvalidArgumentException('Tenant host mapping contains an invalid channel code');
        }

        return $code;
    }

    private function normalizeHost(string $host): string
    {
        $host = strtolower(trim($host));
        if ($host === '') {
            return '';
        }

        if ($host === '::1' || $host === '[::1]') {
            return '::1';
        }

        $parsed = parse_url('http://' . $host, PHP_URL_HOST);

        return $this->normalizeParsedHost($parsed);
    }

    private function isLoopback(string $host): bool
    {
        return in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    }

    private function normalizeOrigin(string $origin): string
    {
        $parts = parse_url(trim($origin));
        if (!is_array($parts)
            || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || (isset($parts['path']) && $parts['path'] !== '')) {
            return '';
        }

        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower(rtrim((string) $parts['host'], '.'));
        if ($host === '') {
            return '';
        }

        $renderedHost = strpos($host, ':') !== false ? '[' . trim($host, '[]') . ']' : $host;
        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';

        return $scheme . '://' . $renderedHost . $port;
    }

    private function normalizeParsedHost($host): string
    {
        if (!is_string($host)) {
            return '';
        }

        $host = rtrim($host, '.');
        if (strlen($host) > 1 && $host[0] === '[' && substr($host, -1) === ']') {
            return substr($host, 1, -1);
        }

        return $host;
    }
}
