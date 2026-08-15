<?php

declare(strict_types=1);

namespace app\chamber\controller;

use app\Request;
use app\chamber\tenancy\TenantContext;
use think\facade\Db;
use think\facade\Log;
use think\Response;

/**
 * 前端错误上报（S5 工程可观测 · 已升级）
 * 小程序全局错误（Vue errorHandler / 页面 JS 异常）上报到此。
 * 升级内容（审阅方案 P0）：
 *  - 脱敏：手机号/token/邮箱/身份证在写入前打码
 *  - 落库：ch_client_error 表（保留 30 天，支持查询/告警）
 *  - 限频：同 IP 5 秒去重 + 同 msg 短时间聚合（防刷）
 */
final class ClientErrorController
{
    /** 单 IP 上报间隔（秒）内的最小间隔，防刷 */
    private const MIN_INTERVAL_SECONDS = 5;

    /** 错误日志保留天数 */
    private const RETENTION_DAYS = 30;

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
        $msg = $this->sanitize((string) ($body['msg'] ?? ''));
        $stack = $this->sanitize((string) ($body['stack'] ?? ''));
        $page = (string) ($body['page'] ?? '');
        $platform = (string) ($body['platform'] ?? '');
        $uid = (int) ($body['uid'] ?? 0);

        $msgShort = mb_substr($msg, 0, 300);
        $stackShort = mb_substr($stack, 0, 800);

        Log::warning('client.error', [
            'tenant_id' => $tenant->tenantId(),
            'ip' => $ip,
            'page' => mb_substr($page, 0, 120),
            'platform' => mb_substr($platform, 0, 40),
            'msg' => $msgShort,
            'stack' => $stackShort,
        ]);

        // 文件兜底（保留最近 30 天，过期清理由 cron 或手动）
        @file_put_contents(
            '/tmp/client_errors.log',
            date('Y-m-d H:i:s') . ' [client.error] tenant=' . $tenant->tenantId()
                . ' uid=' . $uid
                . ' ip=' . $ip
                . ' page=' . mb_substr($page, 0, 120)
                . ' msg=' . $msgShort
                . ' stack=' . mb_substr($stack, 0, 500) . PHP_EOL,
            FILE_APPEND
        );

        // 落库（保留 30 天，支持后台查询/告警）
        try {
            Db::table('ch_client_error')->insert([
                'tenant_id' => $tenant->tenantId(),
                'uid' => $uid,
                'ip' => $ip,
                'page' => mb_substr($page, 0, 120),
                'platform' => mb_substr($platform, 0, 40),
                'msg' => $msgShort,
                'stack' => $stackShort,
                'add_time' => time(),
            ]);
            // 惰性清理：每次写入顺带删 30 天前的旧记录
            Db::table('ch_client_error')
                ->where('add_time', '<', time() - self::RETENTION_DAYS * 86400)
                ->limit(200)
                ->delete();
        } catch (\Throwable $e) {
            // 表不存在或写入失败不阻断（文件已落）
        }

        return Response::create(['status' => 200, 'msg' => 'ok', 'data' => ['dedup' => false]], 'json', 200);
    }

    /**
     * 脱敏：手机号/token/邮箱/身份证在写入前打码，防止敏感信息进日志。
     */
    private function sanitize(string $text): string
    {
        if ($text === '') {
            return '';
        }
        // 手机号：1[3-9]xxxxxxxxx → 1xx****xxxx
        $text = preg_replace('/(1[3-9]\d)\d{4}(\d{4})/', '$1****$2', $text);
        // 邮箱：user@domain → u***@domain
        $text = preg_replace('/([a-zA-Z0-9._%+-]{1,2})[a-zA-Z0-9._%+-]*@/', '$1***@', $text);
        // 身份证：前 6 后 4 保留
        $text = preg_replace('/(\d{6})\d{8}(\d{4})/', '$1********$2', $text);
        // 长 token/密钥（32+ 位字母数字）：只留前 6 位
        $text = preg_replace('/\b([A-Za-z0-9]{6})[A-Za-z0-9_-]{26,}\b/', '$1***', $text);
        return $text;
    }
}
