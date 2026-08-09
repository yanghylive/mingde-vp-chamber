<?php

declare(strict_types=1);

namespace app\chamber\services;

use think\facade\Db;
use think\facade\Log;
use Throwable;

/**
 * AI 调用 usage 计费流水记录（服务端权威）。
 *
 * 背景：ai-service 为空骨架，AI 调用由 PHP KaypalGateway 直连网关。
 * 本服务在每次网关调用后，把网关响应的 usage（prompt/completion/total tokens）
 * 落库 ch_chamber_ai_usage，形成服务端权威的计费/成本分析依据（不信任客户端上报）。
 *
 * 设计：
 *  - 每次生成一条事实流水（不做幂等——生成路径已被每日配额限流，流水是事后对账数据）
 *  - 记录失败不影响主流程（try-catch + 告警日志，计费记录缺失可事后补对账）
 *  - 金额不在此计算：单价/套餐配置可能变化，只存 token 数，费用由对账侧按当时单价核算
 */
final class AiUsageRecorder
{
    /**
     * @param array $ctx 上下文：tenant_id/channel_id/member_id/uid
     * @param string $scene 场景：morning|evening
     * @param array $usage 网关响应的 usage：prompt_tokens/completion_tokens/total_tokens
     * @param string $model 模型名
     * @param bool $fallbackUsed 是否走了兜底模板
     * @param int $latencyMs 网关调用耗时（毫秒）
     * @param string $requestId 网关上下文 request_id（对账关联）
     */
    public function record(
        array $ctx,
        string $scene,
        array $usage,
        string $model,
        bool $fallbackUsed,
        int $latencyMs,
        string $requestId = ''
    ): void {
        try {
            Db::table('ch_chamber_ai_usage')->insert([
                'tenant_id' => (int) ($ctx['tenant_id'] ?? 0),
                'channel_id' => (int) ($ctx['channel_id'] ?? 0),
                'member_id' => (int) ($ctx['member_id'] ?? 0),
                'uid' => (int) ($ctx['uid'] ?? 0),
                'scene' => $scene,
                'model' => $model,
                'prompt_tokens' => (int) ($usage['prompt_tokens'] ?? 0),
                'completion_tokens' => (int) ($usage['completion_tokens'] ?? 0),
                'total_tokens' => (int) ($usage['total_tokens'] ?? 0),
                'fallback_used' => $fallbackUsed ? 1 : 0,
                'latency_ms' => max(0, $latencyMs),
                'request_id' => $requestId === '' ? '' : substr($requestId, 0, 64),
                'add_time' => time(),
            ]);
        } catch (Throwable $exception) {
            Log::warning('ai usage record failed (non-blocking)', [
                'scene' => $scene,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
