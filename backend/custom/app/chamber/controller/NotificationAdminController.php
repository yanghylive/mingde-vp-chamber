<?php

declare(strict_types=1);

namespace app\chamber\controller;

use app\Request;
use app\chamber\tenancy\TenantContext;
use think\facade\Db;
use think\Response;

/**
 * 通知发布（admin）：
 *   GET    /admin/v1/notifications          列表
 *   POST   /admin/v1/notifications          发布（scope=all 广播全体 / scope=member 指定会员）
 *   PATCH  /admin/v1/notifications/:id      编辑
 *   DELETE /admin/v1/notifications/:id      撤销（物理删除）
 *
 * 广播模型：member_id=0 表示「全体会员」（哨兵值，真实会员 id 为正整数），
 * 会员端查询兼容 member_id=自己 OR member_id=0。
 */
final class NotificationAdminController
{
    private const MAX_LIMIT = 100;

    /** 通知列表 */
    public function index(Request $request, TenantContext $tenant): Response
    {
        $tenantId = $tenant->tenantId();
        $limit = min(self::MAX_LIMIT, max(1, (int) $request->get('limit', 50)));
        $page = max(1, (int) $request->get('page', 1));

        $query = Db::table('ch_event_notification')
            ->where('tenant_id', $tenantId)
            ->order('id', 'desc');

        $total = (clone $query)->count();
        $rows = $query->page($page, $limit)->select()->toArray();

        $items = [];
        foreach ($rows as $row) {
            $items[] = $this->row($row);
        }

        return json(['code' => 0, 'msg' => 'ok', 'data' => [
            'items' => $items,
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
        ]]);
    }

    /** 发布通知 */
    public function store(Request $request, TenantContext $tenant): Response
    {
        $tenantId = $tenant->tenantId();
        $body = json_decode((string) $request->getContent(), true);
        if (!is_array($body)) {
            $body = [];
        }

        $title = trim((string) ($body['title'] ?? ''));
        $content = trim((string) ($body['body'] ?? ''));
        $scope = (string) ($body['scope'] ?? 'all');
        $memberId = (int) ($body['member_id'] ?? 0);

        if ($title === '') {
            return json(['code' => 400, 'msg' => '请填写通知标题']);
        }
        if ($content === '') {
            return json(['code' => 400, 'msg' => '请填写通知内容']);
        }
        if (!in_array($scope, ['all', 'member'], true)) {
            $scope = 'all';
        }

        $now = time();
        if ($scope === 'member') {
            $member = Db::table('ch_tenant_member')
                ->where('tenant_id', $tenantId)
                ->where('id', $memberId)
                ->where('is_del', 0)
                ->find();
            if (!is_array($member)) {
                return json(['code' => 404, 'msg' => '会员不存在']);
            }
            $id = (int) Db::table('ch_event_notification')->insertGetId([
                'tenant_id' => $tenantId,
                'member_id' => $memberId,
                'event_id' => 0,
                'title' => $title,
                'body' => $content,
                'read' => 0,
                'created_at' => $now,
                'add_time' => $now,
            ]);

            return json(['code' => 0, 'msg' => 'ok', 'data' => ['sent' => 1, 'id' => $id]]);
        }

        // scope=all：member_id=0 广播全体，sent 返回当前会员总数
        $id = (int) Db::table('ch_event_notification')->insertGetId([
            'tenant_id' => $tenantId,
            'member_id' => 0,
            'event_id' => 0,
            'title' => $title,
            'body' => $content,
            'read' => 0,
            'created_at' => $now,
            'add_time' => $now,
        ]);
        $sent = (int) Db::table('ch_tenant_member')
            ->where('tenant_id', $tenantId)
            ->where('is_del', 0)
            ->count();

        return json(['code' => 0, 'msg' => 'ok', 'data' => ['sent' => $sent, 'id' => $id]]);
    }

    /** 编辑通知（title/body） */
    public function update(int $notification_id, Request $request, TenantContext $tenant): Response
    {
        $tenantId = $tenant->tenantId();
        $body = json_decode((string) $request->getContent(), true);
        if (!is_array($body)) {
            $body = [];
        }

        $title = trim((string) ($body['title'] ?? ''));
        $content = trim((string) ($body['body'] ?? ''));

        $exists = Db::table('ch_event_notification')
            ->where('tenant_id', $tenantId)
            ->where('id', $notification_id)
            ->find();
        if (!is_array($exists)) {
            return json(['code' => 404, 'msg' => '通知不存在']);
        }

        $data = [];
        if ($title !== '') {
            $data['title'] = $title;
        }
        if ($content !== '') {
            $data['body'] = $content;
        }
        if ($data) {
            Db::table('ch_event_notification')
                ->where('tenant_id', $tenantId)
                ->where('id', $notification_id)
                ->update($data);
        }

        return json(['code' => 0, 'msg' => 'ok']);
    }

    /** 撤销通知（物理删除） */
    public function destroy(int $notification_id, Request $request, TenantContext $tenant): Response
    {
        unset($request);
        Db::table('ch_event_notification')
            ->where('tenant_id', $tenant->tenantId())
            ->where('id', $notification_id)
            ->delete();

        return json(['code' => 0, 'msg' => 'ok']);
    }

    private function row(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'member_id' => (int) $row['member_id'],
            'event_id' => (int) $row['event_id'],
            'title' => (string) $row['title'],
            'body' => (string) $row['body'],
            'read' => (int) $row['read'] === 1,
            'created_at' => (int) $row['created_at'],
        ];
    }
}
