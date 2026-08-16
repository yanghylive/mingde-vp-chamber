<?php

declare(strict_types=1);

namespace app\chamber\services;

/**
 * 结构化 JSON 日志（监控骨架 B3）
 * - 写入 /tmp/chamber_monitor.log（JSON Lines，一事件一行）
 * - 本环境 think Log 与 error_log() 均不落盘，故统一用 file_put_contents
 * - 字段：time / level / msg / context
 */
final class ChamberLogger
{
    private const LOG_FILE = '/tmp/chamber_monitor.log';

    public function info(string $message, array $context = []): void
    {
        $this->write('info', $message, $context);
    }

    public function warn(string $message, array $context = []): void
    {
        $this->write('warn', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->write('error', $message, $context);
    }

    private function write(string $level, string $message, array $context): void
    {
        $entry = [
            'time' => date('Y-m-d H:i:s'),
            'level' => $level,
            'msg' => $message,
            'context' => (object) $context,
        ];
        $line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($line === false) {
            $line = json_encode(['time' => date('Y-m-d H:i:s'), 'level' => $level, 'msg' => 'log_encode_failed']);
        }
        @file_put_contents(self::LOG_FILE, $line . "\n", FILE_APPEND | LOCK_EX);
    }
}
