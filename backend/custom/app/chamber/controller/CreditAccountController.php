<?php

declare(strict_types=1);

namespace app\chamber\controller;

use app\chamber\exceptions\MemberTransactionException;
use app\chamber\identity\AuthenticatedUserContext;
use app\chamber\services\CreditConsumeService;
use app\chamber\tenancy\TenantContext;
use app\Request;
use think\facade\Db;
use think\Response;

/** 我的课时（个人 + 家庭账户快照，含授予明细与账本）。 */
final class CreditAccountController
{
    /** @var CreditConsumeService */
    private $consume;

    public function __construct(CreditConsumeService $consume = null)
    {
        $this->consume = $consume ?: new CreditConsumeService();
    }

    public function show(Request $request, TenantContext $tenant, AuthenticatedUserContext $auth): Response
    {
        unset($request);
        $member = $this->member($tenant, $auth, false);
        $personal = $this->accountView($tenant, 'member', (int) $member['id']);

        $familyViews = [];
        $familyIds = Db::table('ch_family_member')
            ->where('tenant_id', $tenant->tenantId())
            ->where('uid', $auth->uid())
            ->where('status', 1)
            ->column('family_id');
        if (is_array($familyIds)) {
            foreach (array_unique($familyIds) as $fid) {
                $view = $this->accountView($tenant, 'family', (int) $fid);
                if (is_array($view)) {
                    $familyViews[] = $view;
                }
            }
        }

        return Response::create([
            'status' => 200,
            'msg' => 'ok',
            'data' => ['personal' => $personal, 'family' => $familyViews],
        ], 'json', 200);
    }

    private function accountView(TenantContext $tenant, string $ownerType, int $ownerId): ?array
    {
        $account = Db::table('ch_credit_account')
            ->where('tenant_id', $tenant->tenantId())
            ->where('owner_type', $ownerType)
            ->where('owner_id', $ownerId)
            ->find();
        if (!is_array($account)) {
            return null;
        }
        $snapshot = $this->consume->snapshot($tenant->tenantId(), (int) $account['id']);

        return $snapshot === null ? null : $snapshot->toPublicArray();
    }

    private function member(TenantContext $tenant, AuthenticatedUserContext $auth, bool $lock): array
    {
        $query = Db::table('ch_tenant_member')
            ->where('tenant_id', $tenant->tenantId())
            ->where('uid', $auth->uid());
        if ($lock) {
            $query->lock(true);
        }
        $row = $query->find();
        if (!is_array($row)) {
            throw new MemberTransactionException(404, 'member_not_found', '会员不存在');
        }
        if ((int) $row['status'] !== 1 || (int) $row['is_del'] !== 0) {
            throw new MemberTransactionException(403, 'member_disabled', '会员状态不可用');
        }
        if ((int) $row['current_channel_id'] !== $tenant->channelId()) {
            throw new MemberTransactionException(403, 'tenant_scope_denied', '会员不在当前渠道');
        }

        return $row;
    }
}
