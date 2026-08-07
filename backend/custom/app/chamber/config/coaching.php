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
    'kaypal_origin' => $read('CHAMBER_KAYPAL_ORIGIN', 'https://test.kaypal.cn'),
    'kaypal_app_credential' => $read('CHAMBER_KAYPAL_APP_CREDENTIAL', 'octop_517e546f3cb437a891ff7c540a41ba7f928efa2a49404d19'),
    'kaypal_context_jwt_secret' => $read('CHAMBER_KAYPAL_CONTEXT_JWT_SECRET', '78ad64a71c0c51fb367247bfb71c62f5d288028e2b5f09540c03568e61d977ae86bec6b2c592df898ae7cb019c86fca7'),
    'kaypal_app_id' => $read('CHAMBER_KAYPAL_APP_ID', 'octop'),
    'kaypal_tenant_id' => $read('CHAMBER_KAYPAL_TENANT_ID', 'tenant-highest-enterprise-18230326666'),
    'kaypal_sub' => $read('CHAMBER_KAYPAL_SUB', 'cmo9p6i5x000a58uckbcyv45u'),
    'kaypal_context_iss' => $read('CHAMBER_KAYPAL_CONTEXT_ISS', 'kaypal-ai-platform'),
    'kaypal_context_aud' => $read('CHAMBER_KAYPAL_CONTEXT_AUD', 'kaypal-api-v1'),
    'kaypal_context_ttl' => (int) $read('CHAMBER_KAYPAL_CONTEXT_TTL', 55),
    'kaypal_model' => $read('CHAMBER_KAYPAL_MODEL', 'kaypal-fast'),
    'kaypal_timeout' => (int) $read('CHAMBER_KAYPAL_TIMEOUT', 30),

    // 小薇默认品牌（运营后台可覆盖 ch_discern_config）
    'default_brand_name' => '小薇',
    'default_push_time' => '09:00',
    'default_evening_time' => '21:00',
    'default_streak_threshold' => 3,
];
