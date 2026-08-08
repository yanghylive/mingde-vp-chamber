<?php

declare(strict_types=1);

namespace app\chamber\services;

use app\chamber\exceptions\MemberTransactionException;
use app\chamber\identity\AuthenticatedUserContext;
use app\chamber\tenancy\TenantContext;
use think\facade\Db;

/** Resolves the current tenant member and its points account for P2 read endpoints. */
final class MemberIdentityService
{
    public function resolve(TenantContext $tenant, AuthenticatedUserContext $auth, bool $lock = false): array
    {
        $query = Db::table('ch_tenant_member')
            ->where('tenant_id', $tenant->tenantId())
            ->where('uid', $auth->uid());
        if ($lock) {
            $query->lock(true);
        }
        $member = $query->find();
        if (!is_array($member)) {
            throw new MemberTransactionException(404, 'member_not_found', 'Member account was not found');
        }
        if ((int) $member['status'] !== 1 || (int) $member['is_del'] !== 0) {
            throw new MemberTransactionException(403, 'member_disabled', 'Member account is not active');
        }
        if ((int) $member['current_channel_id'] !== $tenant->channelId()) {
            throw new MemberTransactionException(403, 'tenant_scope_denied', 'Member is not active in the requested channel');
        }

        return $member;
    }

    public function pointsAccount(int $tenantId, array $member, bool $required = true): ?array
    {
        $account = Db::table('ch_point_account')
            ->where('tenant_id', $tenantId)
            ->where('member_id', (int) $member['id'])
            ->lock(true)
            ->find();
        if (!is_array($account)) {
            if ($required) {
                throw new MemberTransactionException(409, 'points_required', 'Member points are insufficient');
            }

            return null;
        }
        if ((int) $account['uid'] !== (int) $member['uid']) {
            throw new MemberTransactionException(409, 'points_required', 'Member points account is inconsistent');
        }

        return $account;
    }
}
