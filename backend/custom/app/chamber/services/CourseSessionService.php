<?php

declare(strict_types=1);

namespace app\chamber\services;

use app\chamber\activity\CourseCheckinToken;
use app\chamber\activity\CourseSessionListQuery;
use app\chamber\activity\CourseSessionSnapshot;
use app\chamber\exceptions\MemberTransactionException;
use app\chamber\identity\AuthenticatedAdminContext;
use app\chamber\membership\CourseConsumptionPolicy;
use app\chamber\tenancy\TenantContext;
use think\facade\Db;

/** 可约课次：会员列表/详情 + 管理端排课 + 签发动态签到令牌。 */
final class CourseSessionService
{
    private const ALLOWED = [
        'course_type', 'coach_id', 'title', 'start_time', 'end_time', 'duration_hours',
        'capacity', 'location', 'price_per_session', 'applicable_package_id',
        'product_id', 'status',
    ];

    /** @var CourseIdempotency */
    private $idempotency;

    public function __construct(CourseIdempotency $idempotency = null)
    {
        $this->idempotency = $idempotency ?: new CourseIdempotency();
    }

    public function list(TenantContext $tenant, CourseSessionListQuery $query, int $now): array
    {
        $dbQuery = Db::table('ch_course_session')
            ->where('tenant_id', $tenant->tenantId())
            ->where('status', $query->status() ?? 1);
        if ($query->courseType() !== null) {
            $dbQuery->where('course_type', $query->courseType());
        }
        if (($query->status() ?? 1) === 1) {
            $dbQuery->where('start_time', '>=', $now);
        }
        $total = (int) $dbQuery->count();
        $rows = $dbQuery->order('start_time', 'asc')
            ->page($query->page(), $query->limit())
            ->select()
            ->toArray();
        $items = [];
        foreach ($rows as $row) {
            $items[] = $this->snapshot($row)->toPublicArray();
        }

        return [
            'items' => $items,
            'page' => [
                'page' => $query->page(),
                'limit' => $query->limit(),
                'total' => $total,
                'has_more' => ($query->page() * $query->limit()) < $total,
            ],
        ];
    }

    public function show(TenantContext $tenant, int $sessionId): ?CourseSessionSnapshot
    {
        $row = Db::table('ch_course_session')
            ->where('tenant_id', $tenant->tenantId())
            ->where('id', $sessionId)
            ->find();
        if (!is_array($row)) {
            return null;
        }

        return $this->snapshot($row);
    }

    public function listForAdmin(TenantContext $tenant, AuthenticatedAdminContext $admin, array $filters    ): array
    {
        $query = Db::table('ch_course_session')->where('tenant_id', $tenant->tenantId());
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
            $items[] = $this->snapshot($row)->toPublicArray();
        }

        return ['items' => $items, 'page' => ['total' => $total]];
    }

    public function detailForAdmin(TenantContext $tenant, AuthenticatedAdminContext $admin, int $sessionId    ): ?array
    {
        $row = Db::table('ch_course_session')
            ->where('tenant_id', $tenant->tenantId())
            ->where('id', $sessionId)
            ->find();
        if (!is_array($row)) {
            return null;
        }

        return $this->snapshot($row)->toPublicArray();
    }

    public function create(
        TenantContext $tenant,
        AuthenticatedAdminContext $admin,
        array $payload,
        string $callerKey
    ): array {
        $this->assertAllowed($payload);
        $normalized = $this->normalize($payload);

        return $this->idempotency->execute(
            $tenant,
            'createCourseSession',
            'crmeb_admin',
            $admin->adminId(),
            $callerKey,
            $normalized,
            201,
            function (int $now) use ($tenant, $normalized): array {
                $id = (int) Db::table('ch_course_session')->insertGetId(array_merge($normalized, [
                    'tenant_id' => $tenant->tenantId(),
                    'booked_count' => 0,
                    'add_time' => $now,
                    'update_time' => $now,
                ]));
                if ($id <= 0) {
                    throw new MemberTransactionException(503, 'course_session_create_failed', '课次创建失败');
                }
                $row = Db::table('ch_course_session')->where('id', $id)->find();
                if (!is_array($row)) {
                    throw new MemberTransactionException(503, 'course_session_create_failed', '课次读取失败');
                }

                return $this->snapshot($row)->toPublicArray();
            },
            null
        );
    }

    public function update(
        TenantContext $tenant,
        AuthenticatedAdminContext $admin,
        int $sessionId,
        array $payload,
        string $callerKey
    ): array {
        $this->assertAllowed($payload);
        $normalized = $this->normalize($payload);

        return $this->idempotency->execute(
            $tenant,
            'updateCourseSession',
            'crmeb_admin',
            $admin->adminId(),
            $callerKey,
            array_merge(['session_id' => $sessionId], $normalized),
            200,
            function (int $now) use ($tenant, $sessionId, $normalized): array {
                $row = Db::table('ch_course_session')
                    ->where('tenant_id', $tenant->tenantId())
                    ->where('id', $sessionId)
                    ->find();
                if (!is_array($row)) {
                    throw new MemberTransactionException(404, 'course_session_not_found', '课次不存在');
                }
                Db::table('ch_course_session')
                    ->where('tenant_id', $tenant->tenantId())
                    ->where('id', $sessionId)
                    ->update(array_merge($normalized, ['update_time' => $now]));
                $updated = Db::table('ch_course_session')->where('id', $sessionId)->find();
                if (!is_array($updated)) {
                    throw new MemberTransactionException(503, 'course_session_update_failed', '课次读取失败');
                }

                return $this->snapshot($updated)->toPublicArray();
            },
            null
        );
    }

    public function issueCheckinToken(
        TenantContext $tenant,
        AuthenticatedAdminContext $admin,
        int $sessionId,
        int $ttl,
        string $callerKey
    ): array {
        if ($sessionId <= 0 || $ttl < 30 || $ttl > 3600) {
            throw new MemberTransactionException(422, 'request_validation_failed', 'check-in token parameters are invalid');
        }

        return $this->idempotency->execute(
            $tenant,
            'issueCourseCheckinTokenForAdmin',
            'crmeb_admin',
            $admin->adminId(),
            $callerKey,
            ['session_id' => $sessionId, 'ttl_seconds' => $ttl],
            201,
            function (int $now) use ($tenant, $admin, $sessionId, $ttl): array {
                $session = Db::table('ch_course_session')
                    ->where('tenant_id', $tenant->tenantId())
                    ->where('id', $sessionId)
                    ->lock(true)
                    ->find();
                if (!is_array($session)) {
                    throw new MemberTransactionException(404, 'course_session_not_found', '课次不存在');
                }
                if (!in_array((int) $session['status'], [1, 2], true)) {
                    throw new MemberTransactionException(409, 'course_session_not_open', '仅可约/已满的课次可签发签到令牌');
                }
                try {
                    $issued = CourseCheckinToken::issue($tenant->tenantId(), $sessionId, $now, $ttl);
                } catch (\Throwable $exception) {
                    throw new MemberTransactionException(503, 'checkin_token_unavailable', '课程签到令牌签名未配置');
                }
                $id = (int) Db::table('ch_course_checkin_token')->insertGetId([
                    'tenant_id' => $tenant->tenantId(),
                    'session_id' => $sessionId,
                    'token_digest' => $issued['digest'],
                    'issued_by_admin_id' => $admin->adminId(),
                    'valid_from' => $issued['valid_from'],
                    'expires_time' => $issued['expires_time'],
                    'status' => 1,
                    'add_time' => $now,
                    'update_time' => $now,
                ]);
                if ($id <= 0) {
                    throw new MemberTransactionException(503, 'checkin_token_unavailable', '课程签到令牌写入失败');
                }

                return [
                    'token_id' => $id,
                    'token' => $issued['token'],
                    'valid_from' => $issued['valid_from'],
                    'expires_time' => $issued['expires_time'],
                ];
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
                    'Unknown course session field: ' . (is_string($field) ? $field : 'body'),
                    [['field' => is_string($field) ? $field : 'body', 'code' => 'unknown_field']]
                );
            }
        }
    }

    private function normalize(array $payload): array
    {
        $row = [];
        if (array_key_exists('course_type', $payload)) {
            $ct = is_string($payload['course_type']) ? $payload['course_type'] : '';
            if (!in_array($ct, [
                CourseConsumptionPolicy::TYPE_PRIVATE,
                CourseConsumptionPolicy::TYPE_GROUP,
                CourseConsumptionPolicy::TYPE_FAMILY,
                CourseConsumptionPolicy::TYPE_ONCOURSE,
            ], true)) {
                throw new MemberTransactionException(422, 'request_validation_failed', 'course_type is invalid', [['field' => 'course_type', 'code' => 'invalid_value']]);
            }
            $row['course_type'] = $ct;
        }
        if (array_key_exists('coach_id', $payload)) {
            $row['coach_id'] = $this->intOrThrow($payload['coach_id'], 'coach_id', 0, 1000000);
        }
        if (array_key_exists('title', $payload)) {
            $title = is_string($payload['title']) ? trim($payload['title']) : '';
            if ($title === '' || strlen($title) > 120) {
                throw new MemberTransactionException(422, 'request_validation_failed', 'title must be 1..120 chars', [['field' => 'title', 'code' => 'invalid_length']]);
            }
            $row['title'] = $title;
        }
        foreach (['start_time', 'end_time'] as $field) {
            if (array_key_exists($field, $payload)) {
                $row[$field] = $this->intOrThrow($payload[$field], $field, 0, 4102444800);
            }
        }
        if (array_key_exists('duration_hours', $payload)) {
            $row['duration_hours'] = $this->decimalOrThrow($payload['duration_hours'], 'duration_hours');
        }
        if (array_key_exists('capacity', $payload)) {
            $row['capacity'] = $this->intOrThrow($payload['capacity'], 'capacity', 1, 100000);
        }
        if (array_key_exists('location', $payload)) {
            if (!is_array($payload['location'])) {
                throw new MemberTransactionException(422, 'request_validation_failed', 'location must be an object', [['field' => 'location', 'code' => 'invalid_type']]);
            }
            $row['location_json'] = json_encode([
                'name' => isset($payload['location']['name']) ? (string) $payload['location']['name'] : '',
                'address' => isset($payload['location']['address']) ? (string) $payload['location']['address'] : '',
                'longitude' => isset($payload['location']['longitude']) ? (string) $payload['location']['longitude'] : '',
                'latitude' => isset($payload['location']['latitude']) ? (string) $payload['location']['latitude'] : '',
            ], JSON_UNESCAPED_UNICODE);
        }
        if (array_key_exists('price_per_session', $payload)) {
            $row['price_per_session'] = $this->decimalOrThrow($payload['price_per_session'], 'price_per_session');
        }
        if (array_key_exists('applicable_package_id', $payload)) {
            $row['applicable_package_id'] = $this->intOrThrow($payload['applicable_package_id'], 'applicable_package_id', 0, 1000000);
        }
        if (array_key_exists('product_id', $payload)) {
            $row['product_id'] = $this->intOrThrow($payload['product_id'], 'product_id', 0, 1000000);
        }
        if (array_key_exists('status', $payload)) {
            $row['status'] = $this->intOrThrow($payload['status'], 'status', 1, 4);
        }

        return $row;
    }

    private function snapshot(array $row): CourseSessionSnapshot
    {
        $row['location_json'] = $this->decodeJson($row['location_json'] ?? null, []);

        return CourseSessionSnapshot::fromArray($row);
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
