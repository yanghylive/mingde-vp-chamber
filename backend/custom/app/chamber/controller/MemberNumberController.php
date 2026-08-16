<?php

declare(strict_types=1);

namespace app\chamber\controller;

use app\Request;
use app\chamber\identity\AuthenticatedUserContext;
use app\chamber\services\MemberIdentityService;
use app\chamber\tenancy\TenantContext;
use think\facade\Db;
use think\Response;

/**
 * 会员番号（前台）：查看自己的番号列表 + 选择一个作为展示番号（member_no）。
 *   GET  /api/chamber/v1/me/numbers                 列表（含 is_selected）
 *   POST /api/chamber/v1/me/numbers/:number_id/select  选择展示番号
 */
final class MemberNumberController
{
    /** @var MemberIdentityService */
    private $identity;

    public function __construct(MemberIdentityService $identity)
    {
        $this->identity = $identity;
    }

    public function index(Request $request, TenantContext $tenant, AuthenticatedUserContext $auth): Response
    {
        unset($request);
        $member = $this->identity->resolve($tenant, $auth);
        $memberId = (int) $member['id'];

        $rows = Db::table('ch_member_number')
            ->where('tenant_id', $tenant->tenantId())
            ->where('member_id', $memberId)
            ->where('status', 1)
            ->where('is_del', 0)
            ->order('sort', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();

        $items = [];
        foreach ($rows as $r) {
            $items[] = [
                'id'          => (int) $r['id'],
                'number'      => (string) $r['number'],
                'label'       => (string) $r['label'],
                'is_selected' => (int) $r['is_selected'] === 1,
            ];
        }

        return json(['code' => 0, 'msg' => 'ok', 'data' => ['items' => $items]]);
    }

    public function select(int $number_id, Request $request, TenantContext $tenant, AuthenticatedUserContext $auth): Response
    {
        unset($request);
        $member = $this->identity->resolve($tenant, $auth);
        $memberId = (int) $member['id'];
        $tenantId = $tenant->tenantId();

        $row = Db::table('ch_member_number')
            ->where('tenant_id', $tenantId)
            ->where('member_id', $memberId)
            ->where('id', $number_id)
            ->where('status', 1)
            ->where('is_del', 0)
            ->find();
        if (!is_array($row)) {
            return json(['code' => 404, 'msg' => '番号不存在']);
        }

        $now = time();
        Db::transaction(function () use ($tenantId, $memberId, $number_id, $now): void {
            Db::table('ch_member_number')
                ->where('tenant_id', $tenantId)
                ->where('member_id', $memberId)
                ->where('is_del', 0)
                ->update(['is_selected' => 0, 'update_time' => $now]);
            Db::table('ch_member_number')
                ->where('id', $number_id)
                ->update(['is_selected' => 1, 'update_time' => $now]);
        });

        return json(['code' => 0, 'msg' => 'ok', 'data' => ['member_no' => (string) $row['number']]]);
    }
}
