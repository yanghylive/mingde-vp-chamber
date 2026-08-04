<?php

declare(strict_types=1);

namespace app\chamber\controller;

use app\Request;
use app\chamber\tenancy\TenantContext;
use think\facade\Db;
use think\Response;

/**
 * 会员管理（admin）：等级查看 / L4 人工指定 / 手动开通·续费·降级 / 订单查看
 * 路径：/api/chamber/admin/v1/members
 */
final class MemberAdminController
{
    /** 会员列表：等级/到期时间/认证状态/姓名/手机号 */
    public function index(Request $request, TenantContext $tenant): Response
    {
        unset($request);
        $tenantId = $tenant->tenantId();

        $members = Db::table('ch_tenant_member')
            ->where('tenant_id', $tenantId)
            ->where('status', 1)
            ->order('id', 'asc')
            ->select()
            ->toArray();

        $rows = [];
        foreach ($members as $m) {
            $profile = Db::table('ch_member_profile')
                ->where('tenant_id', $tenantId)
                ->where('member_id', (int) $m['id'])
                ->find();
            $user = Db::table('eb_user')
                ->where('uid', (int) $m['uid'])
                ->find();
            $tier = (int) $m['tier'];
            $expire = (int) $m['tier_expire_time'];
            $rows[] = [
                'id'            => (int) $m['id'],
                'uid'           => (int) $m['uid'],
                'name'          => (string) ($profile['real_name'] ?? ($user['nickname'] ?? '')),
                'phone'         => (string) ($user['phone'] ?? ''),
                'tier'          => $tier,
                'tier_label'    => $this->tierLabel($tier),
                'expire_time'   => $expire,
                'is_expired'    => $expire > 0 && $expire < time() && $tier > 1 ? 1 : 0,
                'verification_status' => (int) $m['verification_status'],
                'certified_time'      => (int) $m['certified_time'],
                'join_time'     => (int) $m['join_time'],
            ];
        }

        return json(['code' => 0, 'msg' => 'ok', 'data' => ['items' => $rows, 'total' => count($rows)]]);
    }

    /** 等级调整：L4 人工指定 / 手动开通·续费（年费） / 降级 */
    public function update(int $member_id, Request $request, TenantContext $tenant): Response
    {
        $tenantId = $tenant->tenantId();
        $member = Db::table('ch_tenant_member')
            ->where('tenant_id', $tenantId)
            ->where('id', $member_id)
            ->find();
        if (!$member) {
            return json(['code' => 404, 'msg' => '会员不存在']);
        }

        $body = json_decode($request->getContent(), true);
        if (!is_array($body)) {
            $body = [];
        }
        $tier = (int) ($body['tier'] ?? 0);
        $action = (string) ($body['action'] ?? '');
        $remark = (string) ($body['remark'] ?? '');

        if ($tier < 1 || $tier > 4) {
            return json(['code' => 400, 'msg' => '等级必须为 1-4']);
        }

        $now = time();
        $data = ['tier' => $tier];
        $expire = (int) $member['tier_expire_time'];

        if ($tier >= 2) {
            // L2/L3：年费制，从当前到期时间（或现在）往后 +1 年
            $base = $expire > $now ? $expire : $now;
            $data['tier_expire_time'] = $base + 31536000;
        } else {
            // 降到 L1：清空到期
            $data['tier_expire_time'] = 0;
        }

        if ($tier === 4 && $action === 'certify') {
            // L4 人工指定（认证）：置认证状态
            $data['verification_status'] = 1;
            $data['certified_time'] = $now;
        }

        Db::table('ch_tenant_member')
            ->where('tenant_id', $tenantId)
            ->where('id', $member_id)
            ->update($data);

        // 落一条订单流水（manual 手动开通/调整，便于对账）
        Db::table('ch_membership_order')->insert([
            'tenant_id'       => $tenantId,
            'member_id'       => $member_id,
            'uid'             => (int) $member['uid'],
            'order_no'        => 'ADM' . date('YmdHis') . rand(1000, 9999),
            'tier'            => $tier,
            'amount'          => 0,
            'pay_type'        => 'manual',
            'status'          => 1,
            'expire_time'     => $data['tier_expire_time'] ?? 0,
            'remark'          => $remark ?: ($tier === 4 && $action === 'certify' ? 'L4 人工认证指定' : '管理员等级调整'),
            'add_time'        => $now,
            'update_time'     => $now,
        ]);

        return json(['code' => 0, 'msg' => 'ok', 'data' => [
            'tier'             => $tier,
            'tier_expire_time' => $data['tier_expire_time'] ?? 0,
        ]]);
    }

    /** 订单列表（会员收入/对账） */
    public function orders(Request $request, TenantContext $tenant): Response
    {
        unset($request);
        $tenantId = $tenant->tenantId();
        $orders = Db::table('ch_membership_order')
            ->where('tenant_id', $tenantId)
            ->order('id', 'desc')
            ->limit(200)
            ->select()
            ->toArray();

        $items = [];
        foreach ($orders as $o) {
            $items[] = [
                'id'          => (int) $o['id'],
                'order_no'    => (string) $o['order_no'],
                'member_id'   => (int) $o['member_id'],
                'uid'         => (int) $o['uid'],
                'tier'        => (int) $o['tier'],
                'amount'      => (int) $o['amount'],
                'amount_yuan' => round((int) $o['amount'] / 100, 2),
                'pay_type'    => (string) $o['pay_type'],
                'status'      => (int) $o['status'],
                'expire_time' => (int) $o['expire_time'],
                'remark'      => (string) $o['remark'],
                'add_time'    => (int) $o['add_time'],
                'paid_at'     => (int) $o['paid_at'],
            ];
        }

        return json(['code' => 0, 'msg' => 'ok', 'data' => ['items' => $items, 'total' => count($items)]]);
    }

    private function tierLabel(int $tier): string
    {
        return ['', 'L1 免费', 'L2 付费', 'L3 高会', 'L4 认证'][$tier] ?? 'L1 免费';
    }
}
