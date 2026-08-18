<?php

declare(strict_types=1);

namespace app\chamber\controller;

use app\Request;
use app\chamber\identity\AuthenticatedAdminContext;
use app\chamber\services\SettlementService;
use app\chamber\tenancy\TenantContext;
use think\facade\Db;
use think\Response;

/**
 * 分账管理（admin）：规则配置 + 分账单对账 + 手动触发结算 + 抵扣余额查询
 */
final class SettlementAdminController
{
    /** @var SettlementService */
    private $settlement;

    public function __construct(?SettlementService $settlement = null)
    {
        $this->settlement = $settlement ?: new SettlementService();
    }

    /** 分账规则列表 */
    public function rules(Request $request, TenantContext $tenant, AuthenticatedAdminContext $admin): Response
    {
        $admin->assertPermission('chamber.settlement.read');
        $businessType = (string) $request->get('business_type', SettlementService::BUSINESS_MEMBERSHIP);

        return json(['code' => 0, 'msg' => 'ok', 'data' => [
            'items' => $this->settlement->rules($tenant->tenantId(), $businessType),
        ]]);
    }

    /** 保存分账规则（整包替换，比例可改） */
    public function saveRules(Request $request, TenantContext $tenant, AuthenticatedAdminContext $admin): Response
    {
        $admin->assertPermission('chamber.settlement.rule.write');
        $body = json_decode((string) $request->getContent(), true);
        if (!is_array($body)) {
            $body = [];
        }
        $businessType = (string) ($body['business_type'] ?? '');
        $rules = $body['rules'] ?? [];
        if (!is_array($rules)) {
            $rules = [];
        }
        if ($businessType === '') {
            return json(['code' => 400, 'msg' => '请指定业务类型']);
        }

        try {
            $this->settlement->saveRules($tenant->tenantId(), $businessType, $rules);
        } catch (\Throwable $e) {
            return json(['code' => 400, 'msg' => $e->getMessage()]);
        }

        return json(['code' => 0, 'msg' => 'ok']);
    }

    /** 分账单列表（对账） */
    public function settlements(Request $request, TenantContext $tenant, AuthenticatedAdminContext $admin): Response
    {
        $admin->assertPermission('chamber.settlement.read');
        $limit = min(100, max(1, (int) $request->get('limit', 20)));
        $page = max(1, (int) $request->get('page', 1));

        $query = Db::table('ch_settlement')
            ->where('tenant_id', $tenant->tenantId())
            ->order('id', 'desc');

        $total = (clone $query)->count();
        $rows = $query->page($page, $limit)->select()->toArray();

        // 批量查明细，消除 N+1
        $detailMap = [];
        $settlementIds = array_values(array_filter(array_map(static function ($r) {
            return (int) $r['id'];
        }, $rows)));
        if ($settlementIds) {
            foreach (Db::table('ch_settlement_detail')->whereIn('settlement_id', $settlementIds)->select()->toArray() as $d) {
                $detailMap[(int) $d['settlement_id']][] = $d;
            }
        }

        $items = [];
        foreach ($rows as $r) {
            $detailItems = [];
            foreach ($detailMap[(int) $r['id']] ?? [] as $d) {
                $detailItems[] = [
                    'id' => (int) $d['id'],
                    'receiver_type' => (string) $d['receiver_type'],
                    'receiver_name' => (string) $d['receiver_name'],
                    'ratio' => (int) $d['ratio'],
                    'amount' => (string) $d['amount'],
                    'channel' => (string) $d['channel'],
                    'status' => (string) $d['status'],
                ];
            }
            $items[] = [
                'id' => (int) $r['id'],
                'business_type' => (string) $r['business_type'],
                'order_no' => (string) $r['order_no'],
                'order_amount' => (string) $r['order_amount'],
                'status' => (string) $r['status'],
                'details' => $detailItems,
            ];
        }

        return json(['code' => 0, 'msg' => 'ok', 'data' => [
            'items' => $items,
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
        ]]);
    }

    /** 抵扣余额（退款下期抵扣的对账） */
    public function balances(Request $request, TenantContext $tenant, AuthenticatedAdminContext $admin): Response
    {
        $admin->assertPermission('chamber.settlement.read');
        unset($request);

        return json(['code' => 0, 'msg' => 'ok', 'data' => [
            'items' => $this->settlement->balances($tenant->tenantId()),
        ]]);
    }

    /** 手动触发 T+1 结算（cron 也可直接调 SettlementService::runDue） */
    public function runDue(Request $request, TenantContext $tenant, AuthenticatedAdminContext $admin): Response
    {
        $admin->assertPermission('chamber.settlement.retry');
        unset($request);

        return json(['code' => 0, 'msg' => 'ok', 'data' => $this->settlement->runDue()]);
    }

    /** 人工重试单条明细（retry_count 封顶后的恢复通道） */
    public function retry(Request $request, TenantContext $tenant, AuthenticatedAdminContext $admin, $detail_id): Response
    {
        $admin->assertPermission('chamber.settlement.retry');
        unset($request);
        $detailId = (int) $detail_id;
        if ($detailId <= 0) {
            return json(['code' => 422, 'msg' => 'detail_id 必须是正整数']);
        }

        $ok = $this->settlement->retryDetail($tenant->tenantId(), $detailId);

        return json([
            'code' => $ok ? 0 : 409,
            'msg' => $ok ? 'ok' : '明细不存在或状态不允许重试（unknown 且渠道可能已发出时禁止自动重打）',
            'data' => ['retried' => $ok],
        ]);
    }

    /** 手动补单结算（某笔订单漏结算时运营补触发，也用于测试） */
    public function settle(Request $request, TenantContext $tenant, AuthenticatedAdminContext $admin): Response
    {
        $admin->assertPermission('chamber.settlement.manual_adjust');
        $body = json_decode((string) $request->getContent(), true);
        if (!is_array($body)) {
            $body = [];
        }
        $businessType = (string) ($body['business_type'] ?? '');
        $orderNo = (string) ($body['order_no'] ?? '');
        $orderAmount = (string) ($body['order_amount'] ?? '');
        if ($businessType === '' || $orderNo === '' || $orderAmount === '') {
            return json(['code' => 400, 'msg' => '参数不全：business_type / order_no / order_amount 必填']);
        }

        try {
            $result = $this->settlement->settle($tenant->tenantId(), $businessType, $orderNo, $orderAmount);
        } catch (\Throwable $e) {
            return json(['code' => 400, 'msg' => $e->getMessage()]);
        }

        return json(['code' => 0, 'msg' => 'ok', 'data' => $result]);
    }
}
