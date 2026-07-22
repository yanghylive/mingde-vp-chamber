<?php

namespace app\chamber\middleware;

use app\Request;
use app\chamber\services\TenantRuntimeConfig;
use Closure;
use crmeb\interfaces\MiddlewareInterface;
use think\Response;

final class ChamberCorsMiddleware implements MiddlewareInterface
{
    /** @var TenantRuntimeConfig */
    private $runtimeConfig;

    public function __construct(TenantRuntimeConfig $runtimeConfig)
    {
        $this->runtimeConfig = $runtimeConfig;
    }

    public function handle(Request $request, Closure $next)
    {
        $origin = trim((string) $request->header('Origin', ''));
        if ($origin === '') {
            return $next($request);
        }

        if (!$this->runtimeConfig->allowsCorsOrigin($origin)) {
            return Response::create([
                'status' => 403,
                'msg' => 'CORS origin is not allowed',
                'data' => ['reason' => 'cors_origin_denied'],
            ], 'json', 403)->header(['Vary' => 'Origin']);
        }

        $headers = [
            'Access-Control-Allow-Origin' => $origin,
            'Access-Control-Allow-Headers' => implode(', ', [
                'Authorization',
                'Authori-zation',
                'Content-Type',
                'X-Requested-With',
                'X-Request-Id',
                'X-Correlation-Id',
                'X-Chamber-Tenant',
                'X-Chamber-Channel',
                'X-Chamber-Timestamp',
                'X-Chamber-Nonce',
                'X-Chamber-Signature',
            ]),
            'Access-Control-Allow-Methods' => 'GET, POST, PATCH, PUT, DELETE, OPTIONS',
            'Access-Control-Expose-Headers' => 'X-Request-Id, X-Correlation-Id',
            'Access-Control-Max-Age' => '600',
            'Vary' => 'Origin',
        ];
        if ($this->runtimeConfig->corsAllowsCredentials()) {
            $headers['Access-Control-Allow-Credentials'] = 'true';
        }

        $response = $next($request);

        return $response instanceof Response ? $response->header($headers) : $response;
    }
}
