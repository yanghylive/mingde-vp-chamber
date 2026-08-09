<?php

declare(strict_types=1);

namespace app\chamber\controller;

use app\Request;
use app\chamber\identity\AuthenticatedUserContext;
use app\chamber\services\MemberIdentityService;
use app\chamber\tenancy\TenantContext;
use think\facade\Db;
use think\Response;

final class MemberPointsController
{
    private const MAX_LEDGER_LIMIT = 100;

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
        $account = Db::table('ch_point_account')
            ->where('tenant_id', $tenant->tenantId())
            ->where('member_id', (int) $member['id'])
            ->find();

        return $this->success([
            'points' => is_array($account) ? (int) $account['balance'] : 0,
            'frozen_points' => is_array($account) ? (int) ($account['frozen_balance'] ?? 0) : 0,
        ]);
    }

    public function ledger(
        Request $request,
        TenantContext $tenant,
        AuthenticatedUserContext $auth
    ): Response {
        $member = $this->identity->resolve($tenant, $auth);
        $tenantId = $tenant->tenantId();
        $memberId = (int) $member['id'];

        $limit = min(self::MAX_LEDGER_LIMIT, max(1, (int) $request->get('limit', 20)));
        $page = max(1, (int) $request->get('page', 1));

        $query = Db::table('ch_point_ledger')
            ->where('tenant_id', $tenantId)
            ->where('member_id', $memberId)
            ->order('id', 'desc');

        $total = (clone $query)->count();
        $rows = $query->page($page, $limit)->select()->toArray();

        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'id' => (int) $row['id'],
                'delta' => (int) $row['delta'],
                'balance_after' => (int) $row['balance_after'],
                'source_type' => (string) $row['source_type'],
                'source_id' => (string) $row['source_id'],
                'status' => (int) $row['status'],
                'created_at' => (int) $row['add_time'],
            ];
        }

        return $this->success([
            'items' => $items,
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
        ]);
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
