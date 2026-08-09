<?php

namespace app\chamber\controller;

use app\Request;
use app\chamber\tenancy\TenantContext;
use think\facade\Db;
use think\Response;

/**
 * 大咖库（admin + 小程序端）：专家列表 / 资料 / 定价
 * 路径：
 *   GET    /api/chamber/v1/experts                        （小程序端，带 pricing）
 *   GET    /api/chamber/admin/v1/experts                  大咖定价列表（admin）
 *   GET    /api/chamber/admin/v1/experts/profile          大咖资料列表（admin）
 *   PATCH  /api/chamber/admin/v1/experts/:expert_id/profile  保存资料（id=0 新增，>0 更新）
 *   GET    /api/chamber/admin/v1/experts/:expert_id/pricing  定价详情
 *   PATCH  /api/chamber/admin/v1/experts/:expert_id/pricing  保存定价
 * 说明：数据源 ch_expert（migration 202608090003）。
 */
final class ExpertController
{
    /** 列表（admin 大咖定价页 / 小程序共用）：返回含 pricing 的专家列表 */
    public function index(Request $request, TenantContext $tenant): Response
    {
        unset($request);
        $tenantId = $tenant->tenantId();
        $rows = Db::table('ch_expert')
            ->where('tenant_id', $tenantId)
            ->order('id', 'desc')
            ->select()
            ->toArray();

        $items = [];
        foreach ($rows as $e) {
            $items[] = [
                'id'      => (int) $e['id'],
                'name'    => (string) $e['name'],
                'title'   => (string) $e['title'],
                'company' => (string) $e['company'],
                'pricing' => [
                    'online_points'  => (int) $e['online_points'],
                    'online_cash'    => (string) $e['online_cash'],
                    'offline_points' => (int) $e['offline_points'],
                    'offline_cash'   => (string) $e['offline_cash'],
                ],
            ];
        }

        return json(['code' => 0, 'msg' => 'ok', 'data' => ['items' => $items, 'total' => count($items)]]);
    }

    /** 大咖资料列表（admin） */
    public function profile(Request $request, TenantContext $tenant): Response
    {
        unset($request);
        $tenantId = $tenant->tenantId();
        $rows = Db::table('ch_expert')
            ->where('tenant_id', $tenantId)
            ->order('id', 'desc')
            ->select()
            ->toArray();

        $items = [];
        foreach ($rows as $e) {
            $items[] = [
                'id'       => (int) $e['id'],
                'name'     => (string) $e['name'],
                'title'    => (string) $e['title'],
                'company'  => (string) $e['company'],
                'industry' => (string) $e['industry'],
                'bio'      => (string) $e['bio'],
            ];
        }

        return json(['code' => 0, 'msg' => 'ok', 'data' => ['items' => $items, 'total' => count($items)]]);
    }

    /** 保存大咖资料（expert_id=0 新增 / >0 更新） */
    public function updateProfile(int $expert_id, Request $request, TenantContext $tenant): Response
    {
        $tenantId = $tenant->tenantId();
        $body = json_decode((string) $request->getContent(), true);
        if (!is_array($body)) {
            $body = [];
        }

        $data = [
            'name'     => trim((string) ($body['name'] ?? '')),
            'title'    => trim((string) ($body['title'] ?? '')),
            'company'  => trim((string) ($body['company'] ?? '')),
            'industry' => trim((string) ($body['industry'] ?? '')),
            'bio'      => trim((string) ($body['bio'] ?? '')),
        ];
        if ($data['name'] === '') {
            return json(['code' => 400, 'msg' => '请填写大咖姓名']);
        }

        $now = time();
        if ($expert_id < 1) {
            $data['tenant_id'] = $tenantId;
            $data['add_time'] = $now;
            $data['update_time'] = $now;
            $id = (int) Db::table('ch_expert')->insertGetId($data);

            return json(['code' => 0, 'msg' => 'ok', 'data' => ['id' => $id, 'created' => true]]);
        }

        $exists = Db::table('ch_expert')
            ->where('tenant_id', $tenantId)
            ->where('id', $expert_id)
            ->find();
        if (!is_array($exists)) {
            return json(['code' => 404, 'msg' => '大咖不存在']);
        }

        $data['update_time'] = $now;
        Db::table('ch_expert')
            ->where('tenant_id', $tenantId)
            ->where('id', $expert_id)
            ->update($data);

        return json(['code' => 0, 'msg' => 'ok', 'data' => ['id' => $expert_id, 'created' => false]]);
    }

    /** 定价详情（admin） */
    public function showPricing(int $expert_id, Request $request, TenantContext $tenant): Response
    {
        unset($request);
        $tenantId = $tenant->tenantId();
        $row = Db::table('ch_expert')
            ->where('tenant_id', $tenantId)
            ->where('id', $expert_id)
            ->find();
        if (!is_array($row)) {
            return json(['code' => 404, 'msg' => '大咖不存在']);
        }

        return json(['code' => 0, 'msg' => 'ok', 'data' => ['pricing' => $this->pricingArray($row)]]);
    }

    /** 保存定价（admin） */
    public function updatePricing(int $expert_id, Request $request, TenantContext $tenant): Response
    {
        $tenantId = $tenant->tenantId();
        $row = Db::table('ch_expert')
            ->where('tenant_id', $tenantId)
            ->where('id', $expert_id)
            ->find();
        if (!is_array($row)) {
            return json(['code' => 404, 'msg' => '大咖不存在']);
        }

        $body = json_decode((string) $request->getContent(), true);
        if (!is_array($body)) {
            $body = [];
        }

        $data = [
            'online_points'  => max(0, (int) ($body['online_points'] ?? 0)),
            'online_cash'    => $this->normalizeMoney($body['online_cash'] ?? 0),
            'offline_points' => max(0, (int) ($body['offline_points'] ?? 0)),
            'offline_cash'   => $this->normalizeMoney($body['offline_cash'] ?? 0),
            'update_time'    => time(),
        ];
        Db::table('ch_expert')
            ->where('tenant_id', $tenantId)
            ->where('id', $expert_id)
            ->update($data);

        return json(['code' => 0, 'msg' => 'ok', 'data' => ['pricing' => $data]]);
    }

    private function pricingArray(array $row): array
    {
        return [
            'online_points'  => (int) $row['online_points'],
            'online_cash'    => (string) $row['online_cash'],
            'offline_points' => (int) $row['offline_points'],
            'offline_cash'   => (string) $row['offline_cash'],
        ];
    }

    private function normalizeMoney($value): string
    {
        $amount = (float) $value;
        if ($amount < 0) {
            $amount = 0;
        }

        return number_format($amount, 2, '.', '');
    }
}
