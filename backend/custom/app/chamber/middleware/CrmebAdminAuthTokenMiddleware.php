<?php

declare(strict_types=1);

namespace app\chamber\middleware;

use app\Request;
use app\chamber\identity\AuthenticatedAdminContext;
use app\chamber\identity\BearerTokenExtractor;
use app\services\system\admin\AdminAuthServices;
use app\services\system\SystemMenusServices;
use Closure;
use crmeb\exceptions\AuthException;
use crmeb\interfaces\MiddlewareInterface;
use InvalidArgumentException;
use think\Container;
use think\Response;

final class CrmebAdminAuthTokenMiddleware implements MiddlewareInterface
{
    /** @var AdminAuthServices */
    private $authService;

    /** @var SystemMenusServices */
    private $menusService;

    /** @var Container */
    private $container;

    public function __construct(
        AdminAuthServices $authService,
        SystemMenusServices $menusService,
        Container $container
    ) {
        $this->authService = $authService;
        $this->menusService = $menusService;
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
            $permissions = [];
            if ((int) ($adminInfo['level'] ?? -1) !== 0) {
                list($unusedMenus, $permissions) = $this->menusService->getMenusList(
                    $adminInfo['roles'] ?? [],
                    (int) ($adminInfo['level'] ?? -1)
                );
                unset($unusedMenus);
            }
            if (!is_array($permissions)) {
                throw new InvalidArgumentException('CRMEB administrator permissions are invalid');
            }
            $context = AuthenticatedAdminContext::fromAuthInfo($adminInfo, array_values($permissions));
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
