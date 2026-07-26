<?php

declare(strict_types=1);

use app\chamber\membership\MembershipCheckoutIdempotency;
use crmeb\services\CacheService;
use crmeb\utils\JwtAuth;
use think\App;
use think\facade\Db;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

(new App())->initialize();

try {
    $action = $argv[1] ?? '';
    if ($action === 'setup') {
        setupFixture();
    } elseif ($action === 'inspect') {
        inspectFixture(positiveArgument($argv, 2, 'uid'));
    } elseif ($action === 'make-repairable') {
        makeRepairable(positiveArgument($argv, 2, 'uid'));
    } elseif ($action === 'seed-cart') {
        seedCart(positiveArgument($argv, 2, 'uid'));
    } elseif ($action === 'cleanup') {
        cleanupFixture(array_slice($argv, 2));
    } else {
        throw new InvalidArgumentException('Expected setup, inspect, make-repairable, seed-cart, or cleanup action');
    }
} catch (Throwable $exception) {
    fwrite(STDERR, sprintf("membership checkout fixture failure: %s\n", $exception->getMessage()));
    exit(1);
}

function setupFixture(): void
{
    cleanupFixture([]);
    $scope = tenantScope('local-primary');
    $run = bin2hex(random_bytes(5));
    $account = 'g1d_http_' . $run;
    $now = time();
    $uid = (int) Db::table('eb_user')->insertGetId([
        'account' => $account,
        'nickname' => $account,
        'phone' => '138' . substr(hash('sha256', $run), 0, 8),
        'add_time' => $now,
        'status' => 1,
        'user_type' => 'h5',
        'is_del' => 0,
    ]);
    if ($uid <= 0) {
        throw new RuntimeException('CRMEB checkout test user was not created');
    }
    $memberId = (int) Db::table('ch_tenant_member')->insertGetId([
        'tenant_id' => $scope['tenant_id'],
        'uid' => $uid,
        'first_channel_id' => $scope['channel_id'],
        'current_channel_id' => $scope['channel_id'],
        'referrer_uid' => 0,
        'invite_code' => 'D1' . strtoupper(bin2hex(random_bytes(7))),
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
    if ($memberId <= 0) {
        throw new RuntimeException('Checkout test member was not created');
    }

    /** @var JwtAuth $jwt */
    $jwt = app()->make(JwtAuth::class);
    $token = $jwt->createToken($uid, 'api')['token'];
    $plan = Db::table('ch_membership_plan')
        ->where('tenant_id', $scope['tenant_id'])
        ->where('channel_id', $scope['channel_id'])
        ->where('plan_code', 'L3_ANNUAL')
        ->find();
    if (!is_array($plan)) {
        throw new RuntimeException('Local L3 membership plan is unavailable');
    }
    outputJson([
        'uid' => $uid,
        'member_id' => $memberId,
        'tenant_id' => $scope['tenant_id'],
        'channel_id' => $scope['channel_id'],
        'product_id' => (int) $plan['product_id'],
        'product_attr_unique' => (string) $plan['product_attr_unique'],
        'token' => $token,
    ]);
}

function inspectFixture(int $uid): void
{
    $contexts = Db::table('ch_order_context')
        ->where('uid', $uid)
        ->where('business_type', 'membership')
        ->field('id,tenant_id,channel_id,member_id,uid,context_no,idempotency_record_id,order_pk,'
            . 'order_no,business_id,currency,list_amount,payable_amount,pay_status,completion_kind')
        ->order('id', 'asc')
        ->select()
        ->toArray();
    foreach ($contexts as &$context) {
        foreach (['id', 'tenant_id', 'channel_id', 'member_id', 'uid', 'idempotency_record_id', 'order_pk', 'business_id', 'pay_status'] as $field) {
            $context[$field] = (int) $context[$field];
        }
    }
    unset($context);

    $orders = Db::table('eb_store_order')
        ->where('uid', $uid)
        ->field('id,uid,order_id,unique,pay_type,total_num,total_price,pay_price,coupon_price,'
            . 'deduction_price,use_integral,gain_integral,one_brokerage,two_brokerage,staff_brokerage,'
            . 'agent_brokerage,division_brokerage,paid,status,refund_status,is_cancel,is_del,virtual_type,custom_form')
        ->order('id', 'asc')
        ->select()
        ->toArray();
    foreach ($orders as &$order) {
        foreach (['id', 'uid', 'total_num', 'use_integral', 'gain_integral', 'paid', 'status', 'refund_status', 'is_cancel', 'is_del', 'virtual_type'] as $field) {
            $order[$field] = (int) $order[$field];
        }
        $order['cart_rows'] = (int) Db::table('eb_store_order_cart_info')
            ->where('oid', $order['id'])
            ->count();
        $cart = Db::table('eb_store_order_cart_info')
            ->where('oid', $order['id'])
            ->field('cart_id,unique')
            ->order('id', 'asc')
            ->find();
        $order['cart_id'] = (int) ($cart['cart_id'] ?? 0);
        $order['cart_unique'] = (string) ($cart['unique'] ?? '');
    }
    unset($order);

    outputJson([
        'context_count' => count($contexts),
        'order_count' => count($orders),
        'idempotency_count' => (int) Db::table('ch_idempotency_record')
            ->where('operation', MembershipCheckoutIdempotency::OPERATION)
            ->whereIn('id', array_map('intval', array_column($contexts, 'idempotency_record_id')) ?: [0])
            ->count(),
        'records' => Db::table('ch_idempotency_record')
            ->whereIn('id', array_map('intval', array_column($contexts, 'idempotency_record_id')) ?: [0])
            ->field('id,status,attempt_count,update_time')
            ->order('id', 'asc')
            ->select()
            ->toArray(),
        'contexts' => $contexts,
        'orders' => $orders,
    ]);
}

function makeRepairable(int $uid): void
{
    $context = Db::table('ch_order_context')
        ->where('uid', $uid)
        ->where('business_type', 'membership')
        ->whereNotNull('order_pk')
        ->order('id', 'asc')
        ->find();
    if (!is_array($context)) {
        throw new RuntimeException('No bound membership context is available for repair simulation');
    }
    $now = time();
    Db::transaction(function () use ($context, $now): void {
        Db::table('ch_order_context')
            ->where('id', (int) $context['id'])
            ->update([
                'order_pk' => null,
                'order_no' => null,
                'pay_status' => 0,
                'update_time' => $now - 120,
            ]);
        Db::table('ch_idempotency_record')
            ->where('id', (int) $context['idempotency_record_id'])
            ->update([
                'status' => 'unknown',
                'lease_token' => '',
                'lease_expire_time' => 0,
                'result_http_status' => 0,
                'result_code' => '',
                'result_hash' => '',
                'result_json' => null,
                'completed_time' => 0,
                'update_time' => $now - 120,
            ]);
    });
    outputJson([
        'context_id' => (int) $context['id'],
        'idempotency_record_id' => (int) $context['idempotency_record_id'],
        'context_no' => (string) $context['context_no'],
    ]);
}

function seedCart(int $uid): void
{
    $plan = Db::table('ch_membership_plan')
        ->where('plan_code', 'L3_ANNUAL')
        ->find();
    if (!is_array($plan)) {
        throw new RuntimeException('Local L3 membership plan is unavailable');
    }
    $cartId = (int) Db::table('eb_store_cart')->insertGetId([
        'uid' => $uid,
        'type' => '0',
        'product_id' => (int) $plan['product_id'],
        'product_attr_unique' => (string) $plan['product_attr_unique'],
        'cart_num' => 1,
        'add_time' => time(),
        'is_pay' => 0,
        'is_del' => 0,
        'is_new' => 0,
        'combination_id' => 0,
        'seckill_id' => 0,
        'bargain_id' => 0,
        'advance_id' => 0,
        'status' => 1,
    ]);
    if ($cartId <= 0) {
        throw new RuntimeException('Membership cart fixture was not created');
    }
    outputJson(['cart_id' => $cartId]);
}

function cleanupFixture(array $tokens): void
{
    foreach ($tokens as $token) {
        if (is_string($token) && $token !== '') {
            CacheService::delete(md5($token));
        }
    }
    $uids = array_map('intval', Db::table('eb_user')
        ->whereLike('account', 'g1d\_http\_%')
        ->column('uid'));
    $fixtureProductIds = array_map('intval', Db::table('eb_store_product')
        ->whereIn('spu', ['CHMEML3LOCAL', 'CHMEML4LOCAL'])
        ->column('id'));
    $fixtureOrderIds = $fixtureProductIds === [] ? [] : array_map('intval', Db::table('eb_store_order_cart_info')
        ->whereIn('product_id', $fixtureProductIds)
        ->column('oid'));
    $contexts = Db::table('ch_order_context')
        ->where('business_type', 'membership')
        ->where(function ($query) use ($uids, $fixtureOrderIds): void {
            if ($uids !== []) {
                $query->whereIn('uid', $uids);
            }
            if ($fixtureOrderIds !== []) {
                $query->whereOr('order_pk', 'in', $fixtureOrderIds);
            }
        })
        ->field('id,uid,idempotency_record_id,order_pk')
        ->select()
        ->toArray();
    $uids = array_values(array_unique(array_merge(
        $uids,
        array_map('intval', array_column($contexts, 'uid'))
    )));
    $orderIds = array_values(array_unique(array_filter(array_merge(
        $fixtureOrderIds,
        array_map('intval', array_column($contexts, 'order_pk'))
    ))));
    if ($uids !== []) {
        $orderIds = array_values(array_unique(array_merge(
            $orderIds,
            array_map('intval', Db::table('eb_store_order')->whereIn('uid', $uids)->column('id'))
        )));
    }
    if ($orderIds !== []) {
        foreach (['eb_store_order_status', 'eb_store_order_cart_info', 'eb_store_order_economize'] as $table) {
            $field = $table === 'eb_store_order_economize' ? 'order_id' : 'oid';
            try {
                Db::table($table)->whereIn($field, $orderIds)->delete();
            } catch (Throwable $ignored) {
                // Optional upstream side tables vary by CRMEB patch version.
            }
        }
        Db::table('eb_store_order')->whereIn('id', $orderIds)->delete();
    }
    // Cart rows are not foreign-keyed in the upstream schema. Remove fixture
    // carts explicitly so a failed HTTP run cannot poison the next migration
    // verifier or native-cart test.
    Db::table('eb_store_cart')->whereIn('uid', $uids)->delete();
    $recordIds = array_values(array_filter(array_map(
        'intval',
        array_column($contexts, 'idempotency_record_id')
    )));
    Db::table('ch_order_context')->whereIn('uid', $uids)->where('business_type', 'membership')->delete();
    if ($recordIds !== []) {
        Db::table('ch_idempotency_record')->whereIn('id', $recordIds)->delete();
    }
    Db::table('ch_member_profile')->whereIn('uid', $uids)->delete();
    Db::table('ch_tenant_member')->whereIn('uid', $uids)->delete();
    Db::table('eb_user')->whereIn('uid', $uids)->delete();

    Db::table('eb_store_product')->whereIn('spu', ['CHMEML3LOCAL', 'CHMEML4LOCAL'])->update([
        'stock' => 999999,
        'sales' => 0,
    ]);
    $productIds = array_map('intval', Db::table('eb_store_product')
        ->whereIn('spu', ['CHMEML3LOCAL', 'CHMEML4LOCAL'])
        ->column('id'));
    if ($productIds !== []) {
        Db::table('eb_store_product_attr_value')->whereIn('product_id', $productIds)->update([
            'stock' => 999999,
            'sales' => 0,
        ]);
    }
}

function tenantScope(string $slug): array
{
    $row = Db::table('ch_tenant')
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
    if (!is_array($row)) {
        throw new RuntimeException('Local checkout tenant scope is unavailable');
    }

    return ['tenant_id' => (int) $row['tenant_id'], 'channel_id' => (int) $row['channel_id']];
}

function positiveArgument(array $arguments, int $offset, string $field): int
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
