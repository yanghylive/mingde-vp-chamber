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
        $tenantId = $tenant->tenantId();
        $q = trim((string) ($request->get('q') ?? ''));
        $query = Db::table('ch_expert')
            ->where('tenant_id', $tenantId);
        if ($q !== '' && mb_strlen($q) <= 40) {
            $like = '%' . $q . '%';
            $query->where(function ($sub) use ($like) {
                $sub->where('name', 'like', $like)
                    ->whereOr('title', 'like', $like)
                    ->whereOr('company', 'like', $like)
                    ->whereOr('industry', 'like', $like)
                    ->whereOr('bio', 'like', $like);
            });
        }
        $rows = $query->order('id', 'desc')->select()->toArray();

        $items = [];
        foreach ($rows as $e) {
            $items[] = [
                'id'      => (int) $e['id'],
                'name'    => (string) $e['name'],
                'title'   => (string) $e['title'],
                'company' => (string) $e['company'],
                'industry' => (string) $e['industry'],
                'bio'     => (string) $e['bio'],
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

    /** 大咖资料列表（admin）：含角色 + 角色化资料 + 案例/资质/课程，供编辑回显 */
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
            $expertId = (int) $e['id'];
            $items[] = [
                'id'          => $expertId,
                'name'        => (string) $e['name'],
                'title'       => (string) $e['title'],
                'company'     => (string) $e['company'],
                'industry'    => (string) $e['industry'],
                'bio'         => (string) $e['bio'],
                'role'        => (string) ($e['role'] ?? 'mentor'),
                'member_id'   => (int) ($e['member_id'] ?? 0),
                'profile'     => $this->decodeJsonMap((string) ($e['profile_json'] ?? '')),
                'cases'       => $this->listRows($tenantId, $expertId, 'ch_expert_case', 'case'),
                'credentials' => $this->listRows($tenantId, $expertId, 'ch_expert_credential', 'credential'),
                'courses'     => $this->listRows($tenantId, $expertId, 'ch_expert_course', 'course'),
            ];
        }

        return json([
            'code' => 0,
            'msg'  => 'ok',
            'data' => [
                'items'       => $items,
                'total'       => count($items),
                'role_fields' => $this->roleFieldsAll($tenantId),
            ],
        ]);
    }

    private function decodeJsonMap(string $json): array
    {
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function listRows(int $tenantId, int $expertId, string $table, string $kind): array
    {
        $rows = Db::table($table)
            ->where('tenant_id', $tenantId)
            ->where('expert_id', $expertId)
            ->where('status', 1)
            ->where('is_del', 0)
            ->order('sort', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();

        $items = [];
        foreach ($rows as $r) {
            if ($kind === 'case') {
                $items[] = [
                    'id'          => (int) $r['id'],
                    'title'       => (string) $r['title'],
                    'description' => (string) $r['description'],
                    'industry'    => (string) $r['industry'],
                    'year'        => (int) $r['year'],
                ];
            } elseif ($kind === 'credential') {
                $items[] = [
                    'id'     => (int) $r['id'],
                    'name'   => (string) $r['name'],
                    'issuer' => (string) $r['issuer'],
                    'year'   => (int) $r['year'],
                ];
            } else {
                $items[] = [
                    'id'      => (int) $r['id'],
                    'title'   => (string) $r['title'],
                    'summary' => (string) $r['summary'],
                ];
            }
        }

        return $items;
    }

    /** 全部角色的字段模板（供 admin 动态表单渲染） */
    private function roleFieldsAll(int $tenantId): array
    {
        $rows = Db::table('ch_expert_role_field')
            ->where('tenant_id', $tenantId)
            ->where('status', 1)
            ->order('sort', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();

        $map = ['mentor' => [], 'coach' => [], 'industry_leader' => []];
        foreach ($rows as $r) {
            $role = (string) $r['role'];
            if (!isset($map[$role])) {
                $map[$role] = [];
            }
            $map[$role][] = [
                'field_key'   => (string) $r['field_key'],
                'field_label' => (string) $r['field_label'],
                'field_type'  => (string) $r['field_type'],
                'placeholder' => (string) $r['placeholder'],
            ];
        }

        return $map;
    }

    /** 保存大咖资料（expert_id=0 新增 / >0 更新），含角色 + 角色化资料 + 案例/资质/课程 */
    public function updateProfile(int $expert_id, Request $request, TenantContext $tenant): Response
    {
        $tenantId = $tenant->tenantId();
        $body = json_decode((string) $request->getContent(), true);
        if (!is_array($body)) {
            $body = [];
        }

        $role = trim((string) ($body['role'] ?? 'mentor'));
        if (!in_array($role, ['mentor', 'coach', 'industry_leader'], true)) {
            $role = 'mentor';
        }
        $profile = $body['profile'] ?? [];
        if (!is_array($profile)) {
            $profile = [];
        }

        $data = [
            'name'         => trim((string) ($body['name'] ?? '')),
            'title'        => trim((string) ($body['title'] ?? '')),
            'company'      => trim((string) ($body['company'] ?? '')),
            'industry'     => trim((string) ($body['industry'] ?? '')),
            'bio'          => trim((string) ($body['bio'] ?? '')),
            'role'         => $role,
            'member_id'    => max(0, (int) ($body['member_id'] ?? 0)),
            'profile_json' => json_encode($profile, JSON_UNESCAPED_UNICODE),
        ];
        if ($data['name'] === '') {
            return json(['code' => 400, 'msg' => '请填写大咖姓名']);
        }

        $now = time();
        if ($expert_id < 1) {
            $data['tenant_id'] = $tenantId;
            $data['add_time'] = $now;
            $data['update_time'] = $now;
            Db::transaction(function () use (&$expert_id, $tenantId, $data, $body) {
                $expert_id = (int) Db::table('ch_expert')->insertGetId($data);
                $this->syncShowcases($tenantId, $expert_id, $body);
            });

            return json(['code' => 0, 'msg' => 'ok', 'data' => ['id' => $expert_id, 'created' => true]]);
        }

        $exists = Db::table('ch_expert')
            ->where('tenant_id', $tenantId)
            ->where('id', $expert_id)
            ->find();
        if (!is_array($exists)) {
            return json(['code' => 404, 'msg' => '大咖不存在']);
        }

        $data['update_time'] = $now;
        Db::transaction(function () use ($tenantId, $expert_id, $data, $body) {
            Db::table('ch_expert')
                ->where('tenant_id', $tenantId)
                ->where('id', $expert_id)
                ->update($data);

            $this->syncShowcases($tenantId, $expert_id, $body);
        });

        return json(['code' => 0, 'msg' => 'ok', 'data' => ['id' => $expert_id, 'created' => false]]);
    }

    /** 案例/资质/课程整包替换（先删后插） */
    private function syncShowcases(int $tenantId, int $expertId, array $body): void
    {
        $now = time();

        Db::table('ch_expert_case')->where('tenant_id', $tenantId)->where('expert_id', $expertId)->delete();
        foreach ((array) ($body['cases'] ?? []) as $i => $row) {
            if (!is_array($row)) {
                continue;
            }
            $title = trim((string) ($row['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            Db::table('ch_expert_case')->insert([
                'tenant_id'   => $tenantId,
                'expert_id'   => $expertId,
                'title'       => $title,
                'description' => trim((string) ($row['description'] ?? '')),
                'industry'    => trim((string) ($row['industry'] ?? '')),
                'year'        => max(0, (int) ($row['year'] ?? 0)),
                'sort'        => (int) ($row['sort'] ?? $i),
                'status'      => 1,
                'is_del'      => 0,
                'add_time'    => $now,
                'update_time' => $now,
            ]);
        }

        Db::table('ch_expert_credential')->where('tenant_id', $tenantId)->where('expert_id', $expertId)->delete();
        foreach ((array) ($body['credentials'] ?? []) as $i => $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            Db::table('ch_expert_credential')->insert([
                'tenant_id'   => $tenantId,
                'expert_id'   => $expertId,
                'name'        => $name,
                'issuer'      => trim((string) ($row['issuer'] ?? '')),
                'year'        => max(0, (int) ($row['year'] ?? 0)),
                'sort'        => (int) ($row['sort'] ?? $i),
                'status'      => 1,
                'is_del'      => 0,
                'add_time'    => $now,
                'update_time' => $now,
            ]);
        }

        Db::table('ch_expert_course')->where('tenant_id', $tenantId)->where('expert_id', $expertId)->delete();
        foreach ((array) ($body['courses'] ?? []) as $i => $row) {
            if (!is_array($row)) {
                continue;
            }
            $title = trim((string) ($row['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            Db::table('ch_expert_course')->insert([
                'tenant_id'   => $tenantId,
                'expert_id'   => $expertId,
                'title'       => $title,
                'summary'     => trim((string) ($row['summary'] ?? '')),
                'sort'        => (int) ($row['sort'] ?? $i),
                'status'      => 1,
                'is_del'      => 0,
                'add_time'    => $now,
                'update_time' => $now,
            ]);
        }
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
