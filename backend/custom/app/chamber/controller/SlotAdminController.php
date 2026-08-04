<?php

declare(strict_types=1);

namespace app\chamber\controller;

use app\Request;
use app\chamber\tenancy\TenantContext;
use think\facade\Db;
use think\Response;

/**
 * 大咖档期管理（admin）
 * GET    /api/chamber/admin/v1/slots                    全部大咖档期（按大咖分组）
 * POST   /api/chamber/admin/v1/slots                    新增档期 {expert_id, start_time, end_time, location}
 * DELETE /api/chamber/admin/v1/slots/:slot_id           删除档期
 */
final class SlotAdminController
{
    private const MAX_BODY_BYTES = 8192;

    private function expertName(int $tenantId, int $expertId): string
    {
        $profile = Db::table('ch_member_profile')
            ->where('tenant_id', $tenantId)
            ->where('member_id', $expertId)
            ->find();
        if (is_array($profile) && !empty($profile['real_name'])) {
            return (string) $profile['real_name'];
        }
        $seeds = [1 => '陈明远', 2 => '李一舟', 3 => '王建峰'];
        return $seeds[$expertId] ?? '大咖#' . $expertId;
    }

    /** 档期列表（按大咖分组） */
    public function index(Request $request, TenantContext $tenant): Response
    {
        unset($request);
        $tenantId = $tenant->tenantId();
        $slots = Db::table('ch_expert_slot')
            ->where('tenant_id', $tenantId)
            ->order('start_time', 'desc')
            ->select()
            ->toArray();

        $items = [];
        foreach ($slots as $s) {
            $expertId = (int) $s['expert_id'];
            $items[] = [
                'id'          => (int) $s['id'],
                'expert_id'   => $expertId,
                'expert_name' => $this->expertName($tenantId, $expertId),
                'start_time'  => (int) $s['start_time'],
                'end_time'    => (int) $s['end_time'],
                'status'      => (string) $s['status'],
                'location'    => (int) $s['location'],
                'location_label' => (int) $s['location'] === 1 ? '线下' : '线上',
            ];
        }

        return json(['code' => 0, 'msg' => 'ok', 'data' => ['items' => $items, 'total' => count($items)]]);
    }

    /** 新增档期 */
    public function store(Request $request, TenantContext $tenant): Response
    {
        $tenantId = $tenant->tenantId();
        $raw = $request->getContent();
        if (strlen($raw) > self::MAX_BODY_BYTES) {
            return json(['code' => 413, 'msg' => '请求体过大']);
        }
        $body = json_decode($raw, true);
        if (!is_array($body)) {
            $body = [];
        }

        $expertId = (int) ($body['expert_id'] ?? 0);
        $startTime = (int) ($body['start_time'] ?? 0);
        $endTime = (int) ($body['end_time'] ?? 0);
        $location = (int) ($body['location'] ?? 0);

        if ($expertId < 1) {
            return json(['code' => 400, 'msg' => '请选择大咖']);
        }
        if ($startTime < 1 || $endTime <= $startTime) {
            return json(['code' => 400, 'msg' => '档期时间不合法（结束需晚于开始）']);
        }
        if ($location !== 0 && $location !== 1) {
            return json(['code' => 400, 'msg' => 'location 必须为 0（线上）或 1（线下）']);
        }

        $now = time();
        $id = (int) Db::table('ch_expert_slot')->insertGetId([
            'tenant_id'  => $tenantId,
            'expert_id'  => $expertId,
            'start_time' => $startTime,
            'end_time'   => $endTime,
            'status'     => 'open',
            'location'   => $location,
            'add_time'   => $now,
        ]);

        return json(['code' => 0, 'msg' => 'ok', 'data' => ['id' => $id]]);
    }

    /** 删除档期（含已预约——保留预约记录，仅移除档期） */
    public function delete(int $slot_id, Request $request, TenantContext $tenant): Response
    {
        unset($request);
        $tenantId = $tenant->tenantId();
        $slot = Db::table('ch_expert_slot')
            ->where('tenant_id', $tenantId)
            ->where('id', $slot_id)
            ->find();
        if (!is_array($slot)) {
            return json(['code' => 404, 'msg' => '档期不存在']);
        }

        Db::table('ch_expert_slot')
            ->where('tenant_id', $tenantId)
            ->where('id', $slot_id)
            ->delete();

        return json(['code' => 0, 'msg' => 'ok', 'data' => ['deleted' => $slot_id]]);
    }
}
