<?php

declare(strict_types=1);

use app\chamber\exceptions\MemberTransactionException;
use app\chamber\membership\BootstrapIdempotency;
use app\chamber\membership\ConsentDocument;
use app\chamber\membership\MemberBootstrapRequest;
use app\chamber\services\ConsentDocumentRegistry;

spl_autoload_register(function (string $class): void {
    $prefix = 'app\\chamber\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    require dirname(__DIR__) . '/' . str_replace('\\', '/', $relative) . '.php';
});

$tests = [];

$tests['empty bootstrap request has one stable canonical shape'] = function (): void {
    $request = MemberBootstrapRequest::fromArray([]);
    assertSame(null, $request->inviteCode());
    assertSame([], $request->consents());
    assertSame(['invite_code' => null, 'consents' => []], $request->toCanonicalArray());
};

$tests['bootstrap request validates and sorts consent entries'] = function (): void {
    $request = MemberBootstrapRequest::fromArray([
        'consents' => [
            ['document_code' => 'terms', 'document_version' => 'v2', 'accepted' => true],
            ['accepted' => true, 'document_version' => 'v1', 'document_code' => 'privacy_policy'],
        ],
        'invite_code' => 'ABcd1234',
    ]);
    assertSame('ABcd1234', $request->inviteCode());
    assertSame('privacy_policy', $request->consents()[0]['document_code']);
    assertSame('terms', $request->consents()[1]['document_code']);
};

$tests['bootstrap request rejects unknown top-level fields'] = function (): void {
    expectException(InvalidArgumentException::class, function (): void {
        MemberBootstrapRequest::fromArray(['tenant_id' => 1]);
    });
};

$tests['bootstrap request rejects weak invite code values'] = function (): void {
    foreach ([12345678, true, null, ['ABCDEFGH']] as $invalid) {
        expectException(InvalidArgumentException::class, function () use ($invalid): void {
            MemberBootstrapRequest::fromArray(['invite_code' => $invalid]);
        });
    }
};

$tests['bootstrap request rejects malformed invite codes'] = function (): void {
    foreach (['short', 'contains-hyphen', '12345678901234567', ' ABCDEFGH', "ABCDEFGH\n"] as $invalid) {
        expectException(InvalidArgumentException::class, function () use ($invalid): void {
            MemberBootstrapRequest::fromArray(['invite_code' => $invalid]);
        });
    }
};

$tests['consents must be a proper JSON list'] = function (): void {
    foreach (['privacy_policy', ['code' => 'privacy_policy'], [1 => consent()]] as $invalid) {
        expectException(InvalidArgumentException::class, function () use ($invalid): void {
            MemberBootstrapRequest::fromArray(['consents' => $invalid]);
        });
    }
};

$tests['consent entries reject unknown and missing fields'] = function (): void {
    expectException(InvalidArgumentException::class, function (): void {
        MemberBootstrapRequest::fromArray(['consents' => [consent(['content_sha256' => str_repeat('a', 64)])]]);
    });
    expectException(InvalidArgumentException::class, function (): void {
        $value = consent();
        unset($value['document_version']);
        MemberBootstrapRequest::fromArray(['consents' => [$value]]);
    });
};

$tests['consent identifiers are strictly typed and validated'] = function (): void {
    foreach ([
        ['document_code' => 1],
        ['document_code' => 'bad/code'],
        ['document_version' => false],
        ['document_version' => 'bad version'],
        ['document_version' => str_repeat('v', 65)],
    ] as $change) {
        expectException(InvalidArgumentException::class, function () use ($change): void {
            MemberBootstrapRequest::fromArray(['consents' => [consent($change)]]);
        });
    }
};

$tests['consent acceptance must be the boolean true literal'] = function (): void {
    foreach ([false, 1, 'true', null] as $invalid) {
        expectException(InvalidArgumentException::class, function () use ($invalid): void {
            MemberBootstrapRequest::fromArray(['consents' => [consent(['accepted' => $invalid])]]);
        });
    }
};

$tests['each consent document code may appear only once'] = function (): void {
    expectException(InvalidArgumentException::class, function (): void {
        MemberBootstrapRequest::fromArray(['consents' => [
            consent(['document_version' => 'v1']),
            consent(['document_version' => 'v2']),
        ]]);
    });
};

$tests['bootstrap request accepts no more than ten consents'] = function (): void {
    $consents = [];
    for ($index = 0; $index < 11; $index++) {
        $consents[] = consent(['document_code' => 'policy_' . $index]);
    }
    expectException(InvalidArgumentException::class, function () use ($consents): void {
        MemberBootstrapRequest::fromArray(['consents' => $consents]);
    });
};

$tests['caller idempotency key follows the public contract exactly'] = function (): void {
    assertSame('Boot.req:12345678', BootstrapIdempotency::assertCallerKey('Boot.req:12345678'));
    foreach (['short', '-leading00', str_repeat('a', 129), 'contains space', "valid-key\n"] as $invalid) {
        expectException(InvalidArgumentException::class, function () use ($invalid): void {
            BootstrapIdempotency::assertCallerKey($invalid);
        });
    }
};

$tests['internal idempotency identity is stable and versioned'] = function (): void {
    $first = internalKey();
    $second = internalKey();
    assertSame($first, $second);
    assertSame(true, (bool) preg_match('/^sha256-v1:[a-f0-9]{64}$/', $first));
};

$tests['internal idempotency identity includes every trusted scope dimension'] = function (): void {
    $base = internalKey();
    $variants = [
        BootstrapIdempotency::deriveInternalKey(2, 'member.bootstrap', 'crmeb_user', 42, 'request-0001'),
        BootstrapIdempotency::deriveInternalKey(1, 'member.profile.patch', 'crmeb_user', 42, 'request-0001'),
        BootstrapIdempotency::deriveInternalKey(1, 'member.bootstrap', 'admin', 42, 'request-0001'),
        BootstrapIdempotency::deriveInternalKey(1, 'member.bootstrap', 'crmeb_user', 43, 'request-0001'),
        BootstrapIdempotency::deriveInternalKey(1, 'member.bootstrap', 'crmeb_user', 42, 'request-0002'),
    ];
    foreach ($variants as $variant) {
        assertNotSame($base, $variant);
    }
};

$tests['internal idempotency identity rejects invalid trusted scope'] = function (): void {
    expectException(InvalidArgumentException::class, function (): void {
        BootstrapIdempotency::deriveInternalKey(0, 'member.bootstrap', 'crmeb_user', 42, 'request-0001');
    });
    expectException(InvalidArgumentException::class, function (): void {
        BootstrapIdempotency::deriveInternalKey(1, 'bad operation', 'crmeb_user', 42, 'request-0001');
    });
    expectException(InvalidArgumentException::class, function (): void {
        BootstrapIdempotency::deriveInternalKey(1, 'member.bootstrap', 'crmeb_user', 0, 'request-0001');
    });
};

$tests['canonical JSON recursively sorts object keys and preserves list order'] = function (): void {
    $left = ['z' => 1, 'a' => ['y' => true, 'x' => [['b' => 2, 'a' => 1]]]];
    $right = ['a' => ['x' => [['a' => 1, 'b' => 2]], 'y' => true], 'z' => 1];
    assertSame(BootstrapIdempotency::canonicalJson($left), BootstrapIdempotency::canonicalJson($right));
    assertNotSame(
        BootstrapIdempotency::canonicalJson(['items' => ['a', 'b']]),
        BootstrapIdempotency::canonicalJson(['items' => ['b', 'a']])
    );
};

$tests['canonical JSON rejects values with ambiguous numeric encoding'] = function (): void {
    expectException(InvalidArgumentException::class, function (): void {
        BootstrapIdempotency::canonicalJson(['amount' => 1.0]);
    });
    expectException(InvalidArgumentException::class, function (): void {
        BootstrapIdempotency::canonicalJson(['object' => new stdClass()]);
    });
};

$tests['request hash is stable across source object ordering'] = function (): void {
    $left = MemberBootstrapRequest::fromArray([
        'invite_code' => 'ABCDEFGH',
        'consents' => [consent(['document_code' => 'terms']), consent()],
    ]);
    $right = MemberBootstrapRequest::fromArray([
        'consents' => [array_reverse(consent(), true), consent(['document_code' => 'terms'])],
        'invite_code' => 'ABCDEFGH',
    ]);
    assertSame(
        BootstrapIdempotency::requestHash(11, $left->toCanonicalArray()),
        BootstrapIdempotency::requestHash(11, $right->toCanonicalArray())
    );
};

$tests['request hash includes the trusted channel'] = function (): void {
    $request = MemberBootstrapRequest::fromArray([])->toCanonicalArray();
    assertNotSame(
        BootstrapIdempotency::requestHash(11, $request),
        BootstrapIdempotency::requestHash(12, $request)
    );
};

$tests['consent document hash is computed from exact server content'] = function (): void {
    $document = new ConsentDocument('privacy_policy', 'v1', "Privacy policy\n");
    assertSame('privacy_policy', $document->code());
    assertSame('v1', $document->version());
    assertSame(hash('sha256', "Privacy policy\n"), $document->contentHash());
    assertNotSame(hash('sha256', 'Privacy policy'), $document->contentHash());
};

$tests['registry resolves the exact current tenant document version'] = function (): void {
    $registry = registry();
    $document = $registry->resolve('local-primary', 'privacy_policy', 'v2');
    assertSame(hash('sha256', 'Current privacy policy'), $document->contentHash());
};

$tests['registry reports stable stale reason for old and unknown documents'] = function (): void {
    $registry = registry();
    foreach ([
        ['local-primary', 'privacy_policy', 'v1'],
        ['local-primary', 'unknown_policy', 'v1'],
        ['unknown-tenant', 'privacy_policy', 'v2'],
    ] as $lookup) {
        expectMemberReason('consent_document_stale', function () use ($registry, $lookup): void {
            $registry->resolve($lookup[0], $lookup[1], $lookup[2]);
        });
    }
};

$tests['registry document versions are tenant scoped'] = function (): void {
    $registry = registry([
        documentConfig('other-tenant', 'privacy_policy', 'v3', 'Other tenant policy'),
    ]);
    assertSame('v2', $registry->resolve('local-primary', 'privacy_policy', 'v2')->version());
    assertSame('v3', $registry->resolve('other-tenant', 'privacy_policy', 'v3')->version());
};

$tests['registry rejects malformed and duplicate service configuration'] = function (): void {
    foreach ([
        '{bad json',
        '{}',
        json_encode(['tenant_slug' => 'local-primary']),
        json_encode([documentConfig('UPPER', 'privacy_policy', 'v1', 'Policy')]),
        json_encode([
            documentConfig('local-primary', 'privacy_policy', 'v1', 'First'),
            documentConfig('local-primary', 'privacy_policy', 'v2', 'Second'),
        ]),
    ] as $json) {
        expectException(InvalidArgumentException::class, function () use ($json): void {
            new ConsentDocumentRegistry(['documents_json' => $json]);
        });
    }
};

$tests['local fixture requires a development runtime and explicit marker'] = function (): void {
    $fixture = documentConfig('local-primary', 'privacy_policy', 'local-2026-07-23', 'LOCAL FIXTURE ONLY');
    $fixture['source'] = 'local_fixture';
    $registry = new ConsentDocumentRegistry([
        'environment' => 'local',
        'local_fixture' => $fixture,
    ]);
    assertSame(
        'local-2026-07-23',
        $registry->resolve('local-primary', 'privacy_policy', 'local-2026-07-23')->version()
    );

    $fixture['source'] = 'configured';
    expectException(InvalidArgumentException::class, function () use ($fixture): void {
        new ConsentDocumentRegistry(['environment' => 'local', 'local_fixture' => $fixture]);
    });
};

$tests['production runtime refuses local fixture data'] = function (): void {
    $fixture = documentConfig('local-primary', 'privacy_policy', 'local-2026-07-23', 'LOCAL FIXTURE ONLY');
    $fixture['source'] = 'local_fixture';
    expectException(InvalidArgumentException::class, function () use ($fixture): void {
        new ConsentDocumentRegistry(['environment' => 'production', 'local_fixture' => $fixture]);
    });
};

$tests['privacy digest is keyed stable and salt dependent'] = function (): void {
    $first = new ConsentDocumentRegistry(['privacy_digest_salt' => str_repeat('a', 32)]);
    $second = new ConsentDocumentRegistry(['privacy_digest_salt' => str_repeat('b', 32)]);
    assertSame($first->privacyDigest('203.0.113.8'), $first->privacyDigest('203.0.113.8'));
    assertNotSame($first->privacyDigest('203.0.113.8'), $second->privacyDigest('203.0.113.8'));
    assertSame('', $first->privacyDigest(''));
};

$tests['privacy digest omits missing salt and rejects a weak configured salt'] = function (): void {
    assertSame('', (new ConsentDocumentRegistry([]))->privacyDigest('203.0.113.8'));
    $registry = new ConsentDocumentRegistry(['privacy_digest_salt' => 'too-short']);
    expectException(InvalidArgumentException::class, function () use ($registry): void {
        $registry->privacyDigest('203.0.113.8');
    });
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

function consent(array $changes = []): array
{
    return $changes + [
        'document_code' => 'privacy_policy',
        'document_version' => 'v2',
        'accepted' => true,
    ];
}

function internalKey(): string
{
    return BootstrapIdempotency::deriveInternalKey(
        1,
        'member.bootstrap',
        'crmeb_user',
        42,
        'request-0001'
    );
}

function documentConfig(string $tenant, string $code, string $version, string $content): array
{
    return [
        'tenant_slug' => $tenant,
        'document_code' => $code,
        'document_version' => $version,
        'content' => $content,
    ];
}

function registry(array $additional = []): ConsentDocumentRegistry
{
    return new ConsentDocumentRegistry([
        'documents_json' => json_encode(array_merge([
            documentConfig('local-primary', 'privacy_policy', 'v2', 'Current privacy policy'),
        ], $additional)),
        'privacy_digest_salt' => str_repeat('s', 32),
    ]);
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

function assertNotSame($expected, $actual): void
{
    if ($expected === $actual) {
        throw new RuntimeException(sprintf('Did not expect %s', var_export($actual, true)));
    }
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

    throw new RuntimeException("Expected exception {$class}");
}

function expectMemberReason(string $reason, callable $callback): void
{
    try {
        $callback();
    } catch (MemberTransactionException $exception) {
        assertSame(409, $exception->httpStatus());
        assertSame($reason, $exception->reason());

        return;
    }

    throw new RuntimeException("Expected MemberTransactionException reason {$reason}");
}
