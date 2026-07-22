<?php

namespace app\chamber\middleware;

use app\Request;
use app\chamber\services\RequestTraceId;
use Closure;
use crmeb\interfaces\MiddlewareInterface;
use think\Response;

final class RequestTraceMiddleware implements MiddlewareInterface
{
    /** @var RequestTraceId */
    private $traceId;

    public function __construct(RequestTraceId $traceId)
    {
        $this->traceId = $traceId;
    }

    public function handle(Request $request, Closure $next)
    {
        $trace = self::ensureTrace($request, $this->traceId);
        $requestId = $trace['request_id'];
        $response = $next($request);

        if (!$response instanceof Response) {
            return $response;
        }

        $data = $response->getData();
        if (is_array($data)) {
            $data['request_id'] = $requestId;
            $response->data($data);
        }

        return $response->header(self::responseHeaders($requestId, $trace['correlation_id']));
    }

    public static function ensureRequestId(Request $request, RequestTraceId $traceId = null): string
    {
        return self::ensureTrace($request, $traceId)['request_id'];
    }

    public static function ensureTrace(Request $request, RequestTraceId $traceId = null): array
    {
        $traceId = $traceId ?: new RequestTraceId();
        $requestCandidate = isset($request->requestId)
            ? (string) $request->requestId
            : (string) $request->header('X-Request-Id', '');
        $correlationCandidate = isset($request->correlationId)
            ? (string) $request->correlationId
            : (string) $request->header('X-Correlation-Id', '');
        $trace = $traceId->resolvePair($requestCandidate, $correlationCandidate);

        $request->requestId = $trace['request_id'];
        $request->correlationId = $trace['correlation_id'];

        return $trace;
    }

    public static function responseHeaders(string $requestId, string $correlationId): array
    {
        return [
            'X-Request-Id' => $requestId,
            'X-Correlation-Id' => $correlationId,
        ];
    }
}
