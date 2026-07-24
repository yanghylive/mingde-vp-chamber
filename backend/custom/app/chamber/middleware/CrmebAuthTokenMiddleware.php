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
use think\Response;

final class CrmebAuthTokenMiddleware implements MiddlewareInterface
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
            return $this->authenticationRequired($request);
        }

        $this->installCompatibilityMacros($request);
        $request->authenticatedUserContext = $context;
        $request->chamberAuthenticatedUser = $authInfo['user'];
        $request->chamberAuthenticatedTokenData = $authInfo['tokenData'];
        $this->container->instance(AuthenticatedUserContext::class, $context);
        $this->container->instance(AuthenticatedUserContext::CONTAINER_KEY, $context);

        try {
            return $next($request);
        } finally {
            $this->container->delete(AuthenticatedUserContext::class);
            $this->container->delete(AuthenticatedUserContext::CONTAINER_KEY);
            // ThinkPHP stores dynamic request properties in middleware data and has no __unset hook.
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
                && $this->authenticatedUserContext instanceof AuthenticatedUserContext;
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
