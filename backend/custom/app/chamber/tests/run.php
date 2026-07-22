<?php

use app\chamber\exceptions\TenantAccessException;
use app\chamber\exceptions\TenantResolutionException;
use app\chamber\services\ArrayTenantDirectory;
use app\chamber\services\DisabledSignedTenantRequestVerifier;
use app\chamber\services\HmacTenantRequestVerifier;
use app\chamber\services\InMemoryReplayGuard;
use app\chamber\services\RedisReplayGuard;
use app\chamber\services\RequestTraceId;
use app\chamber\services\TenantContextResolver;
use app\chamber\services\TenantRuntimeConfig;
use app\chamber\services\ThinkDbTenantDirectory;
use app\chamber\tenancy\TenantResolutionInput;

spl_autoload_register(function (string $class): void {
    $prefix = 'app\\chamber\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    require dirname(__DIR__) . '/' . str_replace('\\', '/', $relative) . '.php';
});

$now = 1784625600;
$secret = 'tenant-test-secret-32-bytes-minimum-value';
$records = [
    [
        'tenant_id' => 1,
        'tenant_slug' => 'shenyang',
        'channel_id' => 11,
        'channel_slug' => 'wechat',
        'hosts' => ['sy.example.test'],
        'active' => true,
    ],
    [
        'tenant_id' => 2,
        'tenant_slug' => 'jiuzhang',
        'channel_id' => 21,
        'channel_slug' => 'miniapp',
        'hosts' => ['jz.example.test'],
        'active' => true,
    ],
    [
        'tenant_id' => 3,
        'tenant_slug' => 'disabled',
        'channel_id' => 31,
        'channel_slug' => 'web',
        'hosts' => ['disabled.example.test'],
        'active' => false,
    ],
];

$tests = [];

$tests['host mapping resolves a trusted context'] = function () use ($records, $secret, $now): void {
    list($resolver) = makeResolver($records, $secret, $now);
    $context = $resolver->resolve(new TenantResolutionInput('GET', 'sy.example.test:443', '/api/home', []));
    assertSame(1, $context->tenantId());
    assertSame(11, $context->channelId());
    assertSame('host', $context->source());
};

$tests['signed channel resolves without a mapped host'] = function () use ($records, $secret, $now): void {
    list($resolver, $verifier) = makeResolver($records, $secret, $now);
    $input = signedInput($verifier, $now, 'nonce-signed-valid-0001', 'unknown.test', 'jiuzhang', 'miniapp');
    $context = $resolver->resolve($input);
    assertSame(2, $context->tenantId());
    assertSame('signed_channel', $context->source());
};

$tests['raw tenant id is never trusted'] = function () use ($records, $secret, $now): void {
    list($resolver) = makeResolver($records, $secret, $now);
    expectReason(TenantResolutionException::MISSING, function () use ($resolver): void {
        $resolver->resolve(new TenantResolutionInput('GET', 'unknown.test', '/api/home', [
            'x-tenant-id' => '2',
        ]));
    });
};

$tests['bad signature is rejected'] = function () use ($records, $secret, $now): void {
    list($resolver, $verifier) = makeResolver($records, $secret, $now);
    $input = signedInput($verifier, $now, 'nonce-bad-signature-001', 'unknown.test', 'jiuzhang', 'miniapp', str_repeat('0', 64));
    expectReason(TenantResolutionException::BAD_SIGNATURE, function () use ($resolver, $input): void {
        $resolver->resolve($input);
    });
};

$tests['incomplete signed context is rejected'] = function () use ($records, $secret, $now): void {
    list($resolver) = makeResolver($records, $secret, $now);
    expectReason(TenantResolutionException::INCOMPLETE_SIGNATURE, function () use ($resolver, $now): void {
        $resolver->resolve(new TenantResolutionInput('GET', 'unknown.test', '/api/bootstrap', [
            TenantResolutionInput::HEADER_TENANT => 'jiuzhang',
            TenantResolutionInput::HEADER_TIMESTAMP => (string) $now,
        ]));
    });
};

$tests['stale signed context is rejected'] = function () use ($records, $secret, $now): void {
    list($resolver, $verifier) = makeResolver($records, $secret, $now);
    $input = signedInput($verifier, $now - 301, 'nonce-stale-check-00001', 'unknown.test', 'jiuzhang', 'miniapp');
    expectReason(TenantResolutionException::STALE_SIGNATURE, function () use ($resolver, $input): void {
        $resolver->resolve($input);
    });
};

$tests['signed request replay is rejected'] = function () use ($records, $secret, $now): void {
    list($resolver, $verifier) = makeResolver($records, $secret, $now);
    $input = signedInput($verifier, $now, 'nonce-replay-check-0001', 'unknown.test', 'jiuzhang', 'miniapp');
    $resolver->resolve($input);
    expectReason(TenantResolutionException::REPLAYED, function () use ($resolver, $input): void {
        $resolver->resolve($input);
    });
};

$tests['host and signature conflict is rejected'] = function () use ($records, $secret, $now): void {
    list($resolver, $verifier) = makeResolver($records, $secret, $now);
    $input = signedInput($verifier, $now, 'nonce-conflict-check-001', 'sy.example.test', 'jiuzhang', 'miniapp');
    expectReason(TenantResolutionException::CONFLICT, function () use ($resolver, $input): void {
        $resolver->resolve($input);
    });
};

$tests['inactive tenant is rejected'] = function () use ($records, $secret, $now): void {
    list($resolver) = makeResolver($records, $secret, $now);
    expectReason(TenantResolutionException::INACTIVE, function () use ($resolver): void {
        $resolver->resolve(new TenantResolutionInput('GET', 'disabled.example.test', '/', []));
    });
};

$tests['optional route may proceed without tenant context'] = function () use ($records, $secret, $now): void {
    list($resolver) = makeResolver($records, $secret, $now);
    $context = $resolver->resolve(new TenantResolutionInput('GET', 'unknown.test', '/health', []), false);
    assertSame(null, $context);
};

$tests['context blocks cross-tenant resources'] = function () use ($records, $secret, $now): void {
    list($resolver) = makeResolver($records, $secret, $now);
    $context = $resolver->resolve(new TenantResolutionInput('GET', 'sy.example.test', '/', []));
    expectException(TenantAccessException::class, function () use ($context): void {
        $context->assertTenant(2);
    });
};

$tests['runtime config parses explicit production host mappings'] = function (): void {
    $config = new TenantRuntimeConfig([
        'environment' => 'production',
        'app_debug' => false,
        'host_map_json' => json_encode([
            'portal.example.test' => [
                'tenant_slug' => 'generic-tenant',
                'channel_code' => 'portal_v1',
            ],
        ]),
    ]);
    $mappings = $config->hostMappings();
    assertSame('generic-tenant', $mappings['portal.example.test']['tenant_slug']);
    assertSame('portal_v1', $mappings['portal.example.test']['channel_slug']);
};

$tests['localhost mapping requires an explicit development runtime'] = function (): void {
    $config = new TenantRuntimeConfig([
        'environment' => 'production',
        'app_debug' => true,
        'dev_localhost_enabled' => true,
        'dev_tenant_slug' => 'local-primary',
        'dev_channel_code' => 'default',
    ]);
    expectException(InvalidArgumentException::class, function () use ($config): void {
        $config->hostMappings();
    });
};

$tests['development runtime adds only explicit loopback mappings'] = function (): void {
    $config = new TenantRuntimeConfig([
        'environment' => 'local',
        'app_debug' => 'true',
        'dev_localhost_enabled' => 'true',
        'dev_tenant_slug' => 'local-primary',
        'dev_channel_code' => 'default',
    ]);
    $mappings = $config->hostMappings();
    assertSame('local-primary', $mappings['localhost']['tenant_slug']);
    assertSame('default', $mappings['127.0.0.1']['channel_slug']);
    assertSame('local-primary', $mappings['::1']['tenant_slug']);
};

$tests['CORS policy accepts only configured exact origins'] = function (): void {
    $config = new TenantRuntimeConfig([
        'cors_allowed_origins' => 'HTTP://LOCALHOST:5173, https://portal.example.test',
        'cors_allow_credentials' => 'true',
    ]);
    assertSame(['http://localhost:5173', 'https://portal.example.test'], $config->corsAllowedOrigins());
    assertSame(true, $config->allowsCorsOrigin('http://localhost:5173'));
    assertSame(false, $config->allowsCorsOrigin('http://localhost:5174'));
    assertSame(false, $config->allowsCorsOrigin('https://evil.example'));
    assertSame(true, $config->corsAllowsCredentials());
};

$tests['CORS policy rejects wildcards and origins with paths'] = function (): void {
    foreach (['*', 'https://portal.example.test/path'] as $invalid) {
        $config = new TenantRuntimeConfig(['cors_allowed_origins' => $invalid]);
        expectException(InvalidArgumentException::class, function () use ($config): void {
            $config->corsAllowedOrigins();
        });
    }
};

$tests['request host normalization keeps IPv6 loopback and strips its port'] = function (): void {
    $input = new TenantResolutionInput('GET', '[::1]:8011', '/chamber/health', []);
    assertSame('::1', $input->host());
};

$tests['ThinkPHP directory maps migration columns into context records'] = function (): void {
    $lookup = function (string $tenantSlug, string $channelCode): array {
        assertSame('generic-tenant', $tenantSlug);
        assertSame('portal_v1', $channelCode);

        return [
            'tenant_id' => 41,
            'tenant_slug' => $tenantSlug,
            'tenant_status' => 1,
            'channel_id' => 73,
            'channel_slug' => $channelCode,
            'channel_status' => 1,
        ];
    };
    $directory = new ThinkDbTenantDirectory([
        'portal.example.test' => [
            'tenant_slug' => 'generic-tenant',
            'channel_slug' => 'portal_v1',
        ],
    ], $lookup);
    $record = $directory->findByHost('PORTAL.EXAMPLE.TEST:443');
    assertSame(41, $record->tenantId());
    assertSame(73, $record->channelId());
    assertSame(true, $record->isActive());
};

$tests['Redis replay guard claims a nonce only once'] = function () use ($now): void {
    $handler = new FakeRedisHandler();
    $clock = function () use ($now): int {
        return $now;
    };
    $guard = new RedisReplayGuard('test:tenant:nonce:', $clock, function () use ($handler) {
        return $handler;
    });
    assertSame(true, $guard->claim('nonce-redis-atomic-0001', $now + 60));
    assertSame(false, $guard->claim('nonce-redis-atomic-0001', $now + 60));
};

$tests['Redis replay guard failure returns a stable 503 reason'] = function () use ($now): void {
    $guard = new RedisReplayGuard('test:tenant:nonce:', function () use ($now): int {
        return $now;
    }, function () {
        return new stdClass();
    });

    try {
        $guard->claim('nonce-redis-failure-0001', $now + 60);
    } catch (TenantResolutionException $exception) {
        assertSame(TenantResolutionException::REPLAY_GUARD_UNAVAILABLE, $exception->reason());
        assertSame(503, $exception->httpStatus());
        return;
    }

    throw new RuntimeException('Expected replay guard failure to return a stable TenantResolutionException');
};

$tests['signed entry fails closed when no secret is configured'] = function (): void {
    $verifier = new DisabledSignedTenantRequestVerifier();
    expectReason(TenantResolutionException::SIGNING_UNAVAILABLE, function () use ($verifier): void {
        $verifier->assertValid(new TenantResolutionInput('GET', 'unknown.test', '/chamber/v1/bootstrap', []));
    });
};

$tests['valid request trace ID is passed through unchanged'] = function (): void {
    $trace = new RequestTraceId(function (): string {
        return 'generated-request-id-0001';
    });
    assertSame('client-request-id-1234', $trace->resolve(['client-request-id-1234']));
};

$tests['invalid request trace ID is replaced'] = function (): void {
    $trace = new RequestTraceId(function (): string {
        return 'generated-request-id-0001';
    });
    assertSame('generated-request-id-0001', $trace->resolve(['bad id', '../unsafe']));
};

$tests['request and correlation IDs remain independent when both are valid'] = function (): void {
    $trace = new RequestTraceId(function (): string {
        return 'generated-request-id-0001';
    });
    $resolved = $trace->resolvePair('client-request-id-1234', 'client-correlation-id-5678');
    assertSame('client-request-id-1234', $resolved['request_id']);
    assertSame('client-correlation-id-5678', $resolved['correlation_id']);
};

$tests['invalid request ID is regenerated without discarding valid correlation ID'] = function (): void {
    $trace = new RequestTraceId(function (): string {
        return 'generated-request-id-0001';
    });
    $resolved = $trace->resolvePair('bad id', 'client-correlation-id-5678');
    assertSame('generated-request-id-0001', $resolved['request_id']);
    assertSame('client-correlation-id-5678', $resolved['correlation_id']);
};

$failures = 0;
foreach ($tests as $name => $test) {
    try {
        $test();
        fwrite(STDOUT, "PASS {$name}\n");
    } catch (Throwable $exception) {
        $failures++;
        fwrite(STDERR, "FAIL {$name}: {$exception->getMessage()}\n");
    }
}

fwrite(STDOUT, sprintf("%d tests, %d failures\n", count($tests), $failures));
exit($failures === 0 ? 0 : 1);

function makeResolver(array $records, string $secret, int $now): array
{
    $clock = function () use ($now): int {
        return $now;
    };
    $guard = new InMemoryReplayGuard($clock);
    $verifier = new HmacTenantRequestVerifier($secret, $guard, 300, $clock);

    return [new TenantContextResolver(new ArrayTenantDirectory($records), $verifier), $verifier];
}

function signedInput(
    HmacTenantRequestVerifier $verifier,
    int $timestamp,
    string $nonce,
    string $host,
    string $tenantSlug,
    string $channelSlug,
    string $signature = ''
): TenantResolutionInput {
    $headers = [
        TenantResolutionInput::HEADER_TENANT => $tenantSlug,
        TenantResolutionInput::HEADER_CHANNEL => $channelSlug,
        TenantResolutionInput::HEADER_TIMESTAMP => (string) $timestamp,
        TenantResolutionInput::HEADER_NONCE => $nonce,
        TenantResolutionInput::HEADER_SIGNATURE => str_repeat('0', 64),
    ];
    $unsigned = new TenantResolutionInput('GET', $host, '/api/bootstrap', $headers);
    $headers[TenantResolutionInput::HEADER_SIGNATURE] = $signature ?: $verifier->signatureFor($unsigned);

    return new TenantResolutionInput('GET', $host, '/api/bootstrap', $headers);
}

function assertSame($expected, $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf(
            'Expected %s, got %s',
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
}

function expectReason(string $reason, callable $callback): void
{
    try {
        $callback();
    } catch (TenantResolutionException $exception) {
        assertSame($reason, $exception->reason());
        return;
    }

    throw new RuntimeException(sprintf('Expected TenantResolutionException reason %s', $reason));
}

function expectException(string $class, callable $callback): void
{
    try {
        $callback();
    } catch (Throwable $exception) {
        if ($exception instanceof $class) {
            return;
        }
        throw $exception;
    }

    throw new RuntimeException(sprintf('Expected exception %s', $class));
}

final class FakeRedisHandler
{
    /** @var array */
    private $keys = [];

    public function eval(string $script, array $arguments, int $numberOfKeys): int
    {
        if ($numberOfKeys !== 1 || count($arguments) !== 2) {
            throw new RuntimeException('Unexpected Redis EVAL arguments');
        }

        $key = $arguments[0];
        if (isset($this->keys[$key])) {
            return 0;
        }

        $this->keys[$key] = (int) $arguments[1];

        return 1;
    }
}
