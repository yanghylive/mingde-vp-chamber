<?php

use think\facade\Env;

$read = function (string $name, $default = null) {
    $value = getenv($name);
    if ($value !== false) {
        return $value;
    }

    return Env::get(strtolower(str_replace('_', '.', $name)), $default);
};

return [
    'environment' => $read('CHAMBER_ENV', 'production'),
    'app_debug' => $read('APP_DEBUG', false),
    'signing_secret' => $read('CHAMBER_TENANT_SIGNING_SECRET', ''),
    'signature_ttl' => $read('CHAMBER_TENANT_SIGNATURE_TTL', 300),
    'replay_prefix' => $read('CHAMBER_TENANT_REPLAY_PREFIX', 'chamber:tenant:nonce:'),
    'host_map_json' => $read('CHAMBER_HOST_MAP_JSON', ''),
    'cors_allowed_origins' => $read('CHAMBER_CORS_ALLOWED_ORIGINS', ''),
    'cors_allow_credentials' => $read('CHAMBER_CORS_ALLOW_CREDENTIALS', true),
    'dev_localhost_enabled' => $read('CHAMBER_DEV_LOCALHOST_ENABLED', false),
    'dev_tenant_slug' => $read('CHAMBER_DEV_TENANT_SLUG', ''),
    'dev_channel_code' => $read('CHAMBER_DEV_CHANNEL_CODE', ''),
];
