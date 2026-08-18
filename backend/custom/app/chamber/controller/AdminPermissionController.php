<?php

declare(strict_types=1);

namespace app\chamber\controller;

use app\Request;
use app\chamber\exceptions\MemberTransactionException;
use app\chamber\identity\AuthenticatedAdminContext;
use app\chamber\tenancy\TenantContext;
use think\facade\Db;
use think\Response;

/**
 * Admin 动作级权限授权入口（补齐 ch_admin_permission 的唯一写入方）。
 * 路径：POST /api/chamber/admin/v1/admin-permissions
 * 权限：chamber.admin.permission（超管默认持有；授权操作需审计）
 */
final class AdminPermissionController
{
    private const MAX_BODY_BYTES = 8192;

    /** 授权/撤销 admin 权限点 */
    public function grant(Request $request, TenantContext $tenant, AuthenticatedAdminContext $admin): Response
    {
        $admin->assertPermission('chamber.admin.permission');
        $body = $this->decodeJson($request);
        $adminId = (int) ($body['admin_id'] ?? 0);
        $permission = trim((string) ($body['permission'] ?? ''));
        $granted = isset($body['granted']) ? (bool) $body['granted'] : true;

        if ($adminId <= 0 || $permission === '' || strlen($permission) > 64
            || preg_match('/^[a-z][a-z0-9_.]{1,63}$/', $permission) !== 1) {
            throw new MemberTransactionException(422, 'invalid_permission_input', 'admin_id/permission 非法');
        }
        $target = Db::table('eb_system_admin')
            ->where('id', $adminId)
            ->where('is_del', 0)
            ->find();
        if (!is_array($target)) {
            throw new MemberTransactionException(404, 'admin_not_found', '目标管理员不存在');
        }
        // 超管（level=0）无需授权，直接返回
        if ((int) $target['level'] === 0) {
            return json(['code' => 0, 'msg' => 'ok', 'data' => ['admin_id' => $adminId, 'permission' => $permission, 'granted' => true, 'note' => 'super admin']]);
        }

        $now = time();
        $tenantId = $tenant->tenantId();
        if ($granted) {
            Db::execute(
                'INSERT IGNORE INTO ch_admin_permission (tenant_id, admin_id, permission, granted_by, add_time) VALUES (?, ?, ?, ?, ?)',
                [$tenantId, $adminId, $permission, $admin->adminId(), $now]
            );
        } else {
            Db::table('ch_admin_permission')
                ->where('tenant_id', $tenantId)
                ->where('admin_id', $adminId)
                ->where('permission', $permission)
                ->delete();
        }

        return json(['code' => 0, 'msg' => 'ok', 'data' => [
            'admin_id' => $adminId,
            'permission' => $permission,
            'granted' => $granted,
        ]]);
    }

    /** 查询某 admin 的权限点（含全部点清单对照） */
    public function show(Request $request, TenantContext $tenant, AuthenticatedAdminContext $admin, $admin_id): Response
    {
        unset($request);
        $admin->assertPermission('chamber.admin.permission');
        $adminId = (int) $admin_id;
        if ($adminId <= 0) {
            throw new MemberTransactionException(422, 'invalid_admin_id', 'admin_id 非法');
        }
        $granted = Db::table('ch_admin_permission')
            ->where('tenant_id', $tenant->tenantId())
            ->where('admin_id', $adminId)
            ->column('permission');

        return json(['code' => 0, 'msg' => 'ok', 'data' => [
            'admin_id' => $adminId,
            'permissions' => $granted,
            'registry' => self::permissionRegistry(),
        ]]);
    }

    /** 权限点注册表（单一事实源，供授权界面与审计） */
    public static function permissionRegistry(): array
    {
        return [
            'chamber.member.read',
            'chamber.member.update',
            'chamber.member.points',
            'chamber.member.number.write',
            'chamber.ai_twin.read',
            'chamber.ai_twin.write',
            'chamber.graduate_verification.read',
            'chamber.graduate_verification.review',
            'chamber.event.read',
            'chamber.event.write',
            'chamber.event.manage',
            'chamber.event.checkin',
            'chamber.settlement.retry',
            'chamber.settlement.settle',
            'chamber.settlement.rules',
            'chamber.notification.write',
            'chamber.slot.manage',
            'chamber.product.write',
            'chamber.points_paths.write',
            'chamber.site_config.write',
            'chamber.admin.permission',
        ];
    }

    private function decodeJson(Request $request): array
    {
        $raw = (string) $request->getContent();
        if (strlen($raw) > self::MAX_BODY_BYTES) {
            throw new MemberTransactionException(413, 'payload_too_large', '请求体过大');
        }
        $body = json_decode($raw, true);

        return is_array($body) ? $body : [];
    }
}
