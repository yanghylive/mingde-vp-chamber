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

    /** 告警冷却期（秒）：同一 (component, error_code) 冷却期内不重复告警 */
    private const ALERT_COOLDOWN_SECONDS = 3600;

    /** webhook 域名 allowlist（防 SSRF）：空数组表示不限制 */
    private const WEBHOOK_ALLOWED_HOSTS = [
        'qyapi.weixin.qq.com',       // 企业微信
        'oapi.dingtalk.com',         // 钉钉
        'open.feishu.cn',            // 飞书
    ];

    public function health(): Response
    {
        $dbOk = $this->dbPing();
        $cron = $this->cronHealth();

        $healthy = $dbOk && $cron['healthy'];

        $payload = [
            'service' => 'chamber',
            'api_version' => 'v1',
            'time' => time(),
            'overall' => $healthy ? 'ok' : 'degraded',
            'db' => ['ok' => $dbOk],
            'cron' => $cron,
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
            // 冷却去重：同一 (component, error_code) 冷却期内不重复告警
            $now = time();
            if (!$this->shouldFire('health', 'degraded', $now)) {
                return;
            }

            $url = (string) Db::table('eb_system_config')
                ->where('menu_name', 'monitor_webhook_url')
                ->value('value');
            $url = trim($url, "\"' ");
            if ($url === '' || !$this->urlAllowed($url)) {
                return;
            }

            $type = (string) Db::table('eb_system_config')
                ->where('menu_name', 'monitor_webhook_type')
                ->value('value');
            $type = trim($type) !== '' ? trim($type) : 'wecom';

            $text = sprintf(
                "【明德商会监控告警】%s\nDB: %s · Cron: %s",
                date('Y-m-d H:i:s'),
                $payload['db']['ok'] ? '正常' : '异常',
                $payload['cron']['healthy'] ? '正常' : '失联'
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
            $raw = curl_exec($ch);
            $curlErrno = curl_errno($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);

            // 必须确认发送成功（curl 无错误且 HTTP 2xx）才记录冷却——
            // 否则失败也进冷却期，下一周期不再告警，告警被静默吞掉。
            if ($curlErrno !== 0 || $httpCode < 200 || $httpCode >= 300) {
                throw new RuntimeException(sprintf(
                    'webhook delivery failed (http %d, curl %d): %s',
                    $httpCode,
                    $curlErrno,
                    mb_substr((string) $raw, 0, 200)
                ));
            }

            // 发送成功后记录冷却
            $this->markFired('health', 'degraded', $now);
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

    /** 告警冷却：同一 (component, error_code) 冷却期内不重复 */
    private function shouldFire(string $component, string $errorCode, int $now): bool
    {
        try {
            $row = Db::table('ch_alert_state')
                ->where('tenant_id', 0)
                ->where('component', $component)
                ->where('error_code', $errorCode)
                ->find();
            if (is_array($row) && (int) $row['last_fired_at'] + self::ALERT_COOLDOWN_SECONDS > $now) {
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            return true;
        }
    }

    private function markFired(string $component, string $errorCode, int $now): void
    {
        try {
            $exists = Db::table('ch_alert_state')
                ->where('tenant_id', 0)
                ->where('component', $component)
                ->where('error_code', $errorCode)
                ->find();
            if (is_array($exists)) {
                Db::table('ch_alert_state')
                    ->where('id', (int) $exists['id'])
                    ->update(['last_fired_at' => $now]);
            } else {
                Db::table('ch_alert_state')->insert([
                    'tenant_id' => 0,
                    'component' => $component,
                    'error_code' => $errorCode,
                    'last_fired_at' => $now,
                ]);
            }
        } catch (\Throwable $e) {
            // 冷却记录失败不阻塞告警
        }
    }

    /** webhook URL 域名 allowlist（防 SSRF 打到内网） */
    private function urlAllowed(string $url): bool
    {
        if (!preg_match('/^https?:\/\//', $url)) {
            return false;
        }
        $host = (string) parse_url($url, PHP_URL_HOST);
        if ($host === '') {
            return false;
        }
        if (!self::WEBHOOK_ALLOWED_HOSTS) {
            return true;
        }
        foreach (self::WEBHOOK_ALLOWED_HOSTS as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.' . $allowed)) {
                return true;
            }
        }

        return false;
    }
}
