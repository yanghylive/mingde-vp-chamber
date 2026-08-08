<?php

declare(strict_types=1);

namespace app\chamber\controller;

use app\Request;
use app\chamber\identity\AuthenticatedUserContext;
use app\chamber\services\MemberIdentityService;
use app\chamber\tenancy\TenantContext;
use think\facade\Db;
use think\Response;

final class MemberStatsController
{
    /** @var MemberIdentityService */
    private $identity;

    public function __construct(MemberIdentityService $identity)
    {
        $this->identity = $identity;
    }

    public function show(
        Request $request,
        TenantContext $tenant,
        AuthenticatedUserContext $auth
    ): Response {
        $member = $this->identity->resolve($tenant, $auth);
        $tenantId = $tenant->tenantId();
        $memberId = (int) $member['id'];

        $account = Db::table('ch_point_account')
            ->where('tenant_id', $tenantId)
            ->where('member_id', $memberId)
            ->find();
        $points = is_array($account) ? (int) $account['balance'] : 0;

        $contribution = (int) Db::table('ch_contribution_ledger')
            ->where('tenant_id', $tenantId)
            ->where('member_id', $memberId)
            ->where('status', 1)
            ->sum('delta');

        $friends = (int) Db::table('ch_member_friend')
            ->where('tenant_id', $tenantId)
            ->where('status', 'accepted')
            ->where(function ($query) use ($memberId) {
                $query->where('member_id', $memberId)->whereOr('friend_member_id', $memberId);
            })
            ->count();

        $code = $this->distributionCode($tenantId, $memberId);
        $referred = (int) Db::table('ch_distribution_record')
            ->where('tenant_id', $tenantId)
            ->where('member_id', $memberId)
            ->where('invited_member_id', '>', 0)
            ->count();
        $earned = (int) Db::table('ch_distribution_record')
            ->where('tenant_id', $tenantId)
            ->where('member_id', $memberId)
            ->where('status', 'credited')
            ->sum('points_earned');

        return $this->success([
            'points' => $points,
            'contribution' => $contribution,
            'friends' => $friends,
            'distribution' => [
                'code' => $code,
                'referred_count' => $referred,
                'points_earned' => $earned,
            ],
        ]);
    }

    private function distributionCode(int $tenantId, int $memberId): string
    {
        $row = Db::table('ch_distribution_record')
            ->where('tenant_id', $tenantId)
            ->where('member_id', $memberId)
            ->order('id', 'asc')
            ->find();
        if (is_array($row) && trim((string) $row['code']) !== '') {
            return (string) $row['code'];
        }

        return 'MD' . strtoupper(substr(hash('sha256', 'dist:' . $tenantId . ':' . $memberId), 0, 10));
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
