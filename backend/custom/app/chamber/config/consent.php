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

$environment = strtolower(trim((string) $read('CHAMBER_ENV', 'production')));
$isLocal = in_array($environment, ['dev', 'development', 'local', 'test', 'testing'], true);
$localTenantSlug = trim((string) $read('CHAMBER_DEV_TENANT_SLUG', 'local-primary'));
$documentsJson = $read('CHAMBER_CONSENT_DOCUMENTS_JSON', '');
$usesLocalFixture = $isLocal && is_string($documentsJson) && trim($documentsJson) === '';

return [
    'environment' => $environment,
    'documents_json' => $documentsJson,
    'privacy_digest_salt' => $read(
        'CHAMBER_PRIVACY_DIGEST_SALT',
        $isLocal ? 'local-fixture-only-privacy-digest-salt-2026-07-23' : ''
    ),
    'local_fixture' => $usesLocalFixture ? [
        'source' => 'local_fixture',
        'tenant_slug' => $localTenantSlug,
        'document_code' => 'privacy_policy',
        'document_version' => 'local-2026-07-23',
        'content' => 'LOCAL FIXTURE ONLY: privacy policy placeholder for local development.',
    ] : null,
];
