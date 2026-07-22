<?php

namespace app\chamber;

use app\chamber\middleware\RequestTraceMiddleware;
use think\exception\Handle;
use think\exception\HttpException;
use think\exception\HttpResponseException;
use think\facade\Env;
use think\Response;
use Throwable;

final class ChamberExceptionHandle extends Handle
{
    protected $ignoreReport = [
        HttpException::class,
        HttpResponseException::class,
    ];

    public function render($request, Throwable $exception): Response
    {
        if ($exception instanceof HttpResponseException) {
            return $this->withTrace($request, parent::render($request, $exception));
        }

        $status = $exception instanceof HttpException ? $exception->getStatusCode() : 500;
        $message = $status === 404 ? 'Not found' : 'Internal server error';
        $data = [];

        if (Env::get('app_debug', false)) {
            $message = $exception->getMessage();
            $data = [
                'exception' => get_class($exception),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ];
        }

        $requestId = RequestTraceMiddleware::ensureRequestId($request);

        return $this->withTrace($request, Response::create([
            'status' => $status,
            'msg' => $message,
            'data' => $data,
            'request_id' => $requestId,
        ], 'json', $status));
    }

    private function withTrace($request, Response $response): Response
    {
        $trace = RequestTraceMiddleware::ensureTrace($request);
        $requestId = $trace['request_id'];
        $data = $response->getData();
        if (is_array($data)) {
            $data['request_id'] = $requestId;
            $response->data($data);
        }

        return $response->header(RequestTraceMiddleware::responseHeaders(
            $requestId,
            $trace['correlation_id']
        ));
    }
}
