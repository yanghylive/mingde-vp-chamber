<?php

namespace app\chamber\middleware;

use app\Request;
use app\chamber\identity\AuthenticatedUserContext;
use app\chamber\identity\BearerTokenExtractor;
use app\services\user\UserAuthServices;
use ArrayAccess;
use Closure;
use crmeb\exceptions\AuthException;
use crmeb\interfaces\MiddlewareInterface;
use InvalidArgumentException;
use think\Container;

/**
 * 可选鉴权：有 token 时注入真实身份上下文，无 token 时注入匿名游客上下文（uid=0）。
 *
 * 用于「浏览类」公开读接口（活动/大咖/商品/会籍计划/今日3问等），满足微信审核
 * 「先体验浏览、后自行授权登录」的要求。写操作仍走 CrmebAuthTokenMiddleware 强制鉴权。
 */
final class OptionalAuthTokenMiddleware implements MiddlewareInterface
{
    /** @var UserAuthServices */
    private $authService;

    /** @var Container */
    private $container;

    public function __construct(UserAuthServices $authService, Container $container)
    {
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
            $authInfo = $this->authService->parseToken($token);
            $context = AuthenticatedUserContext::fromAuthInfo($authInfo);
        } catch (AuthException | InvalidArgumentException $exception) {
            // 未登录 / token 无效：降级为匿名游客，继续浏览
            $context = AuthenticatedUserContext::anonymous();
        }

        $this->installCompatibilityMacros($request);
        $request->authenticatedUserContext = $context;
        $request->chamberAuthenticatedUser = $context->isAnonymous() ? null : $authInfo['user'];
        $request->chamberAuthenticatedTokenData = $context->isAnonymous() ? [] : $authInfo['tokenData'];
        $this->container->instance(AuthenticatedUserContext::class, $context);
        $this->container->instance(AuthenticatedUserContext::CONTAINER_KEY, $context);

        try {
            return $next($request);
        } finally {
            $this->container->delete(AuthenticatedUserContext::class);
            $this->container->delete(AuthenticatedUserContext::CONTAINER_KEY);
            $request->authenticatedUserContext = null;
            $request->chamberAuthenticatedUser = null;
            $request->chamberAuthenticatedTokenData = null;
        }
    }

    private function installCompatibilityMacros(Request $request): void
    {
        $request->macro('uid', function (): int {
            return isset($this->authenticatedUserContext)
                && $this->authenticatedUserContext instanceof AuthenticatedUserContext
                ? $this->authenticatedUserContext->uid()
                : 0;
        });
        $request->macro('isLogin', function (): bool {
            return isset($this->authenticatedUserContext)
                && $this->authenticatedUserContext instanceof AuthenticatedUserContext
                && $this->authenticatedUserContext->uid() > 0;
        });
        $request->macro('user', function (string $key = null) {
            if (!isset($this->chamberAuthenticatedUser)) {
                return $key === null ? null : '';
            }
            $user = $this->chamberAuthenticatedUser;
            if ($key === null) {
                return $user;
            }
            if (is_array($user)) {
                return array_key_exists($key, $user) ? $user[$key] : '';
            }
            if ($user instanceof ArrayAccess && $user->offsetExists($key)) {
                return $user[$key];
            }
            if (is_object($user) && isset($user->{$key})) {
                return $user->{$key};
            }

            return '';
        });
        $request->macro('tokenData', function (): array {
            return isset($this->chamberAuthenticatedTokenData)
                && is_array($this->chamberAuthenticatedTokenData)
                ? $this->chamberAuthenticatedTokenData
                : [];
        });
    }
}
