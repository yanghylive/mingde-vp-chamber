<?php

namespace app\chamber\middleware;

use app\Request;
use app\chamber\exceptions\TenantResolutionException;
use app\chamber\services\TenantContextResolver;
use app\chamber\tenancy\TenantContext;
use app\chamber\tenancy\TenantResolutionInput;
use Closure;
use crmeb\interfaces\MiddlewareInterface;
use InvalidArgumentException;
use think\Container;
use think\Response;

final class TenantContextMiddleware implements MiddlewareInterface
{
    /** @var TenantContextResolver */
    private $resolver;

    /** @var Container */
    private $container;

    public function __construct(TenantContextResolver $resolver, Container $container)
    {
        $this->resolver = $resolver;
        $this->container = $container;
    }

    public function handle(Request $request, Closure $next, bool $required = true)
    {
        $request->tenantContext = null;

        try {
            $externalPath = $request->baseUrl() ?: $request->pathinfo();
            $context = $this->resolver->resolve(new TenantResolutionInput(
                $request->method(),
                $request->host(false),
                $externalPath,
                $request->header()
            ), $required);
        } catch (TenantResolutionException $exception) {
            return $this->rejection(
                $exception->httpStatus(),
                $exception->getMessage(),
                $exception->reason()
            );
        } catch (InvalidArgumentException $exception) {
            return $this->rejection(400, 'Tenant request context is invalid', TenantResolutionException::INVALID_INPUT);
        }

        if (!$context) {
            return $next($request);
        }

        $request->tenantContext = $context;
        $this->container->instance(TenantContext::class, $context);
        $this->container->instance(TenantContext::CONTAINER_KEY, $context);

        try {
            return $next($request);
        } finally {
            $this->container->delete(TenantContext::class);
            $this->container->delete(TenantContext::CONTAINER_KEY);
            $request->tenantContext = null;
        }
    }

    private function rejection(int $httpStatus, string $message, string $reason): Response
    {
        return Response::create([
            'status' => $httpStatus,
            'msg' => $message,
            'data' => ['reason' => $reason],
        ], 'json', $httpStatus);
    }
}
