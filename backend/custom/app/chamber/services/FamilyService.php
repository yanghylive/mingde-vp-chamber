<?php

declare(strict_types=1);

namespace app\chamber\services;

use app\chamber\exceptions\MemberTransactionException;
use app\chamber\identity\AuthenticatedAdminContext;
use app\chamber\identity\AuthenticatedUserContext;
use app\chamber\membership\FamilySnapshot;
use app\chamber\tenancy\TenantContext;
use think\facade\Db;

/** 家庭账户：会员创建家庭、加入成员、查看；管理端列表。 */
final class FamilyService
{
    /** @var CourseIdempotency */
    private $idempotency;

    public function __construct(CourseIdempotency $idempotency = null)
    {
        $this->idempotency = $idempotency ?: new CourseIdempotency();
    }

    public function indexForMember(TenantContext $tenant, AuthenticatedUserContext $auth): array
    {
        $familyIds = Db::table('ch_family_member')
            ->where('tenant_id', $tenant->tenantId())
            ->where('uid', $auth->uid())
            ->where('status', 1)
            ->column('family_id');
        if (!is_array($familyIds) || count($familyIds) === 0) {
            return ['items' => [], 'page' => ['total' => 0]];
        }
        $rows = Db::table('ch_family')
            ->where('tenant_id', $tenant->tenantId())
            ->whereIn('id', array_unique($familyIds))
            ->where('status', 1)
            ->order('id', 'desc')
            ->select()
            ->toArray();
        $items = [];
        foreach ($rows as $row) {
            $items[] = $this->snapshotWithMembers($row)->toPublicArray();
        }

        return ['items' => $items, 'page' => ['total' => count($items)]];
    }

    public function create(
        TenantContext $tenant,
        AuthenticatedUserContext $auth,
        array $payload,
        string $callerKey
    ): array {
        $name = $this->requireName($payload);
        $member = $this->member($tenant, $auth, false);

        return $this->idempotency->execute(
            $tenant,
            'createFamily',
            'crmeb_user',
            $auth->uid(),
            $callerKey,
            ['name' => $name],
            201,
            function (int $now) use ($tenant, $member, $name, $auth): array {
                $existing = Db::table('ch_family')
                    ->where('tenant_id', $tenant->tenantId())
                    ->where('owner_member_id', (int) $member['id'])
                    ->where('status', 1)
                    ->find();
                if (is_array($existing)) {
                    return $this->snapshotWithMembers($existing)->toPublicArray();
                }
                $familyId = (int) Db::table('ch_family')->insertGetId([
                    'tenant_id' => $tenant->tenantId(),
                    'name' => $name,
                    'owner_member_id' => (int) $member['id'],
                    'owner_uid' => $auth->uid(),
                    'status' => 1,
                    'add_time' => $now,
                    'update_time' => $now,
                ]);
                if ($familyId <= 0) {
                    throw new MemberTransactionException(503, 'family_create_failed', '家庭创建失败');
                }
                Db::table('ch_family_member')->insertGetId([
                    'tenant_id' => $tenant->tenantId(),
                    'family_id' => $familyId,
                    'member_id' => (int) $member['id'],
                    'uid' => $auth->uid(),
                    'relation' => 'owner',
                    'status' => 1,
                    'joined_time' => $now,
                    'add_time' => $now,
                ]);
                $row = Db::table('ch_family')->where('id', $familyId)->find();
                if (!is_array($row)) {
                    throw new MemberTransactionException(503, 'family_create_failed', '家庭读取失败');
                }

                return $this->snapshotWithMembers($row)->toPublicArray();
            },
            function () use ($tenant, $auth): void {
                $this->member($tenant, $auth, false);
            }
        );
    }

    /**
     * 自助加入：登录会员凭家庭 ID 直接加入，relation 由本人填写（前端默认 child）。
     * 去除 owner-only 与 member_uid 限制，使「输入家庭 ID 加入」可用。
     */
    public function addMember(
        TenantContext $tenant,
        AuthenticatedUserContext $auth,
        int $familyId,
        array $payload,
        string $callerKey
    ): array {
        $relation = $this->relationOrDefault($payload);
        $member = $this->member($tenant, $auth, false);

        return $this->idempotency->execute(
            $tenant,
            'addFamilyMember',
            'crmeb_user',
            $auth->uid(),
            $callerKey,
            ['family_id' => $familyId, 'relation' => $relation],
            201,
            function (int $now) use ($tenant, $auth, $familyId, $relation, $member): array {
                $family = Db::table('ch_family')
                    ->where('tenant_id', $tenant->tenantId())
                    ->where('id', $familyId)
                    ->where('status', 1)
                    ->lock(true)
                    ->find();
                if (!is_array($family)) {
                    throw new MemberTransactionException(404, 'family_not_found', '家庭不存在');
                }
                $dup = Db::table('ch_family_member')
                    ->where('tenant_id', $tenant->tenantId())
                    ->where('family_id', $familyId)
                    ->where('member_id', (int) $member['id'])
                    ->where('status', 1)
                    ->find();
                if (is_array($dup)) {
                    return $this->snapshotWithMembers($family)->toPublicArray();
                }
                Db::table('ch_family_member')->insertGetId([
                    'tenant_id' => $tenant->tenantId(),
                    'family_id' => $familyId,
                    'member_id' => (int) $member['id'],
                    'uid' => $auth->uid(),
                    'relation' => $relation,
                    'status' => 1,
                    'joined_time' => $now,
                    'add_time' => $now,
                ]);

                return $this->snapshotWithMembers($family)->toPublicArray();
            },
            function () use ($tenant, $auth): void {
                $this->member($tenant, $auth, false);
            }
        );
    }

    public function listForAdmin(TenantContext $tenant, AuthenticatedAdminContext $admin, array $filters): array
    {
        unset($admin);
        $query = Db::table('ch_family')->where('tenant_id', $tenant->tenantId());
        if (isset($filters['status']) && $filters['status'] !== '' && $filters['status'] !== null) {
            $query->where('status', (int) $filters['status']);
        }
        $total = (int) $query->count();
        $rows = $query->order('id', 'desc')->select()->toArray();
        $items = [];
        foreach ($rows as $row) {
            $items[] = $this->snapshotWithMembers($row)->toPublicArray();
        }

        return ['items' => $items, 'page' => ['total' => $total]];
    }

    private function snapshotWithMembers(array $row): FamilySnapshot
    {
        $members = Db::table('ch_family_member')
            ->where('tenant_id', (int) $row['tenant_id'])
            ->where('family_id', (int) $row['id'])
            ->where('status', 1)
            ->order('id', 'asc')
            ->field('id,family_id,member_id,uid,relation,joined_time')
            ->select()
            ->toArray();
        $membersOut = array_map(function (array $m): array {
            return [
                'id' => (int) $m['id'],
                'member_id' => (int) $m['member_id'],
                'uid' => (int) $m['uid'],
                'relation' => (string) $m['relation'],
                'joined_time' => (int) $m['joined_time'],
            ];
        }, is_array($members) ? $members : []);

        return FamilySnapshot::fromArray($row)->withMembers($membersOut);
    }

    private function requireName(array $payload): string
    {
        if (!isset($payload['name']) || !is_string($payload['name'])) {
            throw new MemberTransactionException(422, 'request_validation_failed', 'name is required', [['field' => 'name', 'code' => 'required']]);
        }
        $name = trim($payload['name']);
        if ($name === '' || strlen($name) > 60) {
            throw new MemberTransactionException(422, 'request_validation_failed', 'name must contain 1 to 60 characters', [['field' => 'name', 'code' => 'invalid_length']]);
        }

        return $name;
    }

    private function relationOrDefault(array $payload): string
    {
        $relation = isset($payload['relation']) && is_string($payload['relation'])
            ? trim($payload['relation']) : '';
        if ($relation === '' || strlen($relation) > 20) {
            $relation = 'member';
        }

        return $relation;
    }

    private function member(TenantContext $tenant, AuthenticatedUserContext $auth, bool $lock): array
    {
        $query = Db::table('ch_tenant_member')
            ->where('tenant_id', $tenant->tenantId())
            ->where('uid', $auth->uid());
        if ($lock) {
            $query->lock(true);
        }
        $row = $query->find();
        if (!is_array($row)) {
            throw new MemberTransactionException(404, 'member_not_found', '会员不存在');
        }
        if ((int) $row['status'] !== 1 || (int) $row['is_del'] !== 0) {
            throw new MemberTransactionException(403, 'member_disabled', '会员状态不可用');
        }
        if ((int) $row['current_channel_id'] !== $tenant->channelId()) {
            throw new MemberTransactionException(403, 'tenant_scope_denied', '会员不在当前渠道');
        }

        return $row;
    }
}
