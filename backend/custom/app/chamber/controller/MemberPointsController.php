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
            ->where('status', 1) // status=0 为待补偿记录（如 AI 退款失败留痕），不展示给用户
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

    /**
     * 通用积分扣费（AI 对话等按次扣费的场景）。
     * Node 网关在调模型前调用本接口扣费（幂等），扣成功才允许继续。
     * body: {amount:int, idempotency_key:string, reason:string?}
     */
    public function consume(
        Request $request,
        TenantContext $tenant,
        AuthenticatedUserContext $auth
    ): Response {
        $body = $request->post();
        $amount = (int) ($body['amount'] ?? 0);
        $idemKey = (string) ($body['idempotency_key'] ?? '');
        $reason = (string) ($body['reason'] ?? 'AI 对话');

        if ($amount <= 0) {
            return Response::create(['code' => 1, 'msg' => 'amount must be positive', 'data' => null], 'json', 422);
        }
        if ($idemKey === '') {
            return Response::create(['code' => 1, 'msg' => 'idempotency_key required', 'data' => null], 'json', 422);
        }

        $tenantId = $tenant->tenantId();
        $member = $this->identity->resolve($tenant, $auth, true);
        $memberId = (int) $member['id'];

        // 幂等：同 key 已扣过则直接返回成功（防 Node 重试重复扣费）
        $idemHash = hash('sha256', 'points_consume:' . $idemKey);
        $existing = Db::table('ch_point_ledger')
            ->where('tenant_id', $tenantId)
            ->where('idempotency_key', $idemHash)
            ->find();
        if (is_array($existing)) {
            return $this->success([
                'consumed' => false,
                'balance' => (int) $existing['balance_after'],
            ]);
        }

        $now = time();
        $result = Db::transaction(function () use ($tenantId, $member, $memberId, $amount, $reason, $idemHash, $now) {
            $account = $this->identity->pointsAccount($tenantId, $member, true);
            $balance = (int) $account['balance'];
            if ($balance < $amount) {
                return ['ok' => false, 'balance' => $balance];
            }

            $newBalance = $balance - $amount;
            $updated = Db::table('ch_point_account')
                ->where('id', (int) $account['id'])
                ->where('tenant_id', $tenantId)
                ->where('version', (int) $account['version'])
                ->update([
                    'balance' => $newBalance,
                    'version' => (int) $account['version'] + 1,
                    'update_time' => $now,
                ]);
            if (!$updated) {
                return ['ok' => false, 'balance' => $balance, 'conflict' => true];
            }

            $ledgerId = (int) Db::table('ch_point_ledger')->insertGetId([
                'tenant_id' => $tenantId,
                'account_id' => (int) $account['id'],
                'member_id' => $memberId,
                'uid' => (int) $member['uid'],
                'delta' => -1 * $amount,
                'balance_after' => $newBalance,
                'source_type' => 'ai_chat',
                'source_id' => (string) $idemHash,
                'remark' => $reason,
                'idempotency_key' => $idemHash,
                'status' => 1,
                'reversal_id' => 0,
                'add_time' => $now,
            ]);

            return ['ok' => $ledgerId > 0, 'balance' => $newBalance];
        });

        if (!$result['ok']) {
            if (!empty($result['conflict'])) {
                return Response::create(['code' => 1, 'msg' => '积分账户已变动，请重试', 'data' => null], 'json', 409);
            }
            return Response::create(['code' => 1, 'msg' => '积分不足，需要 ' . $amount . ' 积分', 'data' => ['balance' => $result['balance']]], 'json', 409);
        }

        return $this->success([
            'consumed' => true,
            'amount' => $amount,
            'balance' => $result['balance'],
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
