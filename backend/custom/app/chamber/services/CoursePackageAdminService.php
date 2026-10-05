<?php

declare(strict_types=1);

namespace app\chamber\services;

use app\chamber\exceptions\MemberTransactionException;
use app\chamber\identity\AuthenticatedAdminContext;
use app\chamber\membership\CourseConsumptionPolicy;
use app\chamber\membership\CoursePackageSnapshot;
use app\chamber\tenancy\TenantContext;
use think\facade\Db;

/** 课时包管理（管理端，含私教分级价）。 */
final class CoursePackageAdminService
{
    private const ALLOWED = [
        'code', 'version', 'name', 'course_type', 'total_hours', 'validity_months',
        'session_duration_hours', 'frequency', 'min_participants', 'max_participants',
        'price', 'currency', 'product_id', 'product_attr_unique', 'benefits', 'refund_policy',
        'status', 'effective_time', 'end_time', 'tiers',
    ];

    /** @var CourseIdempotency */
    private $idempotency;

    public function __construct(CourseIdempotency $idempotency = null)
    {
        $this->idempotency = $idempotency ?: new CourseIdempotency();
    }

    public function listForAdmin(TenantContext $tenant, AuthenticatedAdminContext $admin, array $filters): array
    {
        unset($admin);
        $query = Db::table('ch_course_package')->where('tenant_id', $tenant->tenantId());
        if (isset($filters['course_type']) && $filters['course_type'] !== '' && $filters['course_type'] !== null) {
            $query->where('course_type', (string) $filters['course_type']);
        }
        if (isset($filters['status']) && $filters['status'] !== '' && $filters['status'] !== null) {
            $query->where('status', (int) $filters['status']);
        }
        $total = (int) $query->count();
        $rows = $query->order('id', 'desc')->select()->toArray();
        $items = [];
        foreach ($rows as $row) {
            $row['tiers'] = Db::table('ch_course_package_tier')->where('tenant_id', $tenant->tenantId())->where('package_id', (int) $row['id'])->select()->toArray();
            $items[] = $this->snapshot($row)->toPublicArray();
        }

        return ['items' => $items, 'page' => ['total' => $total]];
    }

    public function detailForAdmin(TenantContext $tenant, AuthenticatedAdminContext $admin, int $packageId): ?array
    {
        unset($admin);
        $row = Db::table('ch_course_package')->where('tenant_id', $tenant->tenantId())->where('id', $packageId)->find();
        if (!is_array($row)) {
            return null;
        }
        $row['tiers'] = Db::table('ch_course_package_tier')->where('tenant_id', $tenant->tenantId())->where('package_id', $packageId)->select()->toArray();

        return $this->snapshot($row)->toPublicArray();
    }

    public function create(
        TenantContext $tenant,
        AuthenticatedAdminContext $admin,
        array $payload,
        string $callerKey
    ): array {
        $this->assertAllowed($payload);
        $normalized = $this->normalize($tenant, $payload, true);

        return $this->idempotency->execute(
            $tenant,
            'createCoursePackage',
            'crmeb_admin',
            $admin->adminId(),
            $callerKey,
            $normalized['request'],
            201,
            function (int $now) use ($tenant, $normalized): array {
                $id = (int) Db::table('ch_course_package')->insertGetId(array_merge($normalized['row'], [
                    'add_time' => $now,
                    'update_time' => $now,
                ]));
                if ($id <= 0) {
                    throw new MemberTransactionException(503, 'course_package_create_failed', '课时包创建失败');
                }
                foreach ($normalized['tiers'] as $tier) {
                    Db::table('ch_course_package_tier')->insert(array_merge($tier, [
                        'tenant_id' => $tenant->tenantId(),
                        'package_id' => $id,
                        'add_time' => $now,
                    ]));
                }
                $row = Db::table('ch_course_package')->where('id', $id)->find();
                $row['tiers'] = Db::table('ch_course_package_tier')->where('tenant_id', $tenant->tenantId())->where('package_id', $id)->select()->toArray();

                return $this->snapshot($row)->toPublicArray();
            },
            null
        );
    }

    public function update(
        TenantContext $tenant,
        AuthenticatedAdminContext $admin,
        int $packageId,
        array $payload,
        string $callerKey
    ): array {
        $this->assertAllowed($payload);
        $normalized = $this->normalize($tenant, $payload, false);

        return $this->idempotency->execute(
            $tenant,
            'updateCoursePackage',
            'crmeb_admin',
            $admin->adminId(),
            $callerKey,
            array_merge(['package_id' => $packageId], $normalized['request']),
            200,
            function (int $now) use ($tenant, $packageId, $normalized): array {
                $row = Db::table('ch_course_package')
                    ->where('tenant_id', $tenant->tenantId())
                    ->where('id', $packageId)
                    ->find();
                if (!is_array($row)) {
                    throw new MemberTransactionException(404, 'course_package_not_found', '课时包不存在');
                }
                Db::table('ch_course_package')
                    ->where('tenant_id', $tenant->tenantId())
                    ->where('id', $packageId)
                    ->update(array_merge($normalized['row'], ['update_time' => $now]));
                if ($normalized['tiers'] !== null) {
                    Db::table('ch_course_package_tier')->where('tenant_id', $tenant->tenantId())->where('package_id', $packageId)->delete();
                    foreach ($normalized['tiers'] as $tier) {
                        Db::table('ch_course_package_tier')->insert(array_merge($tier, [
                            'tenant_id' => $tenant->tenantId(),
                            'package_id' => $packageId,
                            'add_time' => $now,
                        ]));
                    }
                }
                $updated = Db::table('ch_course_package')->where('id', $packageId)->find();
                $updated['tiers'] = Db::table('ch_course_package_tier')->where('tenant_id', $tenant->tenantId())->where('package_id', $packageId)->select()->toArray();

                return $this->snapshot($updated)->toPublicArray();
            },
            null
        );
    }

    private function assertAllowed(array $payload): void
    {
        foreach (array_keys($payload) as $field) {
            if (!is_string($field) || !in_array($field, self::ALLOWED, true)) {
                throw new MemberTransactionException(
                    422,
                    'request_validation_failed',
                    'Unknown course package field: ' . (is_string($field) ? $field : 'body'),
                    [['field' => is_string($field) ? $field : 'body', 'code' => 'unknown_field']]
                );
            }
        }
    }

    /**
     * @return array{row:array, tiers:?array, request:array}
     */
    private function normalize(TenantContext $tenant, array $payload, bool $requireCore): array
    {
        $row = [];
        $tiers = null;
        $request = [];

        if (array_key_exists('code', $payload)) {
            $code = is_string($payload['code']) ? trim($payload['code']) : '';
            if ($code === '' || strlen($code) > 40 || !preg_match('/^[A-Za-z0-9._-]+$/D', $code)) {
                throw new MemberTransactionException(422, 'request_validation_failed', 'code is invalid', [['field' => 'code', 'code' => 'invalid_value']]);
            }
            $row['code'] = $code;
        } elseif ($requireCore) {
            throw new MemberTransactionException(422, 'request_validation_failed', 'code is required', [['field' => 'code', 'code' => 'required']]);
        }
        if (array_key_exists('version', $payload)) {
            $row['version'] = $this->intOrThrow($payload['version'], 'version', 1, 1000);
        } elseif ($requireCore) {
            $row['version'] = 1;
        }
        if (array_key_exists('name', $payload)) {
            $name = is_string($payload['name']) ? trim($payload['name']) : '';
            if ($name === '' || strlen($name) > 80) {
                throw new MemberTransactionException(422, 'request_validation_failed', 'name must be 1..80 chars', [['field' => 'name', 'code' => 'invalid_length']]);
            }
            $row['name'] = $name;
        } elseif ($requireCore) {
            throw new MemberTransactionException(422, 'request_validation_failed', 'name is required', [['field' => 'name', 'code' => 'required']]);
        }
        if (array_key_exists('course_type', $payload)) {
            $ct = is_string($payload['course_type']) ? $payload['course_type'] : '';
            if (!in_array($ct, [CourseConsumptionPolicy::TYPE_PRIVATE, CourseConsumptionPolicy::TYPE_GROUP, CourseConsumptionPolicy::TYPE_FAMILY, CourseConsumptionPolicy::TYPE_ONCOURSE], true)) {
                throw new MemberTransactionException(422, 'request_validation_failed', 'course_type is invalid', [['field' => 'course_type', 'code' => 'invalid_value']]);
            }
            $row['course_type'] = $ct;
        } elseif ($requireCore) {
            throw new MemberTransactionException(422, 'request_validation_failed', 'course_type is required', [['field' => 'course_type', 'code' => 'required']]);
        }
        foreach (['total_hours', 'price', 'session_duration_hours'] as $field) {
            if (array_key_exists($field, $payload)) {
                $row[$field] = $this->decimalOrThrow($payload[$field], $field);
            } elseif ($requireCore) {
                $row[$field] = '0.00';
            }
            $request[$field] = $row[$field] ?? '0.00';
        }
        foreach (['validity_months', 'min_participants', 'max_participants', 'status', 'effective_time', 'end_time', 'product_id'] as $field) {
            if (array_key_exists($field, $payload)) {
                $row[$field] = $this->intOrThrow($payload[$field], $field, 0, 1000000);
            } elseif ($requireCore) {
                $row[$field] = in_array($field, ['status', 'effective_time'], true) ? ($field === 'status' ? 1 : 0) : 0;
            }
            $request[$field] = $row[$field] ?? 0;
        }
        if (array_key_exists('frequency', $payload)) {
            $row['frequency'] = is_string($payload['frequency']) ? $payload['frequency'] : '';
        }
        if (array_key_exists('currency', $payload)) {
            $currency = is_string($payload['currency']) ? $payload['currency'] : '';
            if (!preg_match('/^[A-Z]{3}$/D', $currency)) {
                throw new MemberTransactionException(422, 'request_validation_failed', 'currency must be ISO 4217', [['field' => 'currency', 'code' => 'invalid_value']]);
            }
            $row['currency'] = $currency;
        } elseif ($requireCore) {
            $row['currency'] = 'CNY';
        }
        if (array_key_exists('product_attr_unique', $payload)) {
            $row['product_attr_unique'] = is_string($payload['product_attr_unique']) ? $payload['product_attr_unique'] : '';
        }
        if (array_key_exists('benefits', $payload)) {
            if (!is_array($payload['benefits'])) {
                throw new MemberTransactionException(422, 'request_validation_failed', 'benefits must be an array', [['field' => 'benefits', 'code' => 'invalid_type']]);
            }
            $row['benefits_json'] = json_encode($payload['benefits'], JSON_UNESCAPED_UNICODE);
        }
        if (array_key_exists('refund_policy', $payload)) {
            if (!is_array($payload['refund_policy'])) {
                throw new MemberTransactionException(422, 'request_validation_failed', 'refund_policy must be an array', [['field' => 'refund_policy', 'code' => 'invalid_type']]);
            }
            $row['refund_policy_json'] = json_encode($payload['refund_policy'], JSON_UNESCAPED_UNICODE);
        }
        if (array_key_exists('tiers', $payload)) {
            if (!is_array($payload['tiers'])) {
                throw new MemberTransactionException(422, 'request_validation_failed', 'tiers must be an array', [['field' => 'tiers', 'code' => 'invalid_type']]);
            }
            $tiers = [];
            foreach ($payload['tiers'] as $tier) {
                if (!is_array($tier) || !isset($tier['coach_tier'], $tier['price_per_hour'], $tier['total_price'])) {
                    throw new MemberTransactionException(422, 'request_validation_failed', 'tier is invalid', [['field' => 'tiers', 'code' => 'invalid_value']]);
                }
                $tiers[] = [
                    'coach_tier' => $this->intOrThrow($tier['coach_tier'], 'tier.coach_tier', 1, 3),
                    'price_per_hour' => $this->decimalOrThrow($tier['price_per_hour'], 'tier.price_per_hour'),
                    'total_price' => $this->decimalOrThrow($tier['total_price'], 'tier.total_price'),
                ];
            }
            $request['tiers'] = $tiers;
        }
        $row['channel_id'] = $tenant->channelId();

        return ['row' => $row, 'tiers' => $tiers, 'request' => $request];
    }

    private function snapshot(array $row): CoursePackageSnapshot
    {
        $row['benefits'] = $this->decodeJson($row['benefits_json'] ?? null, []);
        $row['refund_policy'] = $this->decodeJson($row['refund_policy_json'] ?? null, []);

        return CoursePackageSnapshot::fromArray($row);
    }

    private static function decodeJson($value, $default)
    {
        if (!is_string($value) || $value === '') {
            return $default;
        }
        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : $default;
    }

    private function intOrThrow($value, string $field, int $min, int $max): int
    {
        if (is_string($value) && preg_match('/^(0|[1-9][0-9]*)$/D', $value) === 1) {
            $value = (int) $value;
        }
        if (!is_int($value) || $value < $min || $value > $max) {
            throw new MemberTransactionException(422, 'request_validation_failed', $field . ' is out of range', [['field' => $field, 'code' => 'invalid_value']]);
        }

        return $value;
    }

    private function decimalOrThrow($value, string $field): string
    {
        if (!is_string($value) || !preg_match('/^[0-9]+(?:\.[0-9]{1,2})?$/D', $value)) {
            throw new MemberTransactionException(422, 'request_validation_failed', $field . ' must be a decimal', [['field' => $field, 'code' => 'invalid_value']]);
        }

        return number_format((float) $value, 2, '.', '');
    }
}
