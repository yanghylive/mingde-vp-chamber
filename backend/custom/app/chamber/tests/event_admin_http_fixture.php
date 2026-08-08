<?php

declare(strict_types=1);

use crmeb\services\CacheService;
use crmeb\utils\JwtAuth;
use think\App;
use think\facade\Db;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

(new App())->initialize();

try {
    $action = $argv[1] ?? '';
    if ($action === 'setup') {
        setupAdminEventFixture();
    } elseif ($action === 'registration') {
        createAdminEventRegistration(positiveAdminEventArgument($argv, 2, 'event_id'));
    } elseif ($action === 'inspect') {
        inspectAdminEventFixture(
            positiveAdminEventArgument($argv, 2, 'event_id'),
            positiveAdminEventArgument($argv, 3, 'registration_id')
        );
    } elseif ($action === 'cleanup') {
        cleanupAdminEventFixture(array_slice($argv, 2));
    } else {
        throw new InvalidArgumentException('Expected setup, registration, inspect, or cleanup action');
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'event admin HTTP fixture failure: ' . $exception->getMessage() . "\n");
    exit(1);
}

function setupAdminEventFixture(): void
{
    cleanupAdminEventFixture([]);
    $now = time();
    $run = strtolower(bin2hex(random_bytes(6)));
    $super = createAdminEventPrincipal('g2_aw_http_super_' . $run, 0, $now);
    $denied = createAdminEventPrincipal('g2_aw_http_denied_' . $run, 1, $now);
    $foreignScope = adminEventScope('local-secondary');
    $foreignEventId = rawAdminHttpEvent($foreignScope, (int) $super['id'], $now, $run);

    /** @var JwtAuth $jwt */
    $jwt = app()->make(JwtAuth::class);
    $adminToken = $jwt->createToken((int) $super['id'], 'admin', [
        'pwd' => md5((string) $super['pwd']),
    ])['token'];
    $deniedToken = $jwt->createToken((int) $denied['id'], 'admin', [
        'pwd' => md5((string) $denied['pwd']),
    ])['token'];

    outputAdminEventJson([
        'admin_token' => $adminToken,
        'denied_token' => $deniedToken,
        'admin_id' => (int) $super['id'],
        'denied_admin_id' => (int) $denied['id'],
        'foreign_event_id' => $foreignEventId,
    ]);
}

function createAdminEventPrincipal(string $account, int $level, int $now): array
{
    $pwd = password_hash('Admin12345', PASSWORD_BCRYPT);
    $id = (int) Db::table('eb_system_admin')->insertGetId([
        'account' => $account,
        'head_pic' => '',
        'pwd' => $pwd,
        'real_name' => $level === 0 ? 'G2Admin' : 'G2Denied',
        'roles' => '',
        'last_ip' => '127.0.0.1',
        'last_time' => 0,
        'add_time' => $now,
        'login_count' => 0,
        'level' => $level,
        'status' => 1,
        'division_id' => 0,
        'is_del' => 0,
    ]);
    if ($id <= 0) {
        throw new RuntimeException('Admin HTTP principal could not be created');
    }
    return ['id' => $id, 'pwd' => $pwd];
}

function createAdminEventRegistration(int $eventId): void
{
    $event = Db::table('ch_event')->where('id', $eventId)->where('is_del', 0)->find();
    if (!is_array($event) || (int) $event['status'] !== 1) {
        throw new RuntimeException('Published admin HTTP event was not found');
    }
    $ticket = Db::table('ch_event_ticket')->where('event_id', $eventId)->where('status', 1)
        ->where('is_del', 0)->order('id', 'asc')->find();
    if (!is_array($ticket)) {
        throw new RuntimeException('Admin HTTP event ticket was not found');
    }
    $now = time();
    $run = strtoupper(bin2hex(random_bytes(5)));
    $account = 'g2_aw_http_user_' . strtolower($run);
    $uid = (int) Db::table('eb_user')->insertGetId([
        'account' => $account,
        'nickname' => $account,
        'phone' => '136' . substr(hash('sha256', $run), 0, 8),
        'add_time' => $now,
        'status' => 1,
        'user_type' => 'h5',
        'is_del' => 0,
    ]);
    $memberId = (int) Db::table('ch_tenant_member')->insertGetId([
        'tenant_id' => (int) $event['tenant_id'],
        'uid' => $uid,
        'first_channel_id' => (int) $event['channel_id'],
        'current_channel_id' => (int) $event['channel_id'],
        'referrer_uid' => 0,
        'invite_code' => 'AH' . substr($run, 0, 14),
        'attribution_locked_time' => $now,
        'tier' => 2,
        'verification_status' => 2,
        'current_verification_id' => 0,
        'status' => 1,
        'join_time' => $now,
        'certified_time' => $now,
        'tier_expire_time' => 0,
        'current_membership_term_id' => 0,
        'membership_version' => 1,
        'add_time' => $now,
        'update_time' => $now,
        'is_del' => 0,
    ]);
    Db::table('ch_point_account')->insert([
        'tenant_id' => (int) $event['tenant_id'],
        'member_id' => $memberId,
        'uid' => $uid,
        'balance' => 100,
        'frozen_balance' => 0,
        'version' => 1,
        'add_time' => $now,
        'update_time' => $now,
    ]);
    $registrationId = (int) Db::table('ch_event_registration')->insertGetId([
        'tenant_id' => (int) $event['tenant_id'],
        'event_id' => $eventId,
        'ticket_id' => (int) $ticket['id'],
        'member_id' => $memberId,
        'uid' => $uid,
        'registration_no' => 'AH' . substr($run, 0, 20),
        'order_pk' => 0,
        'order_no' => '',
        'order_context_id' => 0,
        'amount' => '0.00',
        'integral_amount' => 0,
        'status' => 1,
        'reserve_expire_time' => 0,
        'paid_time' => $now,
        'cancel_time' => 0,
        'refund_time' => 0,
        'add_time' => $now,
        'update_time' => $now,
    ]);
    Db::table('ch_event_ticket')->where('id', (int) $ticket['id'])->inc('paid_count')->update();
    outputAdminEventJson([
        'registration_id' => $registrationId,
        'member_id' => $memberId,
        'uid' => $uid,
    ]);
}

function inspectAdminEventFixture(int $eventId, int $registrationId): void
{
    $registration = Db::table('ch_event_registration')->where('id', $registrationId)->find();
    $memberId = is_array($registration) ? (int) $registration['member_id'] : 0;
    outputAdminEventJson([
        'event_status' => (int) Db::table('ch_event')->where('id', $eventId)->value('status'),
        'token_count' => (int) Db::table('ch_event_checkin_token')->where('event_id', $eventId)->count(),
        'checkin_count' => (int) Db::table('ch_event_checkin')->where('registration_id', $registrationId)->count(),
        'registration_status' => is_array($registration) ? (int) $registration['status'] : -1,
        'reward_count' => (int) Db::table('ch_event_reward')->where('registration_id', $registrationId)->count(),
        'contribution_count' => $memberId > 0
            ? (int) Db::table('ch_contribution_ledger')->where('member_id', $memberId)->count()
            : 0,
        'point_balance' => $memberId > 0
            ? (int) Db::table('ch_point_account')->where('member_id', $memberId)->value('balance')
            : -1,
        'cancel_audit_count' => (int) Db::table('ch_audit_record')
            ->where('business_type', 'event')->where('business_id', $eventId)->where('action', 'cancel')->count(),
    ]);
}

function cleanupAdminEventFixture(array $tokens): void
{
    foreach ($tokens as $token) {
        if (is_string($token) && $token !== '') {
            CacheService::delete(md5($token));
        }
    }
    $admins = Db::table('eb_system_admin')->whereLike('account', 'g2\_aw\_http\_%')
        ->field('id')->select()->toArray();
    $adminIds = array_values(array_filter(array_map('intval', array_column($admins, 'id'))));
    $eventIds = $adminIds === [] ? [] : array_values(array_filter(array_map(
        'intval',
        Db::table('ch_event')->whereIn('created_admin_id', $adminIds)->column('id')
    )));
    $uids = array_values(array_filter(array_map(
        'intval',
        Db::table('eb_user')->whereLike('account', 'g2\_aw\_http\_user\_%')->column('uid')
    )));
    $memberIds = $uids === [] ? [] : array_values(array_filter(array_map(
        'intval',
        Db::table('ch_tenant_member')->whereIn('uid', $uids)->column('id')
    )));
    if ($eventIds !== []) {
        Db::table('ch_event_checkin')->whereIn('event_id', $eventIds)->delete();
        Db::table('ch_event_checkin_token')->whereIn('event_id', $eventIds)->delete();
        Db::table('ch_event_reward')->whereIn('event_id', $eventIds)->delete();
        Db::table('ch_event_registration')->whereIn('event_id', $eventIds)->delete();
        Db::table('ch_audit_record')->where('business_type', 'event')->whereIn('business_id', $eventIds)->delete();
        Db::table('ch_event_ticket')->whereIn('event_id', $eventIds)->delete();
        Db::table('ch_event')->whereIn('id', $eventIds)->delete();
    }
    if ($memberIds !== []) {
        Db::table('ch_contribution_ledger')->whereIn('member_id', $memberIds)->delete();
        Db::table('ch_point_ledger')->whereIn('member_id', $memberIds)->delete();
        Db::table('ch_point_account')->whereIn('member_id', $memberIds)->delete();
    }
    if ($uids !== []) {
        Db::table('ch_member_profile')->whereIn('uid', $uids)->delete();
        Db::table('ch_tenant_member')->whereIn('uid', $uids)->delete();
        Db::table('eb_user')->whereIn('uid', $uids)->delete();
    }
    if ($adminIds !== []) {
        $recordIds = [];
        $rows = Db::table('ch_idempotency_record')->whereIn('operation', [
            'createEventForAdmin',
            'updateEventForAdmin',
            'publishEventForAdmin',
            'cancelEventForAdmin',
            'issueEventCheckinTokenForAdmin',
            'createManualEventCheckinForAdmin',
        ])->field('id,result_json')->select()->toArray();
        foreach ($rows as $row) {
            $result = json_decode((string) ($row['result_json'] ?? ''), true);
            if (is_array($result) && in_array((int) ($result['principal_id'] ?? 0), $adminIds, true)) {
                $recordIds[] = (int) $row['id'];
            }
        }
        if ($recordIds !== []) {
            Db::table('ch_idempotency_record')->whereIn('id', $recordIds)->delete();
        }
        Db::table('eb_system_admin')->whereIn('id', $adminIds)->delete();
    }
}

function adminEventScope(string $tenantSlug): array
{
    $row = Db::table('ch_tenant')->alias('tenant')
        ->join(['ch_channel' => 'channel'], 'channel.tenant_id=tenant.id')
        ->where('tenant.slug', $tenantSlug)->where('channel.code', 'default')
        ->where('tenant.status', 1)->where('tenant.is_del', 0)
        ->where('channel.status', 1)->where('channel.is_del', 0)
        ->field('tenant.id AS tenant_id,channel.id AS channel_id')->find();
    if (!is_array($row)) {
        throw new RuntimeException('Admin HTTP tenant scope is unavailable: ' . $tenantSlug);
    }
    return ['tenant_id' => (int) $row['tenant_id'], 'channel_id' => (int) $row['channel_id']];
}

function rawAdminHttpEvent(array $scope, int $adminId, int $now, string $run): int
{
    return (int) Db::table('ch_event')->insertGetId([
        'tenant_id' => $scope['tenant_id'], 'channel_id' => $scope['channel_id'],
        'event_no' => 'G2AWHTTP' . strtoupper(substr($run, 0, 20)),
        'event_type' => 'growth', 'title' => 'Foreign admin event',
        'cover_image' => '', 'summary' => '', 'detail' => '',
        'tags_json' => '[]', 'speakers_json' => '[]',
        'start_time' => $now + 14400, 'end_time' => $now + 18000,
        'signup_start_time' => $now - 600, 'signup_end_time' => $now + 7200,
        'location_name' => '', 'address' => '',
        'longitude' => '0.000000', 'latitude' => '0.000000',
        'min_tier' => 1, 'eligibility_json' => '{}', 'refund_policy_json' => '{}',
        'checkin_reward_points' => 0, 'checkin_reward_contribution' => 0,
        'status' => 0, 'created_admin_id' => $adminId, 'publish_time' => 0,
        'add_time' => $now, 'update_time' => $now, 'is_del' => 0,
    ]);
}

function positiveAdminEventArgument(array $arguments, int $offset, string $field): int
{
    $value = $arguments[$offset] ?? '';
    if (!is_string($value) || preg_match('/^[1-9][0-9]*$/D', $value) !== 1) {
        throw new InvalidArgumentException($field . ' must be a positive integer');
    }
    return (int) $value;
}

function outputAdminEventJson(array $value): void
{
    $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($json)) {
        throw new RuntimeException('Admin event fixture output could not be encoded');
    }
    fwrite(STDOUT, $json . "\n");
}
