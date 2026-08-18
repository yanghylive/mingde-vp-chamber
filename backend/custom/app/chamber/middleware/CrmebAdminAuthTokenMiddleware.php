<?php

declare(strict_types=1);

namespace app\chamber\middleware;

use app\Request;
use app\chamber\identity\AuthenticatedAdminContext;
use app\chamber\identity\BearerTokenExtractor;
use app\chamber\tenancy\TenantContext;
use app\services\system\admin\AdminAuthServices;
use Closure;
use crmeb\exceptions\AuthException;
use crmeb\interfaces\MiddlewareInterface;
use InvalidArgumentException;
use think\Container;
use think\facade\Db;
use think\Response;

final class CrmebAdminAuthTokenMiddleware implements MiddlewareInterface
{
    /** @var AdminAuthServices */
    private $authService;

    /** @var Container */
    private $container;

    public function __construct(
        AdminAuthServices $authService,
        Container $container
    ) {
        $this->authService = $authService;
        $this->container = $container;
    }

    public function handle(Request $request, Closure $next)
    {
        try {
            $token = BearerTokenExtractor::fromHeaders(
                $request->header('Authorization', null),
                $request->header('Authori-zation', null)
            );
            $adminInfo = $this->authService->parseToken($token);
            $activeAdmin = Db::table('eb_system_admin')
                ->where('id', (int) ($adminInfo['id'] ?? 0))
                ->field('id,level,status,is_del')
                ->find();
            if (!is_array($activeAdmin)
                || (int) $activeAdmin['status'] !== 1
                || (int) $activeAdmin['is_del'] !== 0
                || (int) $activeAdmin['level'] !== (int) ($adminInfo['level'] ?? -1)) {
                throw new InvalidArgumentException('CRMEB administrator is inactive');
            }
            // 从 ch_admin_permission 加载动作级权限点（超管 level=0 自动全放行）。
            // 权限表唯一键是 (tenant_id, admin_id, permission)——必须按当前租户过滤，
            // 否则同一 admin 跨租户权限会合并串权（租户 A 管理员继承租户 B 权限）。
            $adminId = (int) ($adminInfo['id'] ?? 0);
            $tenantContext = $request->tenantContext;
            if (!$tenantContext instanceof TenantContext) {
                throw new InvalidArgumentException('Tenant context must be resolved before admin authentication');
            }
            $tenantId = $tenantContext->tenantId();
            $permissions = Db::table('ch_admin_permission')
                ->where('tenant_id', $tenantId)
                ->where('admin_id', $adminId)
                ->column('permission');
            $context = AuthenticatedAdminContext::fromAuthInfo($adminInfo, $permissions ?: []);
        } catch (AuthException | InvalidArgumentException $exception) {
            return $this->authenticationRequired($request);
        }

        $request->authenticatedAdminContext = $context;
        $request->chamberAuthenticatedAdmin = $adminInfo;
        $request->macro('isAdminLogin', function (): bool {
            return isset($this->authenticatedAdminContext)
                && $this->authenticatedAdminContext instanceof AuthenticatedAdminContext;
        });
        $request->macro('adminId', function (): int {
            return isset($this->authenticatedAdminContext)
                && $this->authenticatedAdminContext instanceof AuthenticatedAdminContext
                ? $this->authenticatedAdminContext->adminId()
                : 0;
        });
        $request->macro('adminInfo', function () {
            return isset($this->chamberAuthenticatedAdmin) ? $this->chamberAuthenticatedAdmin : null;
        });
        $this->container->instance(AuthenticatedAdminContext::class, $context);
        $this->container->instance(AuthenticatedAdminContext::CONTAINER_KEY, $context);

        try {
            return $next($request);
        } finally {
            $this->container->delete(AuthenticatedAdminContext::class);
            $this->container->delete(AuthenticatedAdminContext::CONTAINER_KEY);
            $request->authenticatedAdminContext = null;
            $request->chamberAuthenticatedAdmin = null;
        }
    }

    private function authenticationRequired(Request $request): Response
    {
        $trace = RequestTraceMiddleware::ensureTrace($request);

        return Response::create([
            'status' => 401,
            'msg' => 'Authentication required',
            'data' => [
                'reason' => 'authentication_required',
                'field_errors' => [],
            ],
            'request_id' => $trace['request_id'],
        ], 'json', 401)->header(RequestTraceMiddleware::responseHeaders(
            $trace['request_id'],
            $trace['correlation_id']
        ));
    }
}
