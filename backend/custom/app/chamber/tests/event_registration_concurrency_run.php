<?php

declare(strict_types=1);

use app\chamber\activity\EventEligibility;
use app\chamber\activity\EventRegistrationRequest;
use app\chamber\exceptions\MemberTransactionException;
use app\chamber\identity\AuthenticatedUserContext;
use app\chamber\membership\BootstrapIdempotency;
use app\chamber\services\EventRegistrationService;
use app\chamber\tenancy\TenantContext;
use app\chamber\tenancy\TenantRecord;
use think\App;
use think\facade\Db;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

(new App())->initialize();

if (($argv[1] ?? '') === 'worker') {
    runWorker((string) ($argv[2] ?? ''));
    exit(0);
}

$now = time();
$runId = strtolower(bin2hex(random_bytes(6)));
$assertions = 0;
$memberIds = [];
$uids = [];
$idempotencyKeys = [];
$eventId = 0;
$ticketId = 0;

try {
    $tenant = requiredRow('ch_tenant', ['slug' => 'local-primary', 'status' => 1, 'is_del' => 0]);
    $channel = requiredRow('ch_channel', [
        'tenant_id' => (int) $tenant['id'],
        'code' => 'default',
        'status' => 1,
        'is_del' => 0,
    ]);
    $role = requiredRow('ch_persona_role', [
        'tenant_id' => (int) $tenant['id'],
        'code' => 'mentor',
        'status' => 1,
        'is_del' => 0,
    ]);
    $baseUid = (int) Db::table('ch_tenant_member')->max('uid') + random_int(1000, 10000);
    for ($index = 0; $index < 6; $index++) {
        $uid = $baseUid + $index;
        $memberId = createRaceMember((int) $tenant['id'], (int) $channel['id'], $uid, $now);
        Db::table('ch_point_account')->insert([
            'tenant_id' => (int) $tenant['id'],
            'member_id' => $memberId,
            'uid' => $uid,
            'balance' => 100,
            'version' => 1,
            'add_time' => $now,
            'update_time' => $now,
        ]);
        Db::table('ch_member_role')->insert([
            'tenant_id' => (int) $tenant['id'],
            'member_id' => $memberId,
            'uid' => $uid,
            'role_id' => (int) $role['id'],
            'is_primary' => 1,
            'grant_source' => 0,
            'source_application_id' => 0,
            'status' => 1,
            'effective_time' => $now - 10,
            'expire_time' => 0,
            'revoke_time' => 0,
            'add_time' => $now,
            'update_time' => $now,
        ]);
        $memberIds[] = $memberId;
        $uids[] = $uid;
    }

    $eventId = (int) Db::table('ch_event')->insertGetId([
        'tenant_id' => (int) $tenant['id'],
        'channel_id' => (int) $channel['id'],
        'event_no' => 'RR' . strtoupper($runId),
        'event_type' => 'industry',
        'title' => 'Registration concurrency test',
        'cover_image' => '',
        'summary' => 'Six members compete for two seats',
        'tags_json' => '[]',
        'speakers_json' => '[]',
        'detail' => '',
        'start_time' => $now + 7200,
        'end_time' => $now + 10800,
        'signup_start_time' => $now - 3600,
        'signup_end_time' => $now + 3600,
        'location_name' => 'Test venue',
        'address' => '',
        'longitude' => '0.000000',
        'latitude' => '0.000000',
        'min_tier' => 2,
        'eligibility_json' => json_encode([
            'allowed_channel_ids' => [(int) $channel['id']],
            'min_points' => 50,
            'required_roles' => ['mentor'],
        ]),
        'refund_policy_json' => '{}',
        'checkin_reward_points' => 0,
        'checkin_reward_contribution' => 0,
        'status' => EventEligibility::EVENT_PUBLISHED,
        'created_admin_id' => 1,
        'publish_time' => $now - 100,
        'add_time' => $now,
        'update_time' => $now,
        'is_del' => 0,
    ]);
    $ticketId = (int) Db::table('ch_event_ticket')->insertGetId([
        'tenant_id' => (int) $tenant['id'],
        'event_id' => $eventId,
        'name' => 'Two seat ticket',
        'price' => '0.00',
        'integral_price' => 0,
        'product_id' => 0,
        'product_attr_unique' => '',
        'capacity' => 2,
        'reserved_count' => 0,
        'paid_count' => 0,
        'min_tier' => 2,
        'eligibility_json' => '{}',
        'refund_policy_json' => '{}',
        'sale_start_time' => $now - 3600,
        'sale_end_time' => $now + 3600,
        'status' => 1,
        'sort' => 1,
        'add_time' => $now,
        'update_time' => $now,
        'is_del' => 0,
    ]);

    $processes = [];
    foreach ($uids as $index => $uid) {
        $callerKey = 'event-registration-race-' . $runId . '-' . $index;
        $idempotencyKeys[] = BootstrapIdempotency::deriveInternalKey(
            (int) $tenant['id'],
            'createEventRegistration',
            'crmeb_user',
            $uid,
            $callerKey
        );
        $payload = base64_encode(json_encode([
            'tenant_id' => (int) $tenant['id'],
            'tenant_slug' => (string) $tenant['slug'],
            'channel_id' => (int) $channel['id'],
            'channel_code' => (string) $channel['code'],
            'event_id' => $eventId,
            'ticket_id' => $ticketId,
            'uid' => $uid,
            'caller_key' => $callerKey,
        ], JSON_UNESCAPED_SLASHES));
        $command = escapeshellarg(PHP_BINARY)
            . ' ' . escapeshellarg(__FILE__)
            . ' worker ' . escapeshellarg($payload);
        $pipes = [];
        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start registration race worker');
        }
        fclose($pipes[0]);
        $processes[] = [$process, $pipes];
    }

    $results = [];
    foreach ($processes as list($process, $pipes)) {
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        if ($exitCode !== 0) {
            throw new RuntimeException('Registration race worker failed: ' . trim((string) $stderr));
        }
        $decoded = json_decode(trim((string) $stdout), true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Registration race worker returned invalid JSON: ' . trim((string) $stdout));
        }
        $results[] = $decoded;
    }

    $successes = array_values(array_filter($results, function (array $result): bool {
        return ($result['status'] ?? '') === 'registered';
    }));
    $failures = array_values(array_filter($results, function (array $result): bool {
        return ($result['status'] ?? '') === 'failed';
    }));
    assertSame(2, count($successes));
    assertSame(4, count($failures));
    foreach ($failures as $failure) {
        assertSame('event_full', $failure['reason'] ?? null);
        assertSame(409, $failure['http_status'] ?? null);
    }
    assertSame(2, (int) Db::table('ch_event_ticket')->where('id', $ticketId)->value('paid_count'));
    assertSame(2, (int) Db::table('ch_event_registration')->where('event_id', $eventId)->count());
    assertSame(2, count(array_unique(array_column($successes, 'registration_id'))));

    fwrite(STDOUT, sprintf(
        "Event registration concurrency passed (%d assertions; 6 contenders / 2 seats).\n",
        $assertions
    ));
} finally {
    if ($idempotencyKeys !== []) {
        Db::table('ch_idempotency_record')->whereIn('idempotency_key', $idempotencyKeys)->delete();
    }
    if ($eventId > 0) {
        Db::table('ch_event_registration')->where('event_id', $eventId)->delete();
    }
    if ($ticketId > 0) {
        Db::table('ch_event_ticket')->where('id', $ticketId)->delete();
    }
    if ($eventId > 0) {
        Db::table('ch_event')->where('id', $eventId)->delete();
    }
    if ($memberIds !== []) {
        Db::table('ch_point_ledger')->whereIn('member_id', $memberIds)->delete();
        Db::table('ch_point_account')->whereIn('member_id', $memberIds)->delete();
        Db::table('ch_member_role')->whereIn('member_id', $memberIds)->delete();
        Db::table('ch_tenant_member')->whereIn('id', $memberIds)->delete();
    }
}

function runWorker(string $encoded): void
{
    $decoded = base64_decode($encoded, true);
    $input = is_string($decoded) ? json_decode($decoded, true) : null;
    if (!is_array($input)) {
        fwrite(STDERR, "Invalid worker payload\n");
        exit(2);
    }
    $tenant = new TenantContext(new TenantRecord(
        (int) $input['tenant_id'],
        (string) $input['tenant_slug'],
        (int) $input['channel_id'],
        (string) $input['channel_code'],
        true
    ), 'event-registration-race');
    $auth = new AuthenticatedUserContext((int) $input['uid'], true, 'api');
    try {
        $result = (new EventRegistrationService())->register(
            $tenant,
            $auth,
            (int) $input['event_id'],
            EventRegistrationRequest::fromArray([
                'ticket_id' => (int) $input['ticket_id'],
                'expected_amount' => '0.00',
                'expected_integral' => 0,
            ]),
            (string) $input['caller_key']
        );
        fwrite(STDOUT, json_encode([
            'status' => 'registered',
            'registration_id' => (int) $result['id'],
        ], JSON_UNESCAPED_SLASHES));
    } catch (MemberTransactionException $exception) {
        fwrite(STDOUT, json_encode([
            'status' => 'failed',
            'reason' => $exception->reason(),
            'http_status' => $exception->httpStatus(),
        ], JSON_UNESCAPED_SLASHES));
    } catch (Throwable $exception) {
        fwrite(STDERR, $exception->getMessage() . "\n");
        exit(2);
    }
}

function requiredRow(string $table, array $where): array
{
    $query = Db::table($table);
    foreach ($where as $field => $value) {
        $query->where($field, $value);
    }
    $row = $query->find();
    if (!is_array($row)) {
        throw new RuntimeException('Required fixture was not found: ' . $table);
    }

    return $row;
}

function createRaceMember(int $tenantId, int $channelId, int $uid, int $now): int
{
    return (int) Db::table('ch_tenant_member')->insertGetId([
        'tenant_id' => $tenantId,
        'uid' => $uid,
        'first_channel_id' => $channelId,
        'current_channel_id' => $channelId,
        'referrer_uid' => 0,
        'tier' => 2,
        'verification_status' => 2,
        'primary_role_id' => 0,
        'status' => 1,
        'join_time' => $now,
        'certified_time' => $now,
        'tier_expire_time' => 0,
        'add_time' => $now,
        'update_time' => $now,
        'is_del' => 0,
    ]);
}

function assertSame($expected, $actual): void
{
    global $assertions;
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf(
            'Expected %s, got %s',
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
}
