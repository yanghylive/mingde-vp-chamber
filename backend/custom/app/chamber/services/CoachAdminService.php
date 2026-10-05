<?php

declare(strict_types=1);

namespace app\chamber\services;

use app\chamber\exceptions\MemberTransactionException;
use app\chamber\identity\AuthenticatedAdminContext;
use app\chamber\membership\CoachSnapshot;
use app\chamber\tenancy\TenantContext;
use think\facade\Db;

/** 教练档案管理（管理端）。 */
final class CoachAdminService
{
    private const ALLOWED = ['name', 'tier', 'title', 'avatar', 'bio', 'status'];

    /** @var CourseIdempotency */
    private $idempotency;

    public function __construct(CourseIdempotency $idempotency = null)
    {
        $this->idempotency = $idempotency ?: new CourseIdempotency();
    }

    public function listForAdmin(TenantContext $tenant, AuthenticatedAdminContext $admin, array $filters): array
    {
        unset($admin);
        $query = Db::table('ch_coach')->where('tenant_id', $tenant->tenantId());
        if (isset($filters['status']) && $filters['status'] !== '' && $filters['status'] !== null) {
            $query->where('status', (int) $filters['status']);
        }
        if (isset($filters['tier']) && $filters['tier'] !== '' && $filters['tier'] !== null) {
            $query->where('tier', (int) $filters['tier']);
        }
        $total = (int) $query->count();
        $rows = $query->order('id', 'desc')->select()->toArray();
        $items = [];
        foreach ($rows as $row) {
            $items[] = CoachSnapshot::fromArray($row)->toPublicArray();
        }

        return ['items' => $items, 'page' => ['total' => $total]];
    }

    public function detailForAdmin(TenantContext $tenant, AuthenticatedAdminContext $admin, int $coachId): ?array
    {
        unset($admin);
        $row = Db::table('ch_coach')
            ->where('tenant_id', $tenant->tenantId())
            ->where('id', $coachId)
            ->find();

        return $row === null ? null : CoachSnapshot::fromArray($row)->toPublicArray();
    }

    public function create(
        TenantContext $tenant,
        AuthenticatedAdminContext $admin,
        array $payload,
        string $callerKey
    ): array {
        $this->assertAllowed($payload);
        $data = $this->normalize($payload);

        return $this->idempotency->execute(
            $tenant,
            'createCoach',
            'crmeb_admin',
            $admin->adminId(),
            $callerKey,
            $data,
            201,
            function (int $now) use ($tenant, $data): array {
                $id = (int) Db::table('ch_coach')->insertGetId(array_merge($data, [
                    'add_time' => $now,
                    'update_time' => $now,
                ]));
                if ($id <= 0) {
                    throw new MemberTransactionException(503, 'coach_create_failed', '教练创建失败');
                }
                $row = Db::table('ch_coach')->where('id', $id)->find();

                return CoachSnapshot::fromArray($row)->toPublicArray();
            },
            null
        );
    }

    public function update(
        TenantContext $tenant,
        AuthenticatedAdminContext $admin,
        int $coachId,
        array $payload,
        string $callerKey
    ): array {
        $this->assertAllowed($payload);
        $data = $this->normalize($payload, false);

        return $this->idempotency->execute(
            $tenant,
            'updateCoach',
            'crmeb_admin',
            $admin->adminId(),
            $callerKey,
            array_merge(['coach_id' => $coachId], $data),
            200,
            function (int $now) use ($tenant, $coachId, $data): array {
                $row = Db::table('ch_coach')
                    ->where('tenant_id', $tenant->tenantId())
                    ->where('id', $coachId)
                    ->find();
                if (!is_array($row)) {
                    throw new MemberTransactionException(404, 'coach_not_found', '教练不存在');
                }
                if ($data !== []) {
                    Db::table('ch_coach')
                        ->where('tenant_id', $tenant->tenantId())
                        ->where('id', $coachId)
                        ->update(array_merge($data, ['update_time' => $now]));
                }
                $updated = Db::table('ch_coach')->where('id', $coachId)->find();

                return CoachSnapshot::fromArray($updated)->toPublicArray();
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
                    'Unknown coach field: ' . (is_string($field) ? $field : 'body'),
                    [['field' => is_string($field) ? $field : 'body', 'code' => 'unknown_field']]
                );
            }
        }
    }

    private function normalize(array $payload, bool $requireName = true): array
    {
        $data = [];
        if (array_key_exists('name', $payload)) {
            $name = is_string($payload['name']) ? trim($payload['name']) : '';
            if ($name === '' || strlen($name) > 40) {
                throw new MemberTransactionException(422, 'request_validation_failed', 'name must be 1..40 chars', [['field' => 'name', 'code' => 'invalid_length']]);
            }
            $data['name'] = $name;
        } elseif ($requireName) {
            throw new MemberTransactionException(422, 'request_validation_failed', 'name is required', [['field' => 'name', 'code' => 'required']]);
        }
        if (array_key_exists('tier', $payload)) {
            $tier = $this->intOrThrow($payload['tier'], 'tier');
            if ($tier < 1 || $tier > 3) {
                throw new MemberTransactionException(422, 'request_validation_failed', 'tier must be 1..3', [['field' => 'tier', 'code' => 'invalid_value']]);
            }
            $data['tier'] = $tier;
        } elseif ($requireName) {
            $data['tier'] = 1;
        }
        if (array_key_exists('title', $payload)) {
            $data['title'] = $this->stringOrThrow($payload['title'], 'title', 60);
        }
        if (array_key_exists('avatar', $payload)) {
            $data['avatar'] = $this->stringOrThrow($payload['avatar'], 'avatar', 255);
        }
        if (array_key_exists('bio', $payload)) {
            $data['bio'] = is_string($payload['bio']) ? $payload['bio'] : '';
        }
        if (array_key_exists('status', $payload)) {
            $status = $this->intOrThrow($payload['status'], 'status');
            if (!in_array($status, [1, 2], true)) {
                throw new MemberTransactionException(422, 'request_validation_failed', 'status must be 1 or 2', [['field' => 'status', 'code' => 'invalid_value']]);
            }
            $data['status'] = $status;
        } elseif ($requireName) {
            $data['status'] = 1;
        }

        return $data;
    }

    private function intOrThrow($value, string $field): int
    {
        if (is_string($value) && preg_match('/^(0|[1-9][0-9]*)$/D', $value) === 1) {
            $value = (int) $value;
        }
        if (!is_int($value)) {
            throw new MemberTransactionException(422, 'request_validation_failed', $field . ' must be an integer', [['field' => $field, 'code' => 'invalid_type']]);
        }

        return $value;
    }

    private function stringOrThrow($value, string $field, int $max): string
    {
        if (!is_string($value)) {
            throw new MemberTransactionException(422, 'request_validation_failed', $field . ' must be a string', [['field' => $field, 'code' => 'invalid_type']]);
        }
        if (strlen($value) > $max) {
            throw new MemberTransactionException(422, 'request_validation_failed', $field . ' is too long', [['field' => $field, 'code' => 'invalid_length']]);
        }

        return $value;
    }
}
