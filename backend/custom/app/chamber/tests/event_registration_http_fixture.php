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
        setupEventFixture();
    } elseif ($action === 'inspect') {
        inspectEventFixture(positiveEventArgument($argv, 2, 'uid'));
    } elseif ($action === 'complete') {
        completeEventPayment(
            positiveEventArgument($argv, 2, 'registration_id'),
            eventPaymentType($argv[3] ?? 'weixin')
        );
    } elseif ($action === 'cleanup') {
        cleanupEventFixture(array_slice($argv, 2));
    } else {
        throw new InvalidArgumentException('Expected setup, inspect, complete, or cleanup action');
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'event HTTP fixture failure: ' . $exception->getMessage() . "\n");
    exit(1);
}

function setupEventFixture(): void
{
    cleanupEventFixture([]);
    $scope = eventTenantScope();
    $run = strtoupper(bin2hex(random_bytes(5)));
    $now = time();
    $account = 'g2_http_' . strtolower($run);
    $uid = (int) Db::table('eb_user')->insertGetId([
        'account' => $account,
        'nickname' => $account,
        'phone' => '137' . substr(hash('sha256', $run), 0, 8),
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
        'invite_code' => 'E2' . substr($run, 0, 14),
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
        'tenant_id' => $scope['tenant_id'],
        'member_id' => $memberId,
        'uid' => $uid,
        'balance' => 100,
        'frozen_balance' => 0,
        'version' => 1,
        'add_time' => $now,
        'update_time' => $now,
    ]);
    $product = Db::table('eb_store_product')->where('spu', 'CHEVENTLOCAL')->find();
    if (!is_array($product)) {
        throw new RuntimeException('Local event product is unavailable');
    }
    $refundable = [
        'mode' => 'full_before_deadline',
        'deadline_time' => $now + 3600,
        'percent' => 100,
        'description' => 'HTTP refundable before local deadline',
    ];
    $types = [
        'free' => ['price' => '0.00', 'points' => 0, 'product_id' => 0, 'sku' => ''],
        'points' => ['price' => '0.00', 'points' => 10, 'product_id' => 0, 'sku' => ''],
        'cash' => [
            'price' => '10.00', 'points' => 0, 'product_id' => (int) $product['id'],
            'sku' => 'cevt0001', 'refund_policy' => $refundable,
        ],
        'mixed' => [
            'price' => '10.00', 'points' => 15, 'product_id' => (int) $product['id'],
            'sku' => 'cevt0001', 'refund_policy' => $refundable,
        ],
    ];
    $fixtures = [];
    foreach ($types as $type => $ticket) {
        $eventId = createFixtureEvent($scope, $run, $type, $now);
        $ticketId = createFixtureTicket($scope['tenant_id'], $eventId, $ticket, $now);
        $fixtures[$type] = ['event_id' => $eventId, 'ticket_id' => $ticketId];
    }
    /** @var JwtAuth $jwt */
    $jwt = app()->make(JwtAuth::class);
    $token = $jwt->createToken($uid, 'api')['token'];
    outputEventJson([
        'token' => $token,
        'uid' => $uid,
        'member_id' => $memberId,
        'product_id' => (int) $product['id'],
        'product_attr_unique' => 'cevt0001',
        'fixtures' => $fixtures,
    ]);
}

function createFixtureEvent(array $scope, string $run, string $type, int $now): int
{
    return (int) Db::table('ch_event')->insertGetId([
        'tenant_id' => $scope['tenant_id'], 'channel_id' => $scope['channel_id'],
        'event_no' => substr('G2HTTP' . $run . strtoupper($type), 0, 32),
        'event_type' => 'growth', 'title' => 'HTTP ' . $type . ' registration',
        'cover_image' => '', 'summary' => 'HTTP acceptance fixture', 'detail' => '',
        'tags_json' => '[]', 'speakers_json' => '[]',
        'start_time' => $now + 7200, 'end_time' => $now + 10800,
        'signup_start_time' => $now - 3600, 'signup_end_time' => $now + 3600,
        'location_name' => 'Local', 'address' => 'Local',
        'longitude' => '123.000000', 'latitude' => '41.000000',
        'min_tier' => 2, 'eligibility_json' => '{}', 'refund_policy_json' => '{}',
        'checkin_reward_points' => 0, 'checkin_reward_contribution' => 0,
        'status' => 1, 'created_admin_id' => 0, 'publish_time' => $now,
        'add_time' => $now, 'update_time' => $now, 'is_del' => 0,
    ]);
}

function createFixtureTicket(int $tenantId, int $eventId, array $ticket, int $now): int
{
    return (int) Db::table('ch_event_ticket')->insertGetId([
        'tenant_id' => $tenantId, 'event_id' => $eventId, 'name' => 'HTTP fixture ticket',
        'price' => $ticket['price'], 'integral_price' => $ticket['points'],
        'product_id' => $ticket['product_id'], 'product_attr_unique' => $ticket['sku'],
        'capacity' => 10, 'reserved_count' => 0, 'paid_count' => 0, 'min_tier' => 2,
        'eligibility_json' => '{}',
        'refund_policy_json' => json_encode($ticket['refund_policy'] ?? [], JSON_UNESCAPED_SLASHES) ?: '{}',
        'sale_start_time' => $now - 3600, 'sale_end_time' => $now + 3600,
        'status' => 1, 'sort' => 1, 'add_time' => $now, 'update_time' => $now, 'is_del' => 0,
    ]);
}

function completeEventPayment(int $registrationId, string $payType): void
{
    $registration = Db::table('ch_event_registration')->where('id', $registrationId)->find();
    if (!is_array($registration) || (int) $registration['order_pk'] <= 0) {
        throw new RuntimeException('Pending event registration is unavailable');
    }
    $order = Db::table('eb_store_order')->where('id', (int) $registration['order_pk'])->find();
    $result = app()->make('app\\services\\order\\StoreOrderSuccessServices')->paySuccess(
        $order,
        $payType,
        $payType === 'weixin' ? ['trade_no' => 'event-http-' . (int) $order['id']] : []
    );
    outputEventJson([
        'result' => (bool) $result,
        'registration_id' => $registrationId,
        'status' => (int) Db::table('ch_event_registration')->where('id', $registrationId)->value('status'),
        'pay_status' => (int) Db::table('ch_order_context')->where('id', (int) $registration['order_context_id'])->value('pay_status'),
    ]);
}

function inspectEventFixture(int $uid): void
{
    $member = Db::table('ch_tenant_member')->where('uid', $uid)->where('is_del', 0)->find();
    $account = is_array($member) ? Db::table('ch_point_account')->where('member_id', (int) $member['id'])->find() : null;
    outputEventJson([
        'registration_count' => (int) Db::table('ch_event_registration')->where('uid', $uid)->count(),
        'context_count' => (int) Db::table('ch_order_context')->where('uid', $uid)->where('business_type', 'event_registration')->count(),
        'order_count' => (int) Db::table('eb_store_order')->where('uid', $uid)->count(),
        'balance' => (int) ($account['balance'] ?? -1),
        'frozen_balance' => (int) ($account['frozen_balance'] ?? -1),
        'held_count' => (int) Db::table('ch_point_hold')->where('uid', $uid)->where('status', 1)->count(),
        'captured_count' => (int) Db::table('ch_point_hold')->where('uid', $uid)->where('status', 2)->count(),
        'event_ledger_count' => (int) Db::table('ch_point_ledger')->where('uid', $uid)->where('source_type', 'event_registration')->count(),
        'registrations' => Db::table('ch_event_registration')->where('uid', $uid)
            ->field('id,event_id,ticket_id,status,order_pk,order_no,amount,integral_amount')->order('id', 'asc')->select()->toArray(),
    ]);
}

function cleanupEventFixture(array $tokens): void
{
    foreach ($tokens as $token) {
        if (is_string($token) && $token !== '') {
            CacheService::delete(md5($token));
        }
    }
    $uids = array_map('intval', Db::table('eb_user')->whereLike('account', 'g2\_http\_%')->column('uid'));
    $contexts = $uids === [] ? [] : Db::table('ch_order_context')->whereIn('uid', $uids)
        ->where('business_type', 'event_registration')->select()->toArray();
    $contextIds = array_map('intval', array_column($contexts, 'id'));
    $recordIds = array_values(array_filter(array_map('intval', array_column($contexts, 'idempotency_record_id'))));
    $registrationIds = $uids === [] ? [] : array_map(
        'intval',
        Db::table('ch_event_registration')->whereIn('uid', $uids)->column('id')
    );
    $refundAttemptRows = [];
    if ($registrationIds !== [] && eventTableExists('ch_refund_attempt')) {
        $refundAttemptRows = Db::table('ch_refund_attempt')
            ->where('source_type', 'event_registration')
            ->whereIn('source_id', array_map('strval', $registrationIds))
            ->field('id,idempotency_record_id')
            ->select()
            ->toArray();
    }
    $refundAttemptIds = array_values(array_filter(array_map('intval', array_column($refundAttemptRows, 'id'))));
    $recordIds = array_merge(
        $recordIds,
        array_values(array_filter(array_map('intval', array_column($refundAttemptRows, 'idempotency_record_id'))))
    );
    if ($uids !== []) {
        $idempotencyRows = Db::table('ch_idempotency_record')
            ->whereIn('operation', ['createEventRegistration', 'createEventRegistrationRefund'])
            ->field('id,result_json')->select()->toArray();
        foreach ($idempotencyRows as $row) {
            $result = json_decode((string) ($row['result_json'] ?? ''), true);
            if (is_array($result) && in_array((int) ($result['principal_id'] ?? 0), $uids, true)) {
                $recordIds[] = (int) $row['id'];
            }
        }
        $recordIds = array_values(array_unique(array_filter($recordIds)));
    }
    $orderIds = array_values(array_filter(array_map('intval', array_column($contexts, 'order_pk'))));
    if ($contextIds !== []) {
        Db::table('ch_commerce_event_inbox')->whereIn('context_id', $contextIds)->delete();
    }
    if ($refundAttemptIds !== [] && eventTableExists('ch_refund_attempt_audit')) {
        Db::table('ch_refund_attempt_audit')->whereIn('refund_attempt_id', $refundAttemptIds)->delete();
    }
    if ($refundAttemptIds !== [] && eventTableExists('ch_refund_attempt')) {
        Db::table('ch_refund_attempt')->whereIn('id', $refundAttemptIds)->delete();
    }
    if ($orderIds !== []) {
        foreach (['eb_store_order_status', 'eb_store_order_cart_info', 'eb_store_order_economize'] as $table) {
            try {
                Db::table($table)->whereIn($table === 'eb_store_order_economize' ? 'order_id' : 'oid', $orderIds)->delete();
            } catch (Throwable $ignored) {
            }
        }
        Db::table('eb_store_order')->whereIn('id', $orderIds)->delete();
    }
    if ($uids !== []) {
        Db::table('eb_store_cart')->whereIn('uid', $uids)->delete();
        Db::table('ch_point_ledger')->whereIn('uid', $uids)->where('source_type', 'event_registration')->delete();
        Db::table('ch_point_hold')->whereIn('uid', $uids)->delete();
        Db::table('ch_event_registration')->whereIn('uid', $uids)->delete();
        Db::table('ch_order_context')->whereIn('uid', $uids)->where('business_type', 'event_registration')->delete();
        Db::table('ch_point_account')->whereIn('uid', $uids)->delete();
        Db::table('ch_member_profile')->whereIn('uid', $uids)->delete();
        Db::table('ch_tenant_member')->whereIn('uid', $uids)->delete();
        Db::table('eb_user')->whereIn('uid', $uids)->delete();
    }
    if ($recordIds !== []) {
        Db::table('ch_idempotency_record')->whereIn('id', $recordIds)->delete();
    }
    $eventIds = array_map('intval', Db::table('ch_event')->whereLike('event_no', 'G2HTTP%')->column('id'));
    if ($eventIds !== []) {
        Db::table('ch_event_ticket')->whereIn('event_id', $eventIds)->delete();
        Db::table('ch_event')->whereIn('id', $eventIds)->delete();
    }
    $product = Db::table('eb_store_product')->where('spu', 'CHEVENTLOCAL')->find();
    if (is_array($product)) {
        Db::table('eb_store_product')->where('id', (int) $product['id'])->update(['stock' => 999999, 'sales' => 0]);
        Db::table('eb_store_product_attr_value')->where('product_id', (int) $product['id'])
            ->where('unique', 'cevt0001')->update(['stock' => 999999, 'sales' => 0]);
    }
}

function eventPaymentType(string $value): string
{
    if (!in_array($value, ['weixin', 'yue'], true)) {
        throw new InvalidArgumentException('payment type must be weixin or yue');
    }

    return $value;
}

function eventTableExists(string $table): bool
{
    $schema = (string) (Db::query('SELECT DATABASE() AS db_name')[0]['db_name'] ?? '');
    if ($schema === '') {
        return false;
    }
    $rows = Db::query(
        "SELECT COUNT(*) AS c FROM information_schema.TABLES WHERE TABLE_SCHEMA = '" .
        addslashes($schema) . "' AND TABLE_NAME = '" . addslashes($table) . "'"
    );
    return (int) ($rows[0]['c'] ?? 0) > 0;
}

function eventTenantScope(): array
{
    $row = Db::table('ch_tenant')->alias('tenant')
        ->join(['ch_channel' => 'channel'], 'channel.tenant_id=tenant.id')
        ->where('tenant.slug', 'local-primary')->where('channel.code', 'default')
        ->where('tenant.status', 1)->where('tenant.is_del', 0)
        ->where('channel.status', 1)->where('channel.is_del', 0)
        ->field('tenant.id AS tenant_id,channel.id AS channel_id')->find();
    if (!is_array($row)) {
        throw new RuntimeException('Local event tenant scope is unavailable');
    }
    return ['tenant_id' => (int) $row['tenant_id'], 'channel_id' => (int) $row['channel_id']];
}

function positiveEventArgument(array $arguments, int $offset, string $field): int
{
    $value = $arguments[$offset] ?? '';
    if (!is_string($value) || preg_match('/^[1-9][0-9]*$/D', $value) !== 1) {
        throw new InvalidArgumentException($field . ' must be a positive integer');
    }
    return (int) $value;
}

function outputEventJson(array $value): void
{
    $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($json)) {
        throw new RuntimeException('Fixture output could not be encoded');
    }
    fwrite(STDOUT, $json . "\n");
}
