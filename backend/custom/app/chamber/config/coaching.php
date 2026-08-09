<?php

declare(strict_types=1);

use think\facade\Env;

$read = function (string $name, $default = null) {
    $value = getenv($name);
    if ($value !== false) {
        return $value;
    }

    return Env::get(strtolower(str_replace('_', '.', $name)), $default);
};

return [
    // Kaypal 模型网关（OpenAI 兼容）
    // 安全要求：origin/凭据一律从环境变量注入，无默认值（未配置时网关 fail-closed 直接拒绝请求，
    // 防止生产误连测试网关或用硬编码凭据）。本地/测试/生产必须显式配置：
    //   CHAMBER_KAYPAL_ORIGIN / CHAMBER_KAYPAL_APP_CREDENTIAL / CHAMBER_KAYPAL_CONTEXT_JWT_SECRET
    'kaypal_origin' => $read('CHAMBER_KAYPAL_ORIGIN', ''),
    'kaypal_app_credential' => $read('CHAMBER_KAYPAL_APP_CREDENTIAL', ''),
    'kaypal_context_jwt_secret' => $read('CHAMBER_KAYPAL_CONTEXT_JWT_SECRET', ''),
    'kaypal_app_id' => $read('CHAMBER_KAYPAL_APP_ID', 'octop'),
    'kaypal_tenant_id' => $read('CHAMBER_KAYPAL_TENANT_ID', 'tenant-highest-enterprise-18230326666'),
    'kaypal_sub' => $read('CHAMBER_KAYPAL_SUB', 'cmo9p6i5x000a58uckbcyv45u'),
    'kaypal_context_iss' => $read('CHAMBER_KAYPAL_CONTEXT_ISS', 'kaypal-ai-platform'),
    'kaypal_context_aud' => $read('CHAMBER_KAYPAL_CONTEXT_AUD', 'kaypal-api-v1'),
    'kaypal_context_ttl' => (int) $read('CHAMBER_KAYPAL_CONTEXT_TTL', 55),
    'kaypal_model' => $read('CHAMBER_KAYPAL_MODEL', 'kaypal-fast'),
    'kaypal_timeout' => (int) $read('CHAMBER_KAYPAL_TIMEOUT', 30),
    'kaypal_ssl_verify' => !in_array(strtolower(trim((string) $read('CHAMBER_KAYPAL_SSL_VERIFY', 'true'))), ['false', '0', 'off', 'no'], true),

    // 小薇默认品牌（运营后台可覆盖 ch_discern_config）
    'default_brand_name' => '小薇',
    'default_push_time' => '09:00',
    'default_evening_time' => '21:00',
    'default_streak_threshold' => 3,

    // 成本防护：每位会员每天 AI 生成次数上限（morning+evening 合计，含 force 重新生成）。
    // 通过 Redis 原子计数实现，防止 force=true 被循环调用刷爆付费网关。默认 10 次/天/会员。
    'daily_generation_limit' => (int) $read('CHAMBER_COACHING_DAILY_LIMIT', 10),
];
