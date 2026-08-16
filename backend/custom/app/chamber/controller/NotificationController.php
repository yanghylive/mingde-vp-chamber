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
            ->where(function ($q) use ($memberId) {
                $q->where('member_id', $memberId)->whereOr('member_id', 0);
            })
            ->order('id', 'desc');

        $total = (clone $query)->count();
        $rows = $query->page($page, $limit)->select()->toArray();

        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'id' => (int) $row['id'],
                'event_id' => (int) $row['event_id'],
                'type' => (int) $row['event_id'] > 0 ? 'event' : 'system',
                'title' => (string) $row['title'],
                'body' => (string) $row['body'],
                'read' => (int) $row['read'] === 1,
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

    private function success(array $data): Response
    {
        return Response::create([
            'code' => 0,
            'msg' => 'ok',
            'data' => $data,
        ], 'json', 200);
    }
}
