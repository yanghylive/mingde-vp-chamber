<?php

declare(strict_types=1);

namespace app\chamber\controller;

use app\chamber\services\ChamberLogger;
use think\facade\Db;
use think\Response;

/**
 * 监控健康检查（监控告警骨架 B3）
 * GET /api/chamber/monitor/health
 *   - service 服务状态
 *   - db      数据库连通性（SELECT 1）
 *   - cron    定时任务心跳（repair.php 每 5 分钟跑一次，> 15 分钟视为失联）
 *   - errors  近 24 小时客户端错误上报条数（ch_client_error）
 *   - overall ok / degraded
 */
final class MonitorController
{
    /** 定时任务心跳文件（repair.php 每次运行写入） */
    private const HEARTBEAT_FILE = '/tmp/chamber_cron_heartbeat.json';

    /** 心跳超时阈值（秒）：repair 每 5 分钟一次，超过 15 分钟视为失联 */
    private const CRON_STALE_SECONDS = 900;

    public function health(): Response
    {
        $dbOk = $this->dbPing();
        $cron = $this->cronHealth();
        $errorCount = $this->recentErrorCount();

        $healthy = $dbOk && $cron['healthy'];

        $payload = [
            'service' => 'chamber',
            'api_version' => 'v1',
            'time' => time(),
            'overall' => $healthy ? 'ok' : 'degraded',
            'db' => ['ok' => $dbOk],
            'cron' => $cron,
            'errors_last_24h' => $errorCount,
        ];

        if (!$healthy) {
            (new ChamberLogger())->warn('monitor.health.degraded', $payload);
            $this->sendWebhookAlert($payload);
        }

        return Response::create([
            'status' => 200,
            'msg' => 'ok',
            'data' => $payload,
        ], 'json', 200);
    }

    /**
     * 告警 webhook（可配置）：eb_system_config.monitor_webhook_url 存 webhook 地址，
     * monitor_webhook_type 存类型（wecom/dingtalk/feishu，默认 wecom）。
     * 健康降级时发送一条文本告警。
     */
    private function sendWebhookAlert(array $payload): void
    {
        try {
            $url = (string) Db::table('eb_system_config')
                ->where('menu_name', 'monitor_webhook_url')
                ->value('value');
            $url = trim($url, "\"' ");
            if ($url === '' || !preg_match('/^https?:\/\//', $url)) {
                return;
            }

            $type = (string) Db::table('eb_system_config')
                ->where('menu_name', 'monitor_webhook_type')
                ->value('value');
            $type = trim($type) !== '' ? trim($type) : 'wecom';

            $text = sprintf(
                "【明德商会监控告警】%s\nDB: %s · Cron: %s · 近24h错误: %s",
                date('Y-m-d H:i:s'),
                $payload['db']['ok'] ? '正常' : '异常',
                $payload['cron']['healthy'] ? '正常' : '失联',
                $payload['errors_last_24h']
            );

            $body = json_encode(
                ['msgtype' => 'text', 'text' => ['content' => $text]],
                JSON_UNESCAPED_UNICODE
            );

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_TIMEOUT => 5,
                CURLOPT_RETURNTRANSFER => true,
            ]);
            curl_exec($ch);
            curl_close($ch);
        } catch (\Throwable $e) {
            (new ChamberLogger())->error('monitor.webhook.failed', ['err' => $e->getMessage()]);
        }
    }

    private function dbPing(): bool
    {
        try {
            $value = Db::query('SELECT 1 AS ok');
            return is_array($value) && count($value) > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** @return array{healthy:bool,last_run:int|null,age_seconds:int|null,threshold:int} */
    private function cronHealth(): array
    {
        $lastRun = null;
        if (is_file(self::HEARTBEAT_FILE)) {
            $raw = @file_get_contents(self::HEARTBEAT_FILE);
            $decoded = json_decode((string) $raw, true);
            if (is_array($decoded) && isset($decoded['last_run'])) {
                $lastRun = (int) $decoded['last_run'];
            }
        }

        $age = $lastRun !== null ? max(0, time() - $lastRun) : null;

        return [
            'healthy' => $age !== null && $age <= self::CRON_STALE_SECONDS,
            'last_run' => $lastRun,
            'age_seconds' => $age,
            'threshold' => self::CRON_STALE_SECONDS,
        ];
    }

    private function recentErrorCount(): int
    {
        try {
            $since = time() - 86400;
            return (int) Db::table('ch_client_error')
                ->where('add_time', '>=', $since)
                ->count();
        } catch (\Throwable $e) {
            return -1;
        }
    }
}
