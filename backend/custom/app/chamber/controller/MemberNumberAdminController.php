<?php

declare(strict_types=1);

namespace app\chamber\controller;

use app\Request;
use app\chamber\identity\AuthenticatedAdminContext;
use app\chamber\tenancy\TenantContext;
use think\facade\Db;
use think\Response;

/**
 * 会员番号（admin）：后台给会员录入多个番号（number + label），整包替换。
 *   GET  /api/chamber/admin/v1/members/:member_id/numbers   列表
 *   POST /api/chamber/admin/v1/members/:member_id/numbers   批量替换（body {numbers:[{number,label}]}）
 */
final class MemberNumberAdminController
{
    public function index(int $member_id, Request $request, TenantContext $tenant): Response
    {
        unset($request);
        $tenantId = $tenant->tenantId();
        $rows = Db::table('ch_member_number')
            ->where('tenant_id', $tenantId)
            ->where('member_id', $member_id)
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

        return json(['code' => 0, 'msg' => 'ok', 'data' => ['items' => $items, 'total' => count($items)]]);
    }

    public function store(int $member_id, Request $request, TenantContext $tenant, AuthenticatedAdminContext $admin): Response
    {
        $admin->assertPermission('chamber.member.number.write');
        $tenantId = $tenant->tenantId();
        $member = Db::table('ch_tenant_member')
            ->where('tenant_id', $tenantId)
            ->where('id', $member_id)
            ->find();
        if (!is_array($member)) {
            return json(['code' => 404, 'msg' => '会员不存在']);
        }

        $body = json_decode((string) $request->getContent(), true);
        $numbers = is_array($body) && is_array($body['numbers'] ?? null) ? $body['numbers'] : [];

        $now = time();
        // 整包替换：旧番号软删，新番号插入
        Db::table('ch_member_number')
            ->where('tenant_id', $tenantId)
            ->where('member_id', $member_id)
            ->update(['is_del' => 1, 'update_time' => $now]);

        $inserted = 0;
        $firstId = 0;
        foreach (array_values($numbers) as $i => $n) {
            if (!is_array($n)) {
                continue;
            }
            $number = trim((string) ($n['number'] ?? ''));
            if ($number === '') {
                continue;
            }
            $label = trim((string) ($n['label'] ?? ''));
            $id = (int) Db::table('ch_member_number')->insertGetId([
                'tenant_id'   => $tenantId,
                'member_id'   => $member_id,
                'number'      => $number,
                'label'       => $label,
                'is_selected' => 0,
                'sort'        => $i,
                'status'      => 1,
                'is_del'      => 0,
                'add_time'    => $now,
                'update_time' => $now,
            ]);
            if ($id > 0) {
                $inserted++;
                if ($firstId === 0) {
                    $firstId = $id;
                }
            }
        }

        // 保证总有一个选中项（第一个番号），否则前台 member_no 为空
        if ($firstId > 0) {
            $hasSelected = Db::table('ch_member_number')
                ->where('tenant_id', $tenantId)
                ->where('member_id', $member_id)
                ->where('is_del', 0)
                ->where('is_selected', 1)
                ->find();
            if (!is_array($hasSelected)) {
                Db::table('ch_member_number')->where('id', $firstId)->update(['is_selected' => 1, 'update_time' => $now]);
            }
        }

        return json(['code' => 0, 'msg' => 'ok', 'data' => ['inserted' => $inserted]]);
    }
}
