<?php

declare(strict_types=1);

use app\services\system\admin\AdminAuthServices;
use crmeb\services\CacheService;
use think\App;
use think\facade\Db;

/**
 * G2 admin write HTTP gate fixture.
 *
 * Operates inside the php-fpm container (gVisor/Docker Desktop): issues a level-0
 * CRMEB admin JWT and caches it exactly as AdminAuthServices parses tokens, and
 * (for manual check-in) materialises a scoped member + a status=1 registration
 * that the gate checks in over HTTP.
 *
 * Actions:
 *   setup                                             -> {'token','admin_id'}
 *   create-registration <event_id> <ticket_id>        -> {'registration_id','member_id','uid'}
 *   cleanup [--token <jwt>] [<event_id>...]           removes fixture rows + cached token
 */

require dirname(__DIR__, 3) . '/vendor/autoload.php';

(new App())->initialize();

try {
    $action = $argv[1] ?? '';
    if ($action === 'setup') {
        setupFixture();
    } elseif ($action === 'create-registration') {
        createRegistrationForCheckin(
            positiveId($argv, 2, 'event_id'),
            positiveId($argv, 3, 'ticket_id')
        );
    } elseif ($action === 'cleanup') {
        cleanupFixture(array_slice($argv, 2));
    } else {
        throw new InvalidArgumentException('Expected setup, create-registration, or cleanup action');
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'event admin write HTTP fixture failure: ' . $exception->getMessage() . "\n");
    exit(1);
}

function setupFixture(): void
{
    $admin = Db::table('eb_system_admin')
        ->where('status', 1)
        ->where('is_del', 0)
        ->where('level', 0)
        ->limit(1)
        ->find();
    if (!is_array($admin)) {
        throw new RuntimeException('No active level-0 system admin available');
    }
    $adminId = (int) $admin['id'];
    $adminPwd = (string) ($admin['pwd'] ?? '');

    /** @var AdminAuthServices $authService */
    $authService = app()->make(AdminAuthServices::class);
    $tokenInfo = $authService->createToken($adminId, 'admin', $adminPwd);
    $token = (string) $tokenInfo['token'];

    CacheService::set(md5($token), [
        'id' => $adminId,
        'type' => 'admin',
        'account' => (string) ($admin['account'] ?? ''),
    ], 86400, 'admin');

    outputJson(['token' => $token, 'admin_id' => $adminId]);
}

function createRegistrationForCheckin(int $eventId, int $ticketId): void
{
    removeRegistrationsForTicket($eventId, $ticketId);
    $scope = tenantScope();
    $run = strtoupper(bin2hex(random_bytes(6)));
    $now = time();
    $account = 'g2_admin_' . strtolower($run);

    $uid = (int) Db::table('eb_user')->insertGetId([
        'account' => $account,
        'nickname' => $account,
        'phone' => '139' . substr(hash('sha256', $run), 0, 8),
        'add_time' => $now,
        'status' => 1,
        'user_type' => 'h5',
        'is_del' => 0,
    ]);
    $memberId = (int) Db::table('ch_tenant_member')->insertGetId([
        'tenant_id' => $scope['tenant_id'],
        'uid' => $uid,
        'first_channel_id' => $scope['channel_id'],
        'current_channel_id' => $scope['channel_id'],
        'referrer_uid' => 0,
        'invite_code' => 'AW' . substr($run, 0, 14),
        'attribution_locked_time' => $now,
        'tier' => 3,
        'verification_status' => 1,
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
    $registrationId = (int) Db::table('ch_event_registration')->insertGetId([
        'tenant_id' => $scope['tenant_id'],
        'event_id' => $eventId,
        'ticket_id' => $ticketId,
        'member_id' => $memberId,
        'uid' => $uid,
        'registration_no' => 'G2ADWR' . $run,
        'status' => 1,
        'integral_amount' => 0,
        'amount' => '0.00',
        'add_time' => $now,
        'update_time' => $now,
    ]);
    if ($registrationId <= 0) {
        throw new RuntimeException('Registration fixture could not be created');
    }

    outputJson([
        'registration_id' => $registrationId,
        'member_id' => $memberId,
        'uid' => $uid,
    ]);
}

/**
 * cleanup arguments: optional literal token(s) followed by event id(s).
 * A token is anything that is not a string of digits; every pure-digit arg is an
 * event id whose event (and ticket/check-in/audit/registration rows) we delete.
 */
function cleanupFixture(array $args): void
{
    $eventIds = [];
    foreach ($args as $arg) {
        if (!is_string($arg) || $arg === '') {
            continue;
        }
        if (preg_match('/^[1-9][0-9]*$/D', $arg) === 1) {
            $eventIds[] = (int) $arg;
            continue;
        }
        // Assume a bearer token literal.
        CacheService::delete(md5($arg));
    }

    // Registrations/members created for the check-in path.
    $uids = array_map('intval', Db::table('eb_user')->whereLike('account', 'g2\_admin\_%')->column('uid'));
    if ($uids !== []) {
        $regIds = array_column(Db::table('ch_event_registration')->whereIn('uid', $uids)->column('id'), null);
        Db::table('ch_event_checkin')->whereIn('uid', $uids)->delete();
        Db::table('ch_point_ledger')->whereIn('uid', $uids)->where('source_type', 'event_checkin_reward')->delete();
        Db::table('ch_event_registration')->whereIn('uid', $uids)->delete();
        Db::table('ch_member_profile')->whereIn('uid', $uids)->delete();
        Db::table('ch_tenant_member')->whereIn('uid', $uids)->delete();
        Db::table('eb_user')->whereIn('uid', $uids)->delete();
    }

    if ($eventIds !== []) {
        Db::table('ch_event_checkin_token')->whereIn('event_id', $eventIds)->delete();
        Db::table('ch_audit_record')->where('business_type', 'event')->whereIn('business_id', $eventIds)->delete();
        Db::table('ch_event_checkin')->whereIn('event_id', $eventIds)->delete();
        Db::table('ch_event_registration')->whereIn('event_id', $eventIds)->delete();
        Db::table('ch_event_ticket')->whereIn('event_id', $eventIds)->delete();
        Db::table('ch_event')->whereIn('id', $eventIds)->delete();
    }
}

function removeRegistrationsForTicket(int $eventId, int $ticketId): void
{
    $rows = Db::table('ch_event_registration')->where('event_id', $eventId)->where('ticket_id', $ticketId)->select()->toArray();
    $uids = array_values(array_unique(array_map('intval', array_column($rows, 'uid'))));
    $regIds = array_map('intval', array_column($rows, 'id'));
    if ($regIds !== []) {
        Db::table('ch_event_checkin')->whereIn('registration_id', $regIds)->delete();
    }
    if ($uids !== []) {
        Db::table('ch_event_registration')->whereIn('uid', $uids)->delete();
        Db::table('ch_tenant_member')->whereIn('uid', $uids)->delete();
        Db::table('eb_user')->whereIn('uid', $uids)->delete();
    }
}

function tenantScope(): array
{
    $row = Db::table('ch_tenant')->alias('tenant')
        ->join(['ch_channel' => 'channel'], 'channel.tenant_id=tenant.id')
        ->where('tenant.slug', 'local-primary')
        ->where('channel.code', 'default')
        ->where('tenant.status', 1)
        ->where('tenant.is_del', 0)
        ->where('channel.status', 1)
        ->where('channel.is_del', 0)
        ->field('tenant.id AS tenant_id,channel.id AS channel_id')
        ->find();
    if (!is_array($row)) {
        throw new RuntimeException('Local event tenant scope is unavailable');
    }
    return ['tenant_id' => (int) $row['tenant_id'], 'channel_id' => (int) $row['channel_id']];
}

function positiveId(array $arguments, int $offset, string $field): int
{
    $value = $arguments[$offset] ?? '';
    if (!is_string($value) || preg_match('/^[1-9][0-9]*$/D', $value) !== 1) {
        throw new InvalidArgumentException($field . ' must be a positive integer');
    }
    return (int) $value;
}

function outputJson(array $value): void
{
    $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($json)) {
        throw new RuntimeException('Fixture output could not be encoded');
    }
    fwrite(STDOUT, $json . "\n");
}