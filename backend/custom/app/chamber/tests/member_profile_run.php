<?php

declare(strict_types=1);

use app\chamber\exceptions\MemberTransactionException;
use app\chamber\membership\BootstrapIdempotency;
use app\chamber\membership\EncryptedIdempotencyResult;
use app\chamber\membership\MemberContext;
use app\chamber\membership\MemberProfilePatch;
use app\chamber\membership\MemberProfilePrivacy;
use app\chamber\membership\MemberProfileSnapshot;

spl_autoload_register(function (string $class): void {
    $prefix = 'app\\chamber\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file = dirname(__DIR__) . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

$tests = [];

$tests['profile patch accepts every public field with exact types'] = function (): void {
    $input = [
        'real_name' => 'Ada Lovelace',
        'avatar_object_key' => 'member/42/avatar.v2.png',
        'class_name' => 'Class 2010',
        'graduation_year' => 2010,
        'industry' => 'Technology',
        'company_name' => 'Analytical Engines',
        'job_title' => 'Founder',
        'main_business' => 'Computing',
        'province' => 'Liaoning',
        'city' => 'Shenyang',
        'bio' => 'Member biography',
        'resources' => ['Mentoring'],
        'needs' => ['Hiring'],
        'interests' => ['AI'],
        'expertise' => ['Algorithms'],
        'privacy' => [
            'real_name' => 'members',
            'company_name' => 'friends',
        ],
    ];

    assertSame($input, MemberProfilePatch::fromArray($input)->values());
};

$tests['profile patch rejects an empty object and unknown fields'] = function (): void {
    expectMemberError('request_validation_failed', 422, function (): void {
        MemberProfilePatch::fromArray([]);
    }, [['field' => 'body', 'code' => 'min_properties']]);
    expectMemberError('request_validation_failed', 422, function (): void {
        MemberProfilePatch::fromArray(['tenant_id' => 9]);
    }, [['field' => 'tenant_id', 'code' => 'unknown_field']]);
};

$tests['profile patch never coerces scalar or list types'] = function (): void {
    expectMemberError('request_validation_failed', 422, function (): void {
        MemberProfilePatch::fromArray([
            'real_name' => 42,
            'graduation_year' => '2010',
            'resources' => ['first' => 'Mentoring'],
            'privacy' => ['real_name' => true],
        ]);
    }, [
        ['field' => 'real_name', 'code' => 'invalid_type'],
        ['field' => 'graduation_year', 'code' => 'invalid_type'],
        ['field' => 'resources', 'code' => 'invalid_type'],
        ['field' => 'privacy.real_name', 'code' => 'invalid_type'],
    ]);
};

$tests['profile patch enforces string list and year boundaries'] = function (): void {
    expectMemberError('request_validation_failed', 422, function (): void {
        MemberProfilePatch::fromArray([
            'real_name' => str_repeat('a', 41),
            'graduation_year' => 1899,
            'interests' => [str_repeat('b', 61), ' '],
        ]);
    }, [
        ['field' => 'real_name', 'code' => 'too_long'],
        ['field' => 'graduation_year', 'code' => 'out_of_range'],
        ['field' => 'interests[0]', 'code' => 'too_long'],
        ['field' => 'interests[1]', 'code' => 'required'],
    ]);
};

$tests['avatar accepts private object keys and rejects paths or URLs'] = function (): void {
    foreach (['member/avatar.png', 'a_b/c-d.2'] as $valid) {
        assertSame($valid, MemberProfilePatch::fromArray(['avatar_object_key' => $valid])->value(
            'avatar_object_key'
        ));
    }
    foreach (['', 'https://cdn.example/avatar.png', '/member/avatar.png', 'member//avatar', 'member/../avatar', 'member/'] as $invalid) {
        expectMemberError('request_validation_failed', 422, function () use ($invalid): void {
            MemberProfilePatch::fromArray(['avatar_object_key' => $invalid]);
        });
    }
};

$tests['privacy uses a closed field and scope vocabulary'] = function (): void {
    $privacy = MemberProfilePrivacy::fromStoredJson('{}')->toArray();
    assertSame(15, count($privacy));
    foreach ($privacy as $scope) {
        assertSame('private', $scope);
    }

    expectMemberError('request_validation_failed', 422, function (): void {
        MemberProfilePatch::fromArray(['privacy' => [
            'phone' => 'public',
            'real_name' => 'tenant',
        ]]);
    }, [
        ['field' => 'privacy.phone', 'code' => 'unknown_field'],
        ['field' => 'privacy.real_name', 'code' => 'invalid_value'],
    ]);
};

$tests['stored privacy rejects malformed or expanded data'] = function (): void {
    foreach (['not-json', '[]', '{"phone":"public"}', '{"real_name":"tenant"}'] as $encoded) {
        expectException(InvalidArgumentException::class, function () use ($encoded): void {
            MemberProfilePrivacy::fromStoredJson($encoded);
        });
    }
};

$tests['stored list fields reject JSON objects even when empty'] = function (): void {
    expectException(InvalidArgumentException::class, function (): void {
        snapshot(profileRow(['resources_json' => '{}']));
    });
};

$tests['encrypted idempotency result authenticates payload and context'] = function (): void {
    putenv('CHAMBER_IDEMPOTENCY_ENCRYPTION_KEY=test-only-idempotency-encryption-key-32-bytes');
    $data = ['real_name' => 'Ada', 'proof_object_keys' => ['private/proof.png']];
    $sealed = EncryptedIdempotencyResult::seal($data, 'profile:42');

    $canonicalData = json_decode(BootstrapIdempotency::canonicalJson($data), true);
    assertSame($canonicalData, EncryptedIdempotencyResult::open($sealed, 'profile:42'));
    assertSame(false, strpos(json_encode($sealed), 'Ada') !== false);
    expectException(RuntimeException::class, function () use ($sealed): void {
        EncryptedIdempotencyResult::open($sealed, 'profile:43');
    });
};

$tests['profile snapshot exposes API fields without database identities'] = function (): void {
    $profile = profileRow([
        'real_name' => 'Ada',
        'profile_status' => 1,
        'resources_json' => '["Mentoring"]',
        'privacy_json' => '{"real_name":"members"}',
    ]);
    $snapshot = snapshot($profile);
    $data = $snapshot->toArray();

    assertSame('Ada', $data['real_name']);
    assertSame(['Mentoring'], $data['resources']);
    assertSame('members', $data['privacy']['real_name']);
    assertSame('private', $data['privacy']['company_name']);
    assertSame(true, $data['profile_complete']);
    assertSame(false, array_key_exists('tenant_id', $data));
    assertSame(false, array_key_exists('resources_json', $data));
};

$tests['profile patch transitions an incomplete profile and emits minimal database changes'] = function (): void {
    $before = snapshot(profileRow());
    $after = $before->apply(MemberProfilePatch::fromArray([
        'real_name' => 'Ada',
        'resources' => ['Mentoring'],
        'privacy' => ['real_name' => 'members'],
    ]), 1785000000);
    $changes = $after->databaseChangesFrom($before);

    assertSame('Ada', $changes['real_name']);
    assertSame('["Mentoring"]', $changes['resources_json']);
    assertSame(1, $changes['profile_status']);
    assertSame(1785000000, $changes['update_time']);
    $privacy = json_decode($changes['privacy_json'], true);
    assertSame('members', $privacy['real_name']);
    assertSame('private', $privacy['bio']);
    assertSame(true, $after->profileComplete());
};

$tests['same content patch is a no-op and preserves update time'] = function (): void {
    $before = snapshot(profileRow());
    $after = $before->apply(MemberProfilePatch::fromArray([
        'bio' => '',
        'privacy' => ['real_name' => 'private'],
    ]), 1785000000);

    assertSame([], $after->databaseChangesFrom($before));
    assertSame($before->updatedAt(), $after->updatedAt());
};

$tests['profile patch idempotency hash is canonical and content sensitive'] = function (): void {
    $left = MemberProfilePatch::fromArray([
        'real_name' => 'Ada',
        'privacy' => ['real_name' => 'members', 'bio' => 'private'],
    ]);
    $right = MemberProfilePatch::fromArray([
        'privacy' => ['bio' => 'private', 'real_name' => 'members'],
        'real_name' => 'Ada',
    ]);
    $changed = MemberProfilePatch::fromArray(['real_name' => 'Grace']);

    assertSame(
        BootstrapIdempotency::requestHash(11, $left->toCanonicalArray()),
        BootstrapIdempotency::requestHash(11, $right->toCanonicalArray())
    );
    assertNotSame(
        BootstrapIdempotency::requestHash(11, $left->toCanonicalArray()),
        BootstrapIdempotency::requestHash(11, $changed->toCanonicalArray())
    );
    assertNotSame(
        BootstrapIdempotency::deriveInternalKey(3, 'member.profile.patch', 'crmeb_user', 42, 'profile-request-0001'),
        BootstrapIdempotency::deriveInternalKey(4, 'member.profile.patch', 'crmeb_user', 42, 'profile-request-0001')
    );
};

$tests['editing a hidden profile never makes it directory visible'] = function (): void {
    $before = snapshot(profileRow(['real_name' => 'Ada', 'profile_status' => 0]));
    $after = $before->apply(MemberProfilePatch::fromArray(['bio' => 'Updated']), 1785000000);
    $changes = $after->databaseChangesFrom($before);

    assertSame(false, array_key_exists('profile_status', $changes));
    assertSame(true, $after->profileComplete());
};

$tests['profile snapshot fails closed on cross tenant and cross user rows'] = function (): void {
    $profile = profileRow();
    $member = memberWithProfile($profile);
    foreach ([['tenant_id' => 4], ['uid' => 43], ['member_id' => 8]] as $identityChange) {
        expectException(InvalidArgumentException::class, function () use ($member, $profile, $identityChange): void {
            MemberProfileSnapshot::fromRow($member, array_replace($profile, $identityChange));
        });
    }
};

$tests['profile snapshot fails closed on malformed stored JSON'] = function (): void {
    foreach ([
        ['resources_json' => '{"not":"a-list"}'],
        ['interests_json' => '[""]'],
        ['privacy_json' => '{"real_name":"tenant"}'],
    ] as $change) {
        expectException(InvalidArgumentException::class, function () use ($change): void {
            snapshot(profileRow($change));
        });
    }
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

function memberRow(): array
{
    return [
        'id' => 7,
        'tenant_id' => 3,
        'uid' => 42,
        'first_channel_id' => 11,
        'current_channel_id' => 11,
        'referrer_uid' => 0,
        'invite_code' => 'ABCDEFGH',
        'attribution_locked_time' => 1700000000,
        'tier' => 1,
        'verification_status' => 0,
        'current_verification_id' => 0,
        'primary_role_id' => 0,
        'status' => 1,
        'join_time' => 1700000000,
        'certified_time' => 0,
        'tier_expire_time' => 0,
        'current_membership_term_id' => 0,
        'membership_version' => 0,
        'add_time' => 1700000000,
        'update_time' => 1700000000,
        'is_del' => 0,
    ];
}

function profileRow(array $changes = []): array
{
    return array_replace([
        'id' => 9,
        'tenant_id' => 3,
        'member_id' => 7,
        'uid' => 42,
        'real_name' => '',
        'avatar_object_key' => '',
        'class_name' => '',
        'graduation_year' => 0,
        'industry' => '',
        'company_name' => '',
        'job_title' => '',
        'main_business' => '',
        'province' => '',
        'city' => '',
        'bio' => '',
        'resources_json' => '[]',
        'needs_json' => '[]',
        'interests_json' => '[]',
        'expertise_json' => '[]',
        'privacy_json' => '{}',
        'profile_status' => 2,
        'add_time' => 1700000000,
        'update_time' => 1700000000,
        'is_del' => 0,
    ], $changes);
}

function memberWithProfile(array $profile): MemberContext
{
    $row = memberRow();
    $row['profile'] = $profile;

    return MemberContext::fromRow($row);
}

function snapshot(array $profile): MemberProfileSnapshot
{
    return MemberProfileSnapshot::fromRow(memberWithProfile($profile), $profile);
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

function expectMemberError(
    string $reason,
    int $status,
    callable $callback,
    array $fieldErrors = null
): void {
    try {
        $callback();
    } catch (MemberTransactionException $exception) {
        assertSame($reason, $exception->reason());
        assertSame($status, $exception->httpStatus());
        if ($fieldErrors !== null) {
            assertSame($fieldErrors, $exception->fieldErrors());
        }

        return;
    }

    throw new RuntimeException("Expected MemberTransactionException reason {$reason}");
}
