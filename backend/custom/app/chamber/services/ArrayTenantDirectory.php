<?php

namespace app\chamber\services;

use app\chamber\contracts\TenantDirectoryInterface;
use app\chamber\tenancy\TenantRecord;
use InvalidArgumentException;

final class ArrayTenantDirectory implements TenantDirectoryInterface
{
    /** @var TenantRecord[] */
    private $byHost = [];

    /** @var TenantRecord[] */
    private $bySlugs = [];

    public function __construct(array $records)
    {
        foreach ($records as $record) {
            $this->addRecord($record);
        }
    }

    public function findByHost(string $host)
    {
        $host = self::normalizeHost($host);

        return $this->byHost[$host] ?? null;
    }

    public function findBySlugs(string $tenantSlug, string $channelSlug)
    {
        $key = self::slugKey($tenantSlug, $channelSlug);

        return $this->bySlugs[$key] ?? null;
    }

    private function addRecord(array $data): void
    {
        foreach (['tenant_id', 'tenant_slug', 'channel_id', 'channel_slug'] as $required) {
            if (!array_key_exists($required, $data)) {
                throw new InvalidArgumentException(sprintf('Tenant directory record is missing %s', $required));
            }
        }

        $record = new TenantRecord(
            (int) $data['tenant_id'],
            (string) $data['tenant_slug'],
            (int) $data['channel_id'],
            (string) $data['channel_slug'],
            isset($data['active']) ? (bool) $data['active'] : true
        );

        $slugKey = self::slugKey($record->tenantSlug(), $record->channelSlug());
        if (isset($this->bySlugs[$slugKey])) {
            throw new InvalidArgumentException(sprintf('Duplicate tenant/channel mapping: %s', $slugKey));
        }
        $this->bySlugs[$slugKey] = $record;

        foreach ((array) ($data['hosts'] ?? []) as $host) {
            $host = self::normalizeHost((string) $host);
            if ($host === '') {
                continue;
            }
            if (isset($this->byHost[$host])) {
                throw new InvalidArgumentException(sprintf('Duplicate tenant host mapping: %s', $host));
            }
            $this->byHost[$host] = $record;
        }
    }

    private static function slugKey(string $tenantSlug, string $channelSlug): string
    {
        return strtolower(trim($tenantSlug)) . ':' . strtolower(trim($channelSlug));
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

        if (!is_string($parsed)) {
            return '';
        }

        $parsed = rtrim($parsed, '.');
        if (strlen($parsed) > 1 && $parsed[0] === '[' && substr($parsed, -1) === ']') {
            return substr($parsed, 1, -1);
        }

        return $parsed;
    }
}
