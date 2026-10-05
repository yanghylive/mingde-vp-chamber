<?php

declare(strict_types=1);

namespace app\chamber\services;

use app\chamber\exceptions\MemberTransactionException;
use app\chamber\membership\CoursePackageSnapshot;
use app\chamber\tenancy\TenantContext;
use think\facade\Db;

/** 课时包读取（购买页）。 */
final class CoursePackageService
{
    public function list(TenantContext $tenant, int $now, int $page = 1, int $limit = 20, ?string $courseType = null): array
    {
        $query = Db::table('ch_course_package')
            ->where('tenant_id', $tenant->tenantId())
            ->where('channel_id', $tenant->channelId())
            ->where('status', 1)
            ->where('effective_time', '<=', $now)
            ->where(function ($q) use ($now): void {
                $q->where('end_time', 0)->whereOr('end_time', '>', $now);
            });
        if ($courseType !== null) {
            $query->where('course_type', $courseType);
        }
        $total = (int) $query->count();
        $rows = $query->order('id', 'asc')
            ->page($page, $limit)
            ->select()
            ->toArray();
        $items = [];
        foreach ($rows as $row) {
            $items[] = $this->snapshot($row)->toPublicArray();
        }

        return [
            'items' => $items,
            'page' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'has_more' => ($page * $limit) < $total,
            ],
        ];
    }

    public function show(TenantContext $tenant, int $packageId): ?CoursePackageSnapshot
    {
        $row = $this->packageRow($tenant, $packageId, false);

        return $row === null ? null : $this->snapshot($row);
    }

    public function packageRow(TenantContext $tenant, int $packageId, bool $lock): ?array
    {
        $query = Db::table('ch_course_package')
            ->where('tenant_id', $tenant->tenantId())
            ->where('channel_id', $tenant->channelId())
            ->where('id', $packageId);
        if ($lock) {
            $query->lock(true);
        }
        $row = $query->find();
        if (!is_array($row)) {
            return null;
        }
        $tiers = Db::table('ch_course_package_tier')
            ->where('tenant_id', $tenant->tenantId())
            ->where('package_id', $packageId)
            ->select()
            ->toArray();
        $row['tiers'] = $tiers ?? [];

        return $row;
    }

    public function listAll(int $tenantId, int $page = 1, int $limit = 50): array
    {
        $total = (int) Db::table('ch_course_package')->where('tenant_id', $tenantId)->count();
        $rows = Db::table('ch_course_package')
            ->where('tenant_id', $tenantId)
            ->order('id', 'desc')
            ->page($page, $limit)
            ->select()
            ->toArray();
        $items = [];
        foreach ($rows as $row) {
            $row['tiers'] = Db::table('ch_course_package_tier')
                ->where('tenant_id', $tenantId)
                ->where('package_id', (int) $row['id'])
                ->select()
                ->toArray();
            $items[] = $this->snapshot($row)->toPublicArray();
        }

        return ['items' => $items, 'page' => ['page' => $page, 'limit' => $limit, 'total' => $total]];
    }

    private function snapshot(array $row): CoursePackageSnapshot
    {
        $row['benefits'] = self::decodeJson($row['benefits_json'] ?? null, []);
        $row['refund_policy'] = self::decodeJson($row['refund_policy_json'] ?? null, []);
        try {
            return CoursePackageSnapshot::fromArray($row);
        } catch (\InvalidArgumentException $exception) {
            throw new MemberTransactionException(503, 'course_package_invalid', '课时包配置无效: ' . $exception->getMessage());
        }
    }

    private static function decodeJson($value, $default)
    {
        if (!is_string($value) || $value === '') {
            return $default;
        }
        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : $default;
    }
}
