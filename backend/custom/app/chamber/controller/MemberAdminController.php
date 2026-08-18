<?php

declare(strict_types=1);

namespace app\chamber\controller;

use app\Request;
use app\chamber\exceptions\MemberTransactionException;
use app\chamber\identity\AuthenticatedAdminContext;
use app\chamber\tenancy\TenantContext;
use think\facade\Db;
use think\Response;
use Throwable;

/**
 * 会员管理（admin）：等级查看 / L4 人工指定 / 手动开通·续费·降级 / 订单查看 / 积分调整
 * 路径：/api/chamber/admin/v1/members
 */
final class MemberAdminController
{
    /** 会员列表：等级/到期时间/认证状态/姓名/手机号（敏感数据，需 chamber.member.read） */
    public function index(Request $request, TenantContext $tenant, AuthenticatedAdminContext $admin): Response
    {
        unset($request);
        $admin->assertPermission('chamber.member.read');
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
    public function update(int $member_id, Request $request, TenantContext $tenant, AuthenticatedAdminContext $admin): Response
    {
        $admin->assertPermission('chamber.member.update');
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

        Db::transaction(function () use ($tenantId, $member_id, $member, $data, $tier, $action, $remark, $now) {
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
        });

        return json(['code' => 0, 'msg' => 'ok', 'data' => [
            'tier'             => $tier,
            'tier_expire_time' => $data['tier_expire_time'] ?? 0,
        ]]);
    }

    /** 订单列表（会员收入/对账，敏感，需 chamber.member.read） */
    public function orders(Request $request, TenantContext $tenant, AuthenticatedAdminContext $admin): Response
    {
        unset($request);
        $admin->assertPermission('chamber.member.read');
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

    /**
     * 积分调整（后台手动调积分，L1-L4 通用，无 tier 限制）。
     * POST /api/chamber/admin/v1/members/:member_id/points/adjust
     * body: {"delta": 100|-50, "reason": "活动补偿", "caller_key": "..."}
     *  - delta 可正可负（单次 |delta| ≤ 1000000），reason 必填（审计），caller_key 幂等（重放返回原结果）
     *  - 复用项目标准账本模式：事务 + 行锁 + 乐观锁 version + ch_point_ledger 幂等流水（source_type=admin_adjust，source_id=管理员ID，remark=原因）
     *  - 权限：chamber.member.points（超管自动通过）
     */
    public function adjustPoints(
        int $member_id,
        Request $request,
        TenantContext $tenant,
        AuthenticatedAdminContext $admin
    ): Response {
        $admin->assertPermission('chamber.member.points');

        $tenantId = $tenant->tenantId();
        $member = Db::table('ch_tenant_member')
            ->where('tenant_id', $tenantId)
            ->where('id', $member_id)
            ->where('status', 1)
            ->find();
        if (!$member) {
            return json(['code' => 404, 'msg' => '会员不存在']);
        }

        $body = json_decode($request->getContent(), true);
        if (!is_array($body)) {
            return json(['code' => 400, 'msg' => '请求体必须是 JSON']);
        }
        $delta = (int) ($body['delta'] ?? 0);
        $reason = trim((string) ($body['reason'] ?? ''));
        $callerKey = trim((string) ($body['caller_key'] ?? ''));

        if ($delta === 0) {
            return json(['code' => 400, 'msg' => 'delta 不能为 0']);
        }
        if ($delta > 1000000 || $delta < -1000000) {
            return json(['code' => 400, 'msg' => '单次调整幅度过大（|delta| 最大 1000000）']);
        }
        if ($reason === '' || mb_strlen($reason) > 200) {
            return json(['code' => 400, 'msg' => 'reason 必填且不超过 200 字']);
        }
        if (!preg_match('/^[A-Za-z0-9:_-]{8,120}$/D', $callerKey)) {
            return json(['code' => 400, 'msg' => 'caller_key 需为 8-120 位字母/数字/:_-']);
        }

        $now = time();
        $idempotencyKey = hash('sha256', $tenantId . ':' . $callerKey);

        try {
            $result = Db::transaction(function () use ($tenantId, $member, $delta, $reason, $idempotencyKey, $now, $admin): array {
                // 幂等命中：同一 caller_key 直接返回原结果（余额取当前账户值）
                $existing = Db::table('ch_point_ledger')
                    ->where('tenant_id', $tenantId)
                    ->where('idempotency_key', $idempotencyKey)
                    ->find();
                if (is_array($existing)) {
                    $current = $this->lockOrCreatePointAccount($tenantId, (int) $member['id'], (int) $member['uid'], $now);

                    return [
                        'ledger_id' => (int) $existing['id'],
                        'balance' => (int) $current['balance'],
                        'delta' => (int) $existing['delta'],
                        'idempotent' => true,
                    ];
                }

                $account = $this->lockOrCreatePointAccount($tenantId, (int) $member['id'], (int) $member['uid'], $now);
                $newBalance = (int) $account['balance'] + $delta;
                if ($newBalance < 0) {
                    throw new MemberTransactionException(409, 'insufficient_points', '会员积分不足，无法扣减');
                }
                $updated = Db::table('ch_point_account')
                    ->where('id', (int) $account['id'])
                    ->where('tenant_id', $tenantId)
                    ->where('version', (int) $account['version'])
                    ->update([
                        'balance' => $newBalance,
                        'version' => (int) $account['version'] + 1,
                        'update_time' => $now,
                    ]);
                if ($updated !== 1) {
                    throw new MemberTransactionException(409, 'points_adjust_failed', '积分余额更新冲突，请重试');
                }
                $ledgerId = (int) Db::table('ch_point_ledger')->insertGetId([
                    'tenant_id' => $tenantId,
                    'account_id' => (int) $account['id'],
                    'member_id' => (int) $member['id'],
                    'uid' => (int) $member['uid'],
                    'delta' => $delta,
                    'balance_after' => $newBalance,
                    'source_type' => 'admin_adjust',
                    'source_id' => (string) $admin->adminId(),
                    'remark' => $reason,
                    'idempotency_key' => $idempotencyKey,
                    'status' => 1,
                    'reversal_id' => 0,
                    'add_time' => $now,
                ]);

                return [
                    'ledger_id' => $ledgerId,
                    'balance' => $newBalance,
                    'delta' => $delta,
                    'idempotent' => false,
                ];
            });
        } catch (MemberTransactionException $exception) {
            return json(['code' => $exception->getCode(), 'msg' => $exception->getMessage()]);
        }

        return json(['code' => 0, 'msg' => 'ok', 'data' => $result]);
    }

    /**
     * 行锁读取积分账户；不存在则初始化（与 EventRewardService 同模式，唯一键并发兜底）。
     */
    private function lockOrCreatePointAccount(int $tenantId, int $memberId, int $uid, int $now): array
    {
        $account = Db::table('ch_point_account')
            ->where('tenant_id', $tenantId)
            ->where('member_id', $memberId)
            ->lock(true)
            ->find();
        if (!is_array($account)) {
            try {
                Db::table('ch_point_account')->insert([
                    'tenant_id' => $tenantId,
                    'member_id' => $memberId,
                    'uid' => $uid,
                    'balance' => 0,
                    'version' => 1,
                    'add_time' => $now,
                    'update_time' => $now,
                ]);
            } catch (Throwable $exception) {
                // 并发首写可能撞唯一键，忽略后重新读取
            }
            $account = Db::table('ch_point_account')
                ->where('tenant_id', $tenantId)
                ->where('member_id', $memberId)
                ->lock(true)
                ->find();
        }
        if (!is_array($account)) {
            throw new MemberTransactionException(409, 'points_account_failed', '积分账户无法初始化');
        }

        return $account;
    }

    private function tierLabel(int $tier): string
    {
        return ['', 'L1 免费', 'L2 付费', 'L3 高会', 'L4 认证'][$tier] ?? 'L1 免费';
    }
}
