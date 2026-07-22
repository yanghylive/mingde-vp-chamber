<?php

namespace app\chamber\services;

use app\chamber\contracts\TenantDirectoryInterface;
use app\chamber\tenancy\TenantRecord;
use InvalidArgumentException;
use think\facade\Db;

final class ThinkDbTenantDirectory implements TenantDirectoryInterface
{
    /** @var array */
    private $hostMappings;

    /** @var callable|null */
    private $rowLookup;

    public function __construct(array $hostMappings, callable $rowLookup = null)
    {
        $this->hostMappings = $hostMappings;
        $this->rowLookup = $rowLookup;
    }

    public function findByHost(string $host)
    {
        $host = $this->normalizeHost($host);
        if (!isset($this->hostMappings[$host])) {
            return null;
        }

        $scope = $this->hostMappings[$host];
        if (!isset($scope['tenant_slug'], $scope['channel_slug'])) {
            throw new InvalidArgumentException('Tenant host mapping is incomplete');
        }

        return $this->findBySlugs((string) $scope['tenant_slug'], (string) $scope['channel_slug']);
    }

    public function findBySlugs(string $tenantSlug, string $channelSlug)
    {
        $tenantSlug = strtolower(trim($tenantSlug));
        $channelSlug = strtolower(trim($channelSlug));
        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/', $tenantSlug)
            || !preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $channelSlug)) {
            return null;
        }

        $row = $this->lookupRow($tenantSlug, $channelSlug);
        if (!$row) {
            return null;
        }

        return new TenantRecord(
            (int) $row['tenant_id'],
            (string) $row['tenant_slug'],
            (int) $row['channel_id'],
            (string) $row['channel_slug'],
            (int) $row['tenant_status'] === 1 && (int) $row['channel_status'] === 1
        );
    }

    private function lookupRow(string $tenantSlug, string $channelSlug)
    {
        if ($this->rowLookup) {
            return call_user_func($this->rowLookup, $tenantSlug, $channelSlug);
        }

        return Db::table('ch_tenant')
            ->alias('tenant')
            ->join(['ch_channel' => 'channel'], 'channel.tenant_id = tenant.id')
            ->where('tenant.slug', $tenantSlug)
            ->where('channel.code', $channelSlug)
            ->where('tenant.is_del', 0)
            ->where('channel.is_del', 0)
            ->field('tenant.id AS tenant_id,tenant.slug AS tenant_slug,tenant.status AS tenant_status,channel.id AS channel_id,channel.code AS channel_slug,channel.status AS channel_status')
            ->find();
    }

    private function normalizeHost(string $host): string
    {
        $host = strtolower(trim($host));
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
