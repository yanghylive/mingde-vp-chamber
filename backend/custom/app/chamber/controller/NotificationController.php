<?php

declare(strict_types=1);

namespace app\chamber\controller;

use app\Request;
use app\chamber\identity\AuthenticatedUserContext;
use app\chamber\services\MemberIdentityService;
use app\chamber\tenancy\TenantContext;
use think\facade\Db;
use think\Response;

final class NotificationController
{
    private const MAX_LIMIT = 100;

    /** @var MemberIdentityService */
    private $identity;

    public function __construct(MemberIdentityService $identity)
    {
        $this->identity = $identity;
    }

    public function index(
        Request $request,
        TenantContext $tenant,
        AuthenticatedUserContext $auth
    ): Response {
        $member = $this->identity->resolve($tenant, $auth);
        $tenantId = $tenant->tenantId();
        $memberId = (int) $member['id'];

        $limit = min(self::MAX_LIMIT, max(1, (int) $request->get('limit', 20)));
        $page = max(1, (int) $request->get('page', 1));

        $query = Db::table('ch_event_notification')
            ->where('tenant_id', $tenantId)
            ->where('is_del', 0)
            ->where(function ($q) use ($memberId) {
                $q->where('member_id', $memberId)->whereOr('member_id', 0);
            })
            ->order('id', 'desc');

        $total = (clone $query)->count();
        $rows = $query->page($page, $limit)->select()->toArray();

        // 批量查当前用户的已读状态（按用户隔离，取代单行 read 字段）
        $readMap = [];
        $ids = array_column($rows, 'id');
        if ($ids) {
            $reads = Db::table('ch_notification_read')
                ->where('tenant_id', $tenantId)
                ->where('member_id', $memberId)
                ->whereIn('notification_id', $ids)
                ->select()
                ->toArray();
            foreach ($reads as $r) {
                $readMap[(int) $r['notification_id']] = true;
            }
        }

        $items = [];
        foreach ($rows as $row) {
            $nid = (int) $row['id'];
            $items[] = [
                'id' => $nid,
                'event_id' => (int) $row['event_id'],
                'type' => (int) $row['event_id'] > 0 ? 'event' : 'system',
                'title' => (string) $row['title'],
                'body' => (string) $row['body'],
                'read' => isset($readMap[$nid]),
                'created_at' => (int) $row['created_at'],
            ];
        }

        return $this->success([
            'items' => $items,
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
        ]);
    }

    /** 标记通知已读（幂等，按用户隔离） */
    public function markRead(
        Request $request,
        TenantContext $tenant,
        AuthenticatedUserContext $auth,
        $notification_id
    ): Response {
        unset($request);
        $member = $this->identity->resolve($tenant, $auth);
        $tenantId = $tenant->tenantId();
        $memberId = (int) $member['id'];
        $nid = (int) $notification_id;

        // 校验通知存在且对当前用户可见（自己或广播，未撤销）
        $notif = Db::table('ch_event_notification')
            ->where('tenant_id', $tenantId)
            ->where('id', $nid)
            ->where('is_del', 0)
            ->where(function ($q) use ($memberId) {
                $q->where('member_id', $memberId)->whereOr('member_id', 0);
            })
            ->find();
        if (!is_array($notif)) {
            return json(['code' => 404, 'msg' => '通知不存在']);
        }

        // 幂等写已读（唯一键 notification_id+member_id，重复读忽略）
        $now = time();
        try {
            Db::table('ch_notification_read')->insert([
                'notification_id' => $nid,
                'tenant_id' => $tenantId,
                'member_id' => $memberId,
                'read_time' => $now,
                'add_time' => $now,
            ]);
        } catch (\Throwable $e) {
            // duplicate key：已读过，忽略
        }

        return $this->success(['read' => true]);
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
