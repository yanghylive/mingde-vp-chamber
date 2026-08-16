<?php

declare(strict_types=1);

namespace app\chamber\controller;

use app\Request;
use app\chamber\identity\AuthenticatedUserContext;
use app\chamber\services\MemberIdentityService;
use app\chamber\tenancy\TenantContext;
use think\facade\Db;
use think\Response;

final class MemberDistributionController
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

        $code = $this->codeFor($tenantId, $memberId);
        $referred = Db::table('ch_distribution_record')
            ->where('tenant_id', $tenantId)
            ->where('member_id', $memberId)
            ->where('invited_member_id', '>', 0)
            ->count();
        $earned = (int) Db::table('ch_distribution_record')
            ->where('tenant_id', $tenantId)
            ->where('member_id', $memberId)
            ->where('status', 'credited')
            ->sum('points_earned');

        $rows = Db::table('ch_distribution_record')
            ->where('tenant_id', $tenantId)
            ->where('member_id', $memberId)
            ->where('invited_member_id', '>', 0)
            ->order('created_at', 'desc')
            ->order('id', 'desc')
            ->limit(50)
            ->select()
            ->toArray();

        $records = [];
        foreach ($rows as $r) {
            $invitedName = '';
            $profile = Db::table('ch_member_profile')
                ->where('tenant_id', $tenantId)
                ->where('member_id', (int) $r['invited_member_id'])
                ->find();
            if (is_array($profile)) {
                $invitedName = (string) ($profile['real_name'] ?? '');
            }
            $records[] = [
                'id'                 => (int) $r['id'],
                'invited_member_id'  => (int) $r['invited_member_id'],
                'invited_name'       => $invitedName,
                'points_earned'      => (int) $r['points_earned'],
                'status'             => (string) $r['status'],
                'created_at'         => (int) $r['created_at'],
            ];
        }

        return $this->success([
            'code' => $code,
            'referred_count' => $referred,
            'points_earned' => $earned,
            'records' => $records,
        ]);
    }

    private function codeFor(int $tenantId, int $memberId): string
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
