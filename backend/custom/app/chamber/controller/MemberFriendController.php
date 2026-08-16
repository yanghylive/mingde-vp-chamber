<?php

declare(strict_types=1);

namespace app\chamber\controller;

use app\Request;
use app\chamber\exceptions\MemberTransactionException;
use app\chamber\identity\AuthenticatedUserContext;
use app\chamber\services\MemberIdentityService;
use app\chamber\tenancy\TenantContext;
use think\facade\Db;
use think\Response;

final class MemberFriendController
{
    /** @var MemberIdentityService */
    private $identity;

    public function __construct(MemberIdentityService $identity)
    {
        $this->identity = $identity;
    }

    public function index(
        Request $request,
        TenantContext $tenant,
        AuthenticatedUserContext $auth
    ): Response {
        $member = $this->identity->resolve($tenant, $auth);
        $me = (int) $member['id'];
        $tenantId = $tenant->tenantId();

        $rows = Db::table('ch_member_friend')
            ->where('tenant_id', $tenantId)
            ->where('status', 'accepted')
            ->where(function ($query) use ($me) {
                $query->where('member_id', $me)->whereOr('friend_member_id', $me);
            })
            ->order('id', 'desc')
            ->limit(200)
            ->select()
            ->toArray();

        $friendIds = [];
        foreach ($rows as $row) {
            $friendId = (int) $row['member_id'] === $me
                ? (int) $row['friend_member_id']
                : (int) $row['member_id'];
            if ($friendId > 0) {
                $friendIds[$friendId] = $friendId;
            }
        }
        if ($friendIds === []) {
            return $this->success(['items' => []]);
        }

        $tier = (int) $request->get('tier', 0);
        $region = trim((string) $request->get('region', ''));
        $industry = trim((string) $request->get('industry', ''));

        $members = Db::table('ch_tenant_member')
            ->where('tenant_id', $tenantId)
            ->where('id', 'in', array_values($friendIds))
            ->where('status', 1)
            ->where('is_del', 0)
            ->select()
            ->toArray();

        $profiles = Db::table('ch_member_profile')
            ->where('tenant_id', $tenantId)
            ->where('member_id', 'in', array_values($friendIds))
            ->where('is_del', 0)
            ->select()
            ->toArray();

        $profileByMember = [];
        foreach ($profiles as $profile) {
            $profileByMember[(int) $profile['member_id']] = $profile;
        }

        $items = [];
        foreach ($members as $friend) {
            $friendId = (int) $friend['id'];
            if ($tier > 0 && (int) $friend['tier'] !== $tier) {
                continue;
            }
            $profile = $profileByMember[$friendId] ?? [];
            $province = (string) ($profile['province'] ?? '');
            $city = (string) ($profile['city'] ?? '');
            if ($region !== '' && $region !== $province && $region !== $city) {
                continue;
            }
            if ($industry !== '' && (string) ($profile['industry'] ?? '') !== $industry) {
                continue;
            }
            $items[] = [
                'id' => $friendId,
                'real_name' => (string) ($profile['real_name'] ?? ''),
                'avatar_object_key' => (string) ($profile['avatar_object_key'] ?? ''),
                'industry' => (string) ($profile['industry'] ?? ''),
                'company_name' => (string) ($profile['company_name'] ?? ''),
                'job_title' => (string) ($profile['job_title'] ?? ''),
                'tier' => (int) $friend['tier'],
                'province' => $province,
                'city' => $city,
                'region' => trim($province . ($city !== '' && $city !== $province ? ' ' . $city : '')),
                'status' => 'accepted',
            ];
        }

        return $this->success(['items' => $items]);
    }

    public function accept(
        Request $request,
        TenantContext $tenant,
        AuthenticatedUserContext $auth,
        $friend_id
    ): Response {
        $member = $this->identity->resolve($tenant, $auth);
        $me = (int) $member['id'];
        $tenantId = $tenant->tenantId();
        $friendRecordId = $this->positiveId($friend_id);

        // 事务 + 行锁：校验原状态必须为 pending，防止已拒绝/已接受的记录被重复置为 accepted（并发安全）
        $accepted = Db::transaction(function () use ($tenantId, $friendRecordId, $me): array {
            $row = Db::table('ch_member_friend')
                ->where('tenant_id', $tenantId)
                ->where('id', $friendRecordId)
                ->where('friend_member_id', $me)
                ->lock(true)
                ->find();
            if (!is_array($row)) {
                throw new MemberTransactionException(404, 'friend_request_not_found', 'Friend request was not found');
            }
            if ((string) ($row['status'] ?? '') !== 'pending') {
                throw new MemberTransactionException(409, 'friend_request_not_pending', '好友请求已处理，不能重复接受');
            }

            Db::table('ch_member_friend')
                ->where('id', $friendRecordId)
                ->update(['status' => 'accepted']);

            return [
                'id' => $friendRecordId,
                'member_id' => (int) $row['member_id'],
                'friend_member_id' => (int) $row['friend_member_id'],
                'status' => 'accepted',
            ];
        });

        return $this->success($accepted);
    }

    private function positiveId($value): int
    {
        if (is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value)) {
            $id = (int) $value;
            if ((string) $id === $value) {
                return $id;
            }
        }
        if (is_int($value) && $value > 0) {
            return $value;
        }
        throw new MemberTransactionException(422, 'request_validation_failed', 'friend_id must be a positive integer');
    }

    private function success(array $data): Response
    {
        return Response::create([
            'code' => 0,
            'msg' => 'ok',
            'data' => $data,
        ], 'json', 200);
    }
}
