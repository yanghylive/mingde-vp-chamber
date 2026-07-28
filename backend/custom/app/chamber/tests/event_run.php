<?php

declare(strict_types=1);

use app\chamber\activity\EventCheckinRequest;
use app\chamber\activity\EventCheckinToken;
use app\chamber\activity\EventEligibility;
use app\chamber\activity\EventListQuery;
use app\chamber\activity\EventRegistrationRequest;
use app\chamber\activity\EventRegistrationListQuery;
use app\chamber\exceptions\MemberTransactionException;

spl_autoload_register(function (string $class): void {
    $prefix = 'app\\chamber\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    require dirname(__DIR__) . '/' . str_replace('\\', '/', $relative) . '.php';
});

$tests = [];

$tests['event list query applies strict defaults and controlled filters'] = function (): void {
    $defaults = EventListQuery::fromArray([]);
    assertSame(null, $defaults->eventType());
    assertSame(null, $defaults->status());
    assertSame('', $defaults->tag());
    assertSame(1, $defaults->page());
    assertSame(20, $defaults->limit());

    $query = EventListQuery::fromArray([
        'event_type' => 'industry',
        'status' => 'registration_closed',
        'tag' => ' AI 应用 ',
        'page' => '2',
        'limit' => 100,
    ]);
    assertSame('industry', $query->eventType());
    assertSame(EventEligibility::EVENT_REGISTRATION_CLOSED, $query->databaseStatus());
    assertSame('AI 应用', $query->tag());
    assertSame(2, $query->page());
    assertSame(100, $query->limit());
};

$tests['event list query rejects unknown fields and loose pagination'] = function (): void {
    expectFieldError('tenant_id', 'unknown_field', function (): void {
        EventListQuery::fromArray(['tenant_id' => 1]);
    });
    foreach ([0, -1, 101, '01', true] as $invalid) {
        expectFieldError('limit', 'out_of_range', function () use ($invalid): void {
            EventListQuery::fromArray(['limit' => $invalid]);
        });
    }
    expectFieldError('status', 'invalid_value', function (): void {
        EventListQuery::fromArray(['status' => 'open']);
    });
};

$tests['registration list query maps lifecycle filters and pagination'] = function (): void {
    $defaults = EventRegistrationListQuery::fromArray([]);
    assertSame(null, $defaults->status());
    assertSame(1, $defaults->page());
    assertSame(20, $defaults->limit());

    $query = EventRegistrationListQuery::fromArray([
        'status' => 'waitlisted',
        'page' => '3',
        'limit' => 10,
    ]);
    assertSame('waitlisted', $query->status());
    assertSame(4, $query->databaseStatus());
    assertSame(3, $query->page());
    assertSame(10, $query->limit());

    expectFieldError('status', 'invalid_value', function (): void {
        EventRegistrationListQuery::fromArray(['status' => 'paid']);
    });
    expectFieldError('event_id', 'unknown_field', function (): void {
        EventRegistrationListQuery::fromArray(['event_id' => 1]);
    });
};

$tests['registration request freezes ticket price and points snapshots'] = function (): void {
    $request = EventRegistrationRequest::fromArray([
        'ticket_id' => 42,
        'expected_amount' => '19.90',
        'expected_integral' => 30,
    ]);
    assertSame(42, $request->ticketId());
    assertSame('19.90', $request->expectedAmount());
    assertSame(30, $request->expectedIntegral());
    assertSame([
        'ticket_id' => 42,
        'expected_amount' => '19.90',
        'expected_integral' => 30,
    ], $request->toIdempotencyArray());

    $minimal = EventRegistrationRequest::fromArray(['ticket_id' => 7]);
    assertSame(null, $minimal->expectedAmount());
    assertSame(null, $minimal->expectedIntegral());
};

$tests['registration request rejects loose identifiers prices and fields'] = function (): void {
    expectFieldError('ticket_id', 'invalid_value', function (): void {
        EventRegistrationRequest::fromArray(['ticket_id' => '42']);
    });
    expectFieldError('expected_amount', 'invalid_value', function (): void {
        EventRegistrationRequest::fromArray(['ticket_id' => 42, 'expected_amount' => '19.9']);
    });
    expectFieldError('expected_integral', 'invalid_value', function (): void {
        EventRegistrationRequest::fromArray(['ticket_id' => 42, 'expected_integral' => '30']);
    });
    expectFieldError('uid', 'unknown_field', function (): void {
        EventRegistrationRequest::fromArray(['ticket_id' => 42, 'uid' => 1]);
    });
};

$tests['check-in request is strict and token signatures are tenant scoped'] = function (): void {
    $request = EventCheckinRequest::fromArray([
        'token' => str_repeat('a', 24),
        'registration_id' => '42',
    ]);
    assertSame(str_repeat('a', 24), $request->token());
    assertSame(42, $request->registrationId());
    assertSame(0, EventCheckinRequest::fromArray(['token' => str_repeat('b', 24)])->registrationId());
    expectFieldError('registration_id', 'invalid_value', function (): void {
        EventCheckinRequest::fromArray(['token' => str_repeat('a', 24), 'registration_id' => 0]);
    });
    expectFieldError('token', 'invalid_length', function (): void {
        EventCheckinRequest::fromArray(['token' => 'short']);
    });

    $secret = str_repeat('s', 32);
    $issued = EventCheckinToken::issue(1, 10, 1700000000, 300, $secret);
    assertSame(true, EventCheckinToken::verify($issued['token'], 1, 10, 1700000001, $secret));
    assertSame(false, EventCheckinToken::verify($issued['token'], 2, 10, 1700000001, $secret));
    assertSame(false, EventCheckinToken::verify($issued['token'], 1, 10, 1700000301, $secret));
    assertSame($issued['digest'], EventCheckinToken::digest($issued['token']));
};

$tests['published eligible ticket passes all qualification rules'] = function (): void {
    assertSame(null, reason());
};

$tests['event and ticket windows expose stable qualification reasons'] = function (): void {
    assertSame('signup_closed', reason(['status' => EventEligibility::EVENT_REGISTRATION_CLOSED]));
    assertSame('event_started', reason(['status' => EventEligibility::EVENT_ENDED]));
    assertSame('event_not_open', reason(['status' => EventEligibility::EVENT_CANCELLED]));
    assertSame('signup_not_open', reason([], ['sale_start_time' => 1700000100]));
    assertSame('signup_closed', reason([], ['sale_end_time' => 1699999999]));
};

$tests['capacity is checked before member qualification'] = function (): void {
    assertSame('event_full', reason([], [], [], [], 100, false));
};

$tests['tier and verification gates are explicit'] = function (): void {
    assertSame('membership_tier_required', reason(['min_tier' => 3], [], ['tier' => 2]));
    assertSame(
        'membership_verification_required',
        reason(['min_tier' => 2], [], ['tier' => 2, 'verification_status' => 1])
    );
};

$tests['channel points and role rules are merged across event and ticket'] = function (): void {
    assertSame('channel_not_eligible', reason([
        'eligibility_json' => ['allowed_channel_ids' => [20]],
    ]));
    assertSame('points_required', reason([
        'eligibility_json' => ['min_points' => 101],
    ]));
    assertSame('role_required', reason([], [
        'eligibility_json' => ['required_roles' => ['mentor']],
    ]));
    assertSame(null, reason(
        ['eligibility_json' => ['allowed_channel_ids' => [10], 'min_points' => 50]],
        ['eligibility_json' => ['required_roles' => ['mentor']]],
        [],
        ['mentor'],
        100
    ));
};

$tests['eligibility normalizer returns a complete public shape'] = function (): void {
    assertSame([
        'allowed_channel_ids' => [],
        'min_points' => 0,
        'required_roles' => [],
    ], EventEligibility::normalizeRules([]));
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
    fwrite(STDERR, sprintf("%d activity domain test(s) failed\n", count($failures)));
    exit(1);
}

fwrite(STDOUT, sprintf("Activity domain tests passed (%d cases).\n", count($tests)));

function reason(
    array $eventOverrides = [],
    array $ticketOverrides = [],
    array $memberOverrides = [],
    array $roles = [],
    int $points = 100,
    bool $hasCapacity = true
): ?string {
    $event = array_replace([
        'status' => EventEligibility::EVENT_PUBLISHED,
        'signup_start_time' => 1699999000,
        'signup_end_time' => 1700001000,
        'start_time' => 1700002000,
        'min_tier' => 1,
        'eligibility_json' => [],
    ], $eventOverrides);
    $ticket = array_replace([
        'status' => 1,
        'sale_start_time' => 1699999000,
        'sale_end_time' => 1700001000,
        'min_tier' => 1,
        'eligibility_json' => [],
    ], $ticketOverrides);
    $member = array_replace([
        'tier' => 4,
        'verification_status' => 2,
        'current_channel_id' => 10,
    ], $memberOverrides);

    return EventEligibility::reason($event, $ticket, $member, $roles, $points, 1700000000, $hasCapacity);
}

function expectFieldError(string $field, string $code, callable $callback): void
{
    try {
        $callback();
    } catch (MemberTransactionException $exception) {
        assertSame(422, $exception->httpStatus());
        assertSame('request_validation_failed', $exception->reason());
        assertSame([['field' => $field, 'code' => $code]], $exception->fieldErrors());
        return;
    }

    throw new RuntimeException('Expected MemberTransactionException');
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
