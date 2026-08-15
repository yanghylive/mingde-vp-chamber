<?php

declare(strict_types=1);

namespace app\chamber\controller;

use app\Request;
use app\chamber\tenancy\TenantContext;
use think\facade\Log;
use think\Response;

/**
 * 前端错误上报（S5 工程可观测）
 * 小程序全局错误（Vue errorHandler / 页面 JS 异常）上报到此，写入服务端日志。
 * 目的：前端"没反应"类问题不再依赖用户贴 console，服务端日志直接可见。
 */
final class ClientErrorController
{
    /** 单 IP 上报间隔（秒）内的最小间隔，防刷 */
    private const MIN_INTERVAL_SECONDS = 5;

    public function store(Request $request, TenantContext $tenant): Response
    {
        $ip = (string) $request->ip();

        // 简单限频：5 秒内同 IP 只记一次
        $rateKey = 'client_error_' . $ip . '_' . (int) floor(time() / self::MIN_INTERVAL_SECONDS);
        if (\think\facade\Cache::has($rateKey)) {
            return Response::create(['status' => 200, 'msg' => 'ok', 'data' => ['dedup' => true]], 'json', 200);
        }
        \think\facade\Cache::set($rateKey, 1, self::MIN_INTERVAL_SECONDS);

        $body = $request->post();
        $msg = (string) ($body['msg'] ?? '');
        $stack = (string) ($body['stack'] ?? '');
        $page = (string) ($body['page'] ?? '');
        $platform = (string) ($body['platform'] ?? '');

        Log::warning('client.error', [
            'tenant_id' => $tenant->tenantId(),
            'ip' => $ip,
            'page' => mb_substr($page, 0, 120),
            'platform' => mb_substr($platform, 0, 40),
            'msg' => mb_substr($msg, 0, 300),
            'stack' => mb_substr($stack, 0, 800),
        ]);
        // 双保险：写固定文件（某些环境 think Log 通道异常时兜底）
        @file_put_contents(
            '/tmp/client_errors.log',
            date('Y-m-d H:i:s') . ' [client.error] tenant=' . $tenant->tenantId()
                . ' ip=' . $ip
                . ' page=' . mb_substr($page, 0, 120)
                . ' msg=' . mb_substr($msg, 0, 300)
                . ' stack=' . mb_substr($stack, 0, 500) . PHP_EOL,
            FILE_APPEND
        );

        return Response::create(['status' => 200, 'msg' => 'ok', 'data' => ['dedup' => false]], 'json', 200);
    }
}
