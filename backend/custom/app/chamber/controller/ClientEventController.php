<?php

declare(strict_types=1);

namespace app\chamber\controller;

use app\Request;
use app\chamber\tenancy\TenantContext;
use think\facade\Db;
use think\Response;

/**
 * 前端行为埋点（P0 数据可观测）
 * 小程序关键行为（onboard/task/chat/gate/share）上报，写入 JSONL 文件 + 轻量落库。
 * 目的：让"留存率/转化率是否提升"变成可回答的问题，而非靠假设。
 *
 * body 支持两种：
 *   {event, page?, data?}                单事件
 *   {events:[{event,page,data}, ...]}    批量
 */
final class ClientEventController
{
    public function store(Request $request, TenantContext $tenant): Response
    {
        $body = $request->post();

        // 解析事件列表（兼容单事件/批量）
        $list = [];
        if (isset($body['events']) && is_array($body['events'])) {
            foreach ($body['events'] as $ev) {
                if (is_array($ev) && !empty($ev['event'])) {
                    $list[] = $ev;
                }
            }
        } elseif (!empty($body['event'])) {
            $list[] = $body;
        }

        if (!$list) {
            return Response::create(['code' => 1, 'msg' => 'event required', 'data' => null], 'json', 422);
        }

        $tenantId = $tenant->tenantId();
        $ip = (string) $request->ip();
        // uid 由前端传入（埋点非敏感操作，未登录也能上报，uid=0 表示匿名）
        $uid = (int) ($body['uid'] ?? 0);

        $now = time();
        $lines = [];
        $rows = [];
        foreach ($list as $ev) {
            $event = (string) ($ev['event'] ?? '');
            if ($event === '') {
                continue;
            }
            $page = (string) ($ev['page'] ?? '');
            $data = isset($ev['data']) && is_array($ev['data']) ? $ev['data'] : [];
            $lines[] = date('Y-m-d H:i:s') . " [client.event] tenant={$tenantId} uid={$uid} ip={$ip} event={$event} page=" . mb_substr($page, 0, 120) . ' data=' . json_encode($data, JSON_UNESCAPED_UNICODE);
            $rows[] = [
                'tenant_id' => $tenantId,
                'uid' => $uid,
                'event' => mb_substr($event, 0, 64),
                'page' => mb_substr($page, 0, 120),
                'data' => json_encode($data, JSON_UNESCAPED_UNICODE),
                'add_time' => $now,
            ];
        }

        // 写 JSONL 文件（永久留存，供离线分析）
        @file_put_contents(
            '/tmp/client_events.log',
            implode(PHP_EOL, $lines) . PHP_EOL,
            FILE_APPEND
        );

        // 落库（供后续后台看板/漏斗查询）
        try {
            foreach ($rows as $r) {
                Db::table('ch_client_event')->insert($r);
            }
        } catch (\Throwable $e) {
            // 表不存在或写入失败不阻断埋点（文件已落）
        }

        return Response::create(['code' => 0, 'msg' => 'ok', 'data' => ['count' => count($rows)]], 'json', 200);
    }
}
