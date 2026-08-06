<?php

declare(strict_types=1);

use crmeb\services\CacheService;
use crmeb\utils\JwtAuth;
use app\chamber\assets\LocalPrivateAssetStorage;
use app\chamber\membership\BootstrapIdempotency;
use think\App;
use think\facade\Db;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

(new App())->initialize();

$action = $argv[1] ?? '';

try {
    switch ($action) {
        case 'setup':
            fixtureSetup();
            break;
        case 'inspect':
            fixtureInspect(positiveArgument($argv, 2, 'uid'));
            break;
        case 'withdraw':
            fixtureWithdraw(positiveArgument($argv, 2, 'uid'));
            break;
        case 'asset-access':
            fixtureAssetAccess(positiveArgument($argv, 2, 'asset_id'));
            break;
        case 'cleanup':
            fixtureCleanup(array_slice($argv, 2));
            break;
        case 'cleanup-idempotency':
            fixtureCleanupIdempotency(
                positiveArgument($argv, 2, 'tenant_id'),
                stringArgument($argv, 3, 'operation'),
                stringArgument($argv, 4, 'principal_type'),
                positiveArgument($argv, 5, 'principal_id'),
                stringArgument($argv, 6, 'caller_key')
            );
            break;
        default:
            throw new InvalidArgumentException(
                'Expected setup, inspect, withdraw, asset-access, cleanup, or cleanup-idempotency action'
            );
    }
} catch (Throwable $exception) {
    fwrite(STDERR, sprintf("fixture failure: %s\n", $exception->getMessage()));
    exit(1);
}

function fixtureSetup(): void
{
    cleanupUsers(testUserIds());

    $run = bin2hex(random_bytes(4));
    $mainUid = createUser('g1b_test_main_' . $run, '13900000001');
    $referrerUid = createUser('g1b_test_ref1_' . $run, '13900000002');
    $otherReferrerUid = createUser('g1b_test_ref2_' . $run, '13900000003');
    $legacyUid = createUser('g1b_test_legacy_' . $run, '13900000004');
    $primary = tenantScope('local-primary');
    $secondary = tenantScope('local-secondary');
    $referrerCode = 'G1' . strtoupper(bin2hex(random_bytes(7)));
    $otherReferrerCode = 'G2' . strtoupper(bin2hex(random_bytes(7)));
    createReferrer($primary, $referrerUid, $referrerCode);
    createReferrer($primary, $otherReferrerUid, $otherReferrerCode);
    createLegacyMember($primary, $legacyUid, $referrerUid);

    /** @var JwtAuth $jwt */
    $jwt = app()->make(JwtAuth::class);
    $apiToken = $jwt->createToken($mainUid, 'api')['token'];
    $nonApiToken = $jwt->createToken($mainUid, 'admin')['token'];
    $legacyToken = $jwt->createToken($legacyUid, 'api')['token'];

    outputJson([
        'uid' => $mainUid,
        'referrer_uid' => $referrerUid,
        'other_referrer_uid' => $otherReferrerUid,
        'legacy_uid' => $legacyUid,
        'referrer_code' => $referrerCode,
        'other_referrer_code' => $otherReferrerCode,
        'primary_tenant_id' => $primary['tenant_id'],
        'primary_channel_id' => $primary['channel_id'],
        'secondary_tenant_id' => $secondary['tenant_id'],
        'secondary_channel_id' => $secondary['channel_id'],
        'token' => $apiToken,
        'non_api_token' => $nonApiToken,
        'legacy_token' => $legacyToken,
    ]);
}

function fixtureInspect(int $uid): void
{
    $members = Db::table('ch_tenant_member')
        ->alias('member')
        ->join(['ch_tenant' => 'tenant'], 'tenant.id = member.tenant_id')
        ->where('member.uid', $uid)
        ->field('tenant.slug AS tenant_slug,member.id,member.tenant_id,member.uid,'
            . 'member.first_channel_id,member.current_channel_id,member.referrer_uid,'
            . 'member.invite_code,member.attribution_locked_time,member.status,member.is_del')
        ->order('member.tenant_id', 'asc')
        ->select()
        ->toArray();
    foreach ($members as &$member) {
        foreach ([
            'id', 'tenant_id', 'uid', 'first_channel_id', 'current_channel_id',
            'referrer_uid', 'attribution_locked_time', 'status', 'is_del',
        ] as $field) {
            $member[$field] = (int) $member[$field];
        }
    }
    unset($member);

    $profiles = Db::table('ch_member_profile')->where('uid', $uid)->select()->toArray();
    $consents = Db::table('ch_member_consent')
        ->where('uid', $uid)
        ->field('tenant_id,member_id,document_code,document_version,content_sha256,decision,ip_hash,user_agent_hash')
        ->order('id', 'asc')
        ->select()
        ->toArray();
    $idempotency = Db::table('ch_idempotency_record')
        ->where('operation', 'bootstrapChamberMember')
        ->whereRaw(
            'JSON_VALID(`result_json`) = 1 AND CAST(JSON_UNQUOTE(JSON_EXTRACT(`result_json`, \'$.principal_id\')) AS UNSIGNED) = ?',
            [$uid]
        )
        ->field('tenant_id,status,COUNT(*) AS records')
        ->group('tenant_id,status')
        ->order('tenant_id', 'asc')
        ->select()
        ->toArray();
    foreach ($idempotency as &$group) {
        $group['tenant_id'] = (int) $group['tenant_id'];
        $group['records'] = (int) $group['records'];
    }
    unset($group);

    outputJson([
        'members' => $members,
        'profile_count' => count($profiles),
        'consents' => $consents,
        'idempotency' => $idempotency,
    ]);
}

function fixtureWithdraw(int $uid): void
{
    $primary = tenantScope('local-primary');
    $updated = Db::table('ch_tenant_member')
        ->where('tenant_id', $primary['tenant_id'])
        ->where('uid', $uid)
        ->where('status', 1)
        ->where('is_del', 0)
        ->update(['status' => 2, 'update_time' => time()]);
    if ($updated !== 1) {
        throw new RuntimeException('Expected one active primary member to withdraw');
    }

    outputJson(['withdrawn' => true]);
}

function fixtureAssetAccess(int $assetId): void
{
    $asset = Db::table('ch_member_asset')
        ->where('id', $assetId)
        ->field('id,used_business_id,last_access_time,update_time')
        ->find();
    if (!is_array($asset)) {
        throw new RuntimeException('Private member asset fixture was not found');
    }
    $auditCount = Db::table('ch_audit_record')
        ->where('action', 'read_asset')
        ->whereRaw(
            'JSON_VALID(`extra_json`) = 1 '
            . 'AND CAST(JSON_UNQUOTE(JSON_EXTRACT(`extra_json`, \'$.asset_id\')) AS UNSIGNED) = ?',
            [$assetId]
        )
        ->count();

    outputJson([
        'asset_id' => (int) $asset['id'],
        'application_id' => (int) $asset['used_business_id'],
        'last_access_time' => (int) $asset['last_access_time'],
        'update_time' => (int) $asset['update_time'],
        'read_audit_count' => (int) $auditCount,
    ]);
}

function fixtureCleanup(array $tokens): void
{
    foreach ($tokens as $token) {
        if (is_string($token) && $token !== '') {
            CacheService::delete(md5($token));
        }
    }
    $uids = testUserIds();
    cleanupUsers($uids);
    outputJson(['cleaned_uids' => $uids]);
}

function fixtureCleanupIdempotency(
    int $tenantId,
    string $operation,
    string $principalType,
    int $principalId,
    string $callerKey
): void {
    $internalKey = BootstrapIdempotency::deriveInternalKey(
        $tenantId,
        $operation,
        $principalType,
        $principalId,
        $callerKey
    );
    $deleted = Db::table('ch_idempotency_record')
        ->where('tenant_id', $tenantId)
        ->where('idempotency_key', $internalKey)
        ->where('operation', $operation)
        ->delete();
    outputJson(['deleted' => (int) $deleted]);
}

function testUserIds(): array
{
    return array_map('intval', Db::table('eb_user')
        ->where('account', 'like', 'g1b\_test\_%')
        ->column('uid'));
}

function cleanupUsers(array $uids): void
{
    foreach ($uids as $uid) {
        Db::table('ch_idempotency_record')
            ->where('operation', 'bootstrapChamberMember')
            ->whereRaw(
                'JSON_VALID(`result_json`) = 1 AND CAST(JSON_UNQUOTE(JSON_EXTRACT(`result_json`, \'$.principal_id\')) AS UNSIGNED) = ?',
                [(int) $uid]
            )
            ->delete();
    }
    if ($uids === []) {
        return;
    }

    $applicationIds = array_map('intval', Db::table('ch_graduate_verification')
        ->whereIn('uid', $uids)
        ->column('id'));
    $assetRows = Db::table('ch_member_asset')
        ->whereIn('uid', $uids)
        ->field('object_key')
        ->select()
        ->toArray();

    if ($applicationIds !== []) {
        Db::table('ch_audit_record')
            ->where('business_type', 'graduate_verification')
            ->whereIn('business_id', $applicationIds)
            ->delete();
    }
    Db::table('ch_graduate_verification')->whereIn('uid', $uids)->delete();
    Db::table('ch_member_asset')->whereIn('uid', $uids)->delete();

    $storage = new LocalPrivateAssetStorage();
    foreach ($assetRows as $assetRow) {
        $objectKey = is_array($assetRow) ? ($assetRow['object_key'] ?? null) : null;
        if (is_string($objectKey) && $objectKey !== '') {
            $storage->delete($objectKey);
        }
    }

    Db::table('ch_member_consent')->whereIn('uid', $uids)->delete();
    Db::table('ch_member_profile')->whereIn('uid', $uids)->delete();
    Db::table('ch_tenant_member')->whereIn('uid', $uids)->delete();
    Db::table('eb_user')->whereIn('uid', $uids)->delete();
}

function createUser(string $account, string $phone): int
{
    return (int) Db::table('eb_user')->insertGetId([
        'account' => $account,
        'nickname' => $account,
        'phone' => $phone,
        'add_time' => time(),
        'status' => 1,
        'user_type' => 'h5',
        'is_del' => 0,
    ]);
}

function tenantScope(string $slug): array
{
    $scope = Db::table('ch_tenant')
        ->alias('tenant')
        ->join(['ch_channel' => 'channel'], 'channel.tenant_id = tenant.id')
        ->where('tenant.slug', $slug)
        ->where('tenant.status', 1)
        ->where('tenant.is_del', 0)
        ->where('channel.code', 'default')
        ->where('channel.status', 1)
        ->where('channel.is_del', 0)
        ->field('tenant.id AS tenant_id,channel.id AS channel_id')
        ->find();
    if (!is_array($scope)) {
        throw new RuntimeException('Local test tenant scope is unavailable: ' . $slug);
    }

    return [
        'tenant_id' => (int) $scope['tenant_id'],
        'channel_id' => (int) $scope['channel_id'],
    ];
}

function createReferrer(array $scope, int $uid, string $inviteCode): void
{
    $now = time();
    Db::table('ch_tenant_member')->insert([
        'tenant_id' => $scope['tenant_id'],
        'uid' => $uid,
        'first_channel_id' => $scope['channel_id'],
        'current_channel_id' => $scope['channel_id'],
        'referrer_uid' => 0,
        'invite_code' => $inviteCode,
        'attribution_locked_time' => $now,
        'tier' => 1,
        'verification_status' => 0,
        'status' => 1,
        'join_time' => $now,
        'add_time' => $now,
        'update_time' => $now,
        'is_del' => 0,
    ]);
}

function createLegacyMember(array $scope, int $uid, int $referrerUid): void
{
    $joinedAt = time() - 86400;
    Db::table('ch_tenant_member')->insert([
        'tenant_id' => $scope['tenant_id'],
        'uid' => $uid,
        'first_channel_id' => $scope['channel_id'],
        'current_channel_id' => $scope['channel_id'],
        'referrer_uid' => $referrerUid,
        'invite_code' => 'LG' . strtoupper(bin2hex(random_bytes(7))),
        'attribution_locked_time' => 0,
        'tier' => 1,
        'verification_status' => 0,
        'status' => 1,
        'join_time' => $joinedAt,
        'add_time' => $joinedAt,
        'update_time' => $joinedAt,
        'is_del' => 0,
    ]);
}

function positiveArgument(array $arguments, int $offset, string $name): int
{
    $value = $arguments[$offset] ?? '';
    if (!is_string($value) || !preg_match('/^[1-9][0-9]*$/', $value)) {
        throw new InvalidArgumentException($name . ' must be a positive integer');
    }

    return (int) $value;
}

function stringArgument(array $arguments, int $offset, string $name): string
{
    $value = $arguments[$offset] ?? null;
    if (!is_string($value) || $value === '') {
        throw new InvalidArgumentException($name . ' must be a non-empty string');
    }

    return $value;
}

function outputJson(array $data): void
{
    $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($json)) {
        throw new RuntimeException('Fixture output could not be encoded');
    }
    fwrite(STDOUT, $json . "\n");
}
