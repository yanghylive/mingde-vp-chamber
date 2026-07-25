<?php

declare(strict_types=1);

use app\chamber\exceptions\MemberTransactionException;
use app\chamber\identity\AuthenticatedAdminContext;
use app\chamber\membership\GraduateVerificationState;
use app\chamber\services\GraduateVerificationService;
use app\chamber\verification\GraduateVerificationAdminQuery;
use app\chamber\verification\GraduateVerificationApplication;
use app\chamber\verification\GraduateVerificationReviewRequest;
use app\chamber\verification\GraduateVerificationSubmission;
use app\chamber\verification\GraduateVerificationValidationException;

spl_autoload_register(function (string $class): void {
    $prefix = 'app\\chamber\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    require dirname(__DIR__) . '/' . str_replace('\\', '/', $relative) . '.php';
});

$tests = [];

$tests['submission has a stable canonical shape and strict scalar types'] = function (): void {
    $submission = GraduateVerificationSubmission::fromArray(validSubmission());
    assertSame('2024 AI CEO 研修班', $submission->className());
    assertSame(2024, $submission->graduationYear());
    assertSame(1711929600, $submission->graduationAt());
    assertSame(['graduate-proof/42/diploma.jpg'], $submission->proofObjectKeys());
    assertSame(0, $submission->supersedesId());
    assertSame(array_merge(validSubmission(), ['supersedes_id' => 0]), $submission->toCanonicalArray());

    foreach (['2024', 2024.0, true, null] as $invalid) {
        expectValidation('graduation_year', 'out_of_range', function () use ($invalid): void {
            GraduateVerificationSubmission::fromArray(validSubmission(['graduation_year' => $invalid]));
        });
    }
    foreach (['1711929600', -1, 4294967296, false] as $invalid) {
        expectValidation('graduation_at', 'out_of_range', function () use ($invalid): void {
            GraduateVerificationSubmission::fromArray(validSubmission(['graduation_at' => $invalid]));
        });
    }
};

$tests['submission rejects unknown fields and malformed supersedes values'] = function (): void {
    expectValidation('tenant_id', 'unknown_field', function (): void {
        GraduateVerificationSubmission::fromArray(validSubmission(['tenant_id' => 1]));
    });
    foreach ([0, -1, '12', true] as $invalid) {
        expectValidation('supersedes_id', 'invalid_value', function () use ($invalid): void {
            GraduateVerificationSubmission::fromArray(validSubmission(['supersedes_id' => $invalid]));
        });
    }
};

$tests['proof keys are a non-empty unique JSON list'] = function (): void {
    foreach ([[], ['named' => 'proof/a.jpg'], array_fill(0, 11, 'proof/same.jpg')] as $invalid) {
        expectValidation('proof_object_keys', 'invalid_value', function () use ($invalid): void {
            GraduateVerificationSubmission::fromArray(validSubmission(['proof_object_keys' => $invalid]));
        });
    }
    expectValidation('proof_object_keys', 'duplicate_value', function (): void {
        GraduateVerificationSubmission::fromArray(validSubmission([
            'proof_object_keys' => ['proof/a.jpg', 'proof/a.jpg'],
        ]));
    });
};

$tests['proof keys accept private object keys and reject URLs or traversal'] = function (): void {
    assertSame(
        'tenant-1/graduate/42/file_1-2.pdf',
        GraduateVerificationSubmission::assertObjectStorageKey(
            'tenant-1/graduate/42/file_1-2.pdf',
            'proof_object_keys[0]'
        )
    );
    foreach ([
        'https://example.test/file.jpg',
        '/absolute/file.jpg',
        'proof/../secret.jpg',
        'proof/./file.jpg',
        'proof//file.jpg',
        'proof/file.jpg/',
        'proof/file name.jpg',
        "proof/file.jpg\n",
    ] as $invalid) {
        expectValidation('proof_object_keys[0]', 'invalid_format', function () use ($invalid): void {
            GraduateVerificationSubmission::assertObjectStorageKey($invalid, 'proof_object_keys[0]');
        });
    }
};

$tests['review request maps actions and requires notes for adverse decisions'] = function (): void {
    $approve = GraduateVerificationReviewRequest::fromArray(['action' => 'approve']);
    assertSame(GraduateVerificationState::APPROVED, $approve->targetState());
    assertSame(['action' => 'approve', 'note' => ''], $approve->toCanonicalArray());

    foreach ([
        'return' => GraduateVerificationState::RETURNED,
        'reject' => GraduateVerificationState::REJECTED,
        'revoke' => GraduateVerificationState::REVOKED,
    ] as $action => $state) {
        $request = GraduateVerificationReviewRequest::fromArray([
            'action' => $action,
            'note' => '  evidence does not match  ',
        ]);
        assertSame($state, $request->targetState());
        assertSame('evidence does not match', $request->note());
    }

    foreach (['return', 'reject', 'revoke'] as $action) {
        expectValidation('note', 'required', function () use ($action): void {
            GraduateVerificationReviewRequest::fromArray(['action' => $action]);
        });
    }
};

$tests['review request rejects unknown actions fields and loose note types'] = function (): void {
    expectValidation('action', 'invalid_value', function (): void {
        GraduateVerificationReviewRequest::fromArray(['action' => 'approved']);
    });
    expectValidation('operator_id', 'unknown_field', function (): void {
        GraduateVerificationReviewRequest::fromArray(['action' => 'approve', 'operator_id' => 1]);
    });
    expectValidation('note', 'invalid_type', function (): void {
        GraduateVerificationReviewRequest::fromArray(['action' => 'approve', 'note' => 1]);
    });
};

$tests['state mapping and transition matrix are explicit'] = function (): void {
    $expectedCodes = [
        GraduateVerificationState::DRAFT => 0,
        GraduateVerificationState::PENDING => 1,
        GraduateVerificationState::APPROVED => 2,
        GraduateVerificationState::RETURNED => 3,
        GraduateVerificationState::REJECTED => 4,
        GraduateVerificationState::REVOKED => 5,
    ];
    foreach ($expectedCodes as $state => $code) {
        assertSame($code, GraduateVerificationState::toDatabase($state));
        assertSame($state, GraduateVerificationState::fromDatabase($code));
    }

    assertSame(true, GraduateVerificationState::canTransition('draft', 'pending'));
    assertSame(true, GraduateVerificationState::canTransition('pending', 'approved'));
    assertSame(true, GraduateVerificationState::canTransition('pending', 'returned'));
    assertSame(true, GraduateVerificationState::canTransition('pending', 'rejected'));
    assertSame(true, GraduateVerificationState::canTransition('approved', 'revoked'));
    assertSame(false, GraduateVerificationState::canTransition('pending', 'revoked'));
    assertSame(false, GraduateVerificationState::canTransition('returned', 'pending'));
    assertSame(false, GraduateVerificationState::canTransition('approved', 'approved'));
};

$tests['admin query has bounded pagination and a strict allowlist'] = function (): void {
    $defaults = GraduateVerificationAdminQuery::fromArray([]);
    assertSame(null, $defaults->status());
    assertSame('', $defaults->keyword());
    assertSame(1, $defaults->page());
    assertSame(20, $defaults->perPage());

    $query = GraduateVerificationAdminQuery::fromArray([
        'status' => 'pending',
        'keyword' => '  张三  ',
        'page' => '2',
        'per_page' => 100,
    ]);
    assertSame('pending', $query->status());
    assertSame('张三', $query->keyword());
    assertSame(2, $query->page());
    assertSame(100, $query->perPage());

    foreach ([0, -1, 101, '01', true] as $invalid) {
        expectValidation('per_page', 'out_of_range', function () use ($invalid): void {
            GraduateVerificationAdminQuery::fromArray(['per_page' => $invalid]);
        });
    }
    expectValidation('channel_id', 'unknown_field', function (): void {
        GraduateVerificationAdminQuery::fromArray(['channel_id' => 1]);
    });
};

$tests['admin context distinguishes super administrators and explicit permissions'] = function (): void {
    $super = AuthenticatedAdminContext::fromAuthInfo(['id' => '7', 'level' => '0', 'type' => 'admin'], []);
    assertSame(7, $super->adminId());
    assertSame(true, $super->isSuperAdministrator());
    assertSame(true, $super->hasPermission(GraduateVerificationService::REVIEW_PERMISSION));

    $reviewer = AuthenticatedAdminContext::fromAuthInfo(
        ['id' => 8, 'level' => 1, 'type' => 'admin'],
        [GraduateVerificationService::REVIEW_PERMISSION]
    );
    assertSame(false, $reviewer->isSuperAdministrator());
    assertSame(true, $reviewer->hasPermission(GraduateVerificationService::REVIEW_PERMISSION));

    $ordinary = new AuthenticatedAdminContext(9, false, ['system.config.read']);
    $exception = expectException(MemberTransactionException::class, function () use ($ordinary): void {
        $ordinary->assertPermission(GraduateVerificationService::REVIEW_PERMISSION);
    });
    assertSame(403, $exception->httpStatus());
    assertSame('permission_denied', $exception->reason());
};

$tests['application snapshots expose the public shape and resubmit semantics'] = function (): void {
    $application = GraduateVerificationApplication::fromDatabaseRow(applicationRow());
    assertSame([
        'id',
        'application_no',
        'status',
        'class_name',
        'graduation_year',
        'graduation_at',
        'proof_object_keys',
        'proof_assets',
        'submitted_at',
        'reviewed_at',
        'review_note',
        'can_resubmit',
    ], array_keys($application));
    assertSame(GraduateVerificationState::PENDING, $application['status']);
    assertSame([], $application['proof_assets']);
    assertSame(false, $application['can_resubmit']);

    $returned = GraduateVerificationApplication::fromDatabaseRow(applicationRow([
        'status' => 3,
        'review_time' => 1712000000,
        'review_note' => 'please retry',
    ]));
    assertSame(true, $returned['can_resubmit']);
};

$tests['application snapshots reject corrupt proof JSON and object keys'] = function (): void {
    foreach (['not-json', '{"named":"proof/a.jpg"}', '["https://example.test/file.jpg"]'] as $proofJson) {
        expectException(RuntimeException::class, function () use ($proofJson): void {
            GraduateVerificationApplication::fromDatabaseRow(applicationRow(['proof_json' => $proofJson]));
        });
    }
};

$tests['application snapshots reject values outside the public contract'] = function (): void {
    foreach ([
        ['id' => 0],
        ['graduation_year' => 1899],
        ['graduation_year' => 2107],
        ['graduation_time' => -1],
        ['submit_time' => -1],
        ['review_time' => -1],
        ['class_name' => str_repeat('a', 81)],
        ['review_note' => str_repeat('b', 501)],
    ] as $invalid) {
        expectException(RuntimeException::class, function () use ($invalid): void {
            GraduateVerificationApplication::fromDatabaseRow(applicationRow($invalid));
        });
    }
};

$failures = [];
foreach ($tests as $name => $test) {
    try {
        $test();
        fwrite(STDOUT, "PASS {$name}\n");
    } catch (Throwable $exception) {
        $failures[] = sprintf('%s: %s', $name, $exception->getMessage());
        fwrite(STDERR, "FAIL {$name}: {$exception->getMessage()}\n");
    }
}

if ($failures !== []) {
    fwrite(STDERR, sprintf("%d graduate verification test(s) failed\n", count($failures)));
    exit(1);
}

fwrite(STDOUT, sprintf("Graduate verification domain tests passed (%d cases).\n", count($tests)));

function validSubmission(array $overrides = []): array
{
    return array_replace([
        'class_name' => '2024 AI CEO 研修班',
        'graduation_year' => 2024,
        'graduation_at' => 1711929600,
        'proof_object_keys' => ['graduate-proof/42/diploma.jpg'],
    ], $overrides);
}

function applicationRow(array $overrides = []): array
{
    return array_replace([
        'id' => 42,
        'apply_no' => str_repeat('a', 32),
        'status' => 1,
        'class_name' => '2024 AI CEO 研修班',
        'graduation_year' => 2024,
        'graduation_time' => 1711929600,
        'proof_json' => '["graduate-proof/42/diploma.jpg"]',
        'submit_time' => 1711929700,
        'review_time' => 0,
        'review_note' => '',
    ], $overrides);
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

function expectValidation(string $field, string $code, callable $callback): void
{
    $exception = expectException(GraduateVerificationValidationException::class, $callback);
    assertSame($field, $exception->field());
    assertSame($code, $exception->fieldCode());
}

/**
 * @return Throwable
 */
function expectException(string $class, callable $callback)
{
    try {
        $callback();
    } catch (Throwable $exception) {
        if ($exception instanceof $class) {
            return $exception;
        }
        throw $exception;
    }

    throw new RuntimeException(sprintf('Expected exception %s', $class));
}
