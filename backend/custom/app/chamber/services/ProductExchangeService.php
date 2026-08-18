<?php

declare(strict_types=1);

namespace app\chamber\services;

use app\chamber\exceptions\MemberTransactionException;
use app\chamber\identity\AuthenticatedUserContext;
use app\chamber\tenancy\TenantContext;
use think\facade\Db;
use Throwable;

/**
 * Owns points-based product exchange: balance check -> point deduction ->
 * append-only ch_point_ledger entry -> ch_product_exchange_order, all inside a
 * single tenant-scoped idempotent transaction.
 *
 * 定价数据源：CRMEB 商城商品 eb_store_product（与 H5 商城一致）：
 * - 所需积分 = integral_price（商品页「积分抵扣」配置的积分价）
 * - 积分可抵现金 = 所需积分 × integral_ratio（CRMEB 配置，默认 0.1 元/积分）
 * - 补现金 = 商品现金价 - 积分可抵现金（不足抵扣时为 0）
 */
final class ProductExchangeService
{
    /** 含现金补差订单的支付超时（秒）：超时未支付自动取消并退积分 */
    public const EXCHANGE_PAY_TIMEOUT = 1800;
    /** @var MemberIdentityService */
    private $identity;

    /** @var EventIdempotency */
    private $idempotency;

    public function __construct(MemberIdentityService $identity, EventIdempotency $idempotency = null)
    {
        $this->identity = $identity;
        $this->idempotency = $idempotency ?: new EventIdempotency();
    }

    public function exchange(
        TenantContext $tenant,
        AuthenticatedUserContext $auth,
        int $productId,
        int $pointsCost,
        string $cashCost,
        string $callerKey
    ): array {
        if ($productId <= 0) {
            throw new MemberTransactionException(422, 'request_validation_failed', 'product_id must be a positive integer');
        }
        if ($pointsCost <= 0) {
            throw new MemberTransactionException(422, 'request_validation_failed', 'points_cost must be a positive integer');
        }

        return $this->idempotency->execute(
            $tenant,
            'createProductExchange',
            'crmeb_user',
            $auth->uid(),
            $callerKey,
            ['product_id' => $productId, 'points_cost' => $pointsCost, 'cash_cost' => $cashCost],
            200,
            function (int $now) use ($tenant, $auth, $productId, $pointsCost, $cashCost, $callerKey): array {
                $member = $this->identity->resolve($tenant, $auth, true);
                $memberId = (int) $member['id'];

                $product = Db::table('eb_store_product')
                    ->where('id', $productId)
                    ->where('is_del', 0)
                    ->where('is_show', 1)
                    ->find();
                if (!is_array($product)) {
                    throw new MemberTransactionException(404, 'product_not_found', 'Product was not found');
                }

                // 服务端校验兑换价（防客户端自定价）：
                // - 所需积分 = integral_price（商品积分价）
                // - 支持「积分不足补现金」：points_cost 可 < 所需积分，缺失积分按汇率折算成现金补齐
                //   cash_cost = 基础补现金 + 缺失积分 × integral_ratio
                $price = (float) ($product['price'] ?? 0);
                $expectedPoints = (int) ($product['integral_price'] ?? 0);
                $ratio = $this->integralRatio();
                $baseCash = max(0, $price - $expectedPoints * $ratio); // 全积分支付时的补现金
                if ($expectedPoints <= 0) {
                    throw new MemberTransactionException(404, 'exchange_unavailable', 'Product does not support point exchange');
                }
                if ($pointsCost < 1 || $pointsCost > $expectedPoints) {
                    throw new MemberTransactionException(409, 'price_mismatch', 'Exchange price mismatch, please refresh and retry');
                }
                $missingPoints = $expectedPoints - $pointsCost;
                $expectedCashNow = $baseCash + $missingPoints * $ratio; // 积分不足时现金补齐
                $sentCash = (float) ($cashCost === '' ? '0.00' : $cashCost);
                if (abs($sentCash - $expectedCashNow) > 0.011) {
                    throw new MemberTransactionException(409, 'price_mismatch', 'Exchange price mismatch, please refresh and retry');
                }

                $account = $this->identity->pointsAccount($tenant->tenantId(), $member, true);
                $balance = (int) $account['balance'];
                if ($balance < $pointsCost) {
                    throw new MemberTransactionException(409, 'insufficient_points', 'Member points are insufficient');
                }

                $newBalance = $balance - $pointsCost;
                $updated = Db::table('ch_point_account')
                    ->where('id', (int) $account['id'])
                    ->where('tenant_id', $tenant->tenantId())
                    ->where('version', (int) $account['version'])
                    ->update([
                        'balance' => $newBalance,
                        'version' => (int) $account['version'] + 1,
                        'update_time' => $now,
                    ]);
                if ($updated !== 1) {
                    throw new MemberTransactionException(409, 'exchange_failed', 'Points balance could not be updated');
                }

                // 状态机（S5/P0 修复）：纯积分兑换（无现金补差）→ 立即 paid；
                // 含现金补差 → pending，须支付事实确认后才 paid（防财务假账）
                $finalCash = $cashCost === '' ? '0.00' : $cashCost;
                $orderStatus = ((float) $finalCash > 0) ? 'pending' : 'paid';

                $orderId = (int) Db::table('ch_product_exchange_order')->insertGetId([
                    'tenant_id' => $tenant->tenantId(),
                    'member_id' => $memberId,
                    'product_id' => $productId,
                    'points_cost' => $pointsCost,
                    'cash_cost' => $finalCash,
                    'status' => $orderStatus,
                    'idempotency_key' => $callerKey,
                    'created_at' => $now,
                ]);
                if ($orderId <= 0) {
                    throw new MemberTransactionException(409, 'exchange_failed', 'Exchange order could not be created');
                }

                Db::table('ch_point_ledger')->insert([
                    'tenant_id' => $tenant->tenantId(),
                    'account_id' => (int) $account['id'],
                    'member_id' => $memberId,
                    'uid' => (int) $member['uid'],
                    'delta' => -1 * $pointsCost,
                    'balance_after' => $newBalance,
                    'source_type' => 'product_exchange',
                    'source_id' => (string) $orderId,
                    'idempotency_key' => hash('sha256', 'product_exchange:' . $callerKey . ':points'),
                    'status' => 1,
                    'reversal_id' => 0,
                    'add_time' => $now,
                ]);

                return $this->orderPayload($orderId, $product, $pointsCost, $cashCost, $now, $orderStatus);
            },
            function () use ($tenant, $auth): void {
                $this->identity->resolve($tenant, $auth, false);
            }
        );
    }

    public function orders(TenantContext $tenant, AuthenticatedUserContext $auth, int $page, int $limit): array
    {
        $member = $this->identity->resolve($tenant, $auth);
        $memberId = (int) $member['id'];
        $tenantId = $tenant->tenantId();
        $now = time();

        // lazy 超时释放：含现金补差的 pending 订单超过 30 分钟未支付 → 自动取消并退积分
        // （修复：此前扣积分后支付取消/放弃，积分永久滞留无释放路径）
        $expired = Db::table('ch_product_exchange_order')
            ->where('tenant_id', $tenantId)
            ->where('member_id', $memberId)
            ->where('status', 'pending')
            ->where('cash_cost', '>', 0)
            ->where('created_at', '<', $now - self::EXCHANGE_PAY_TIMEOUT)
            ->column('id');
        foreach ($expired as $expiredId) {
            try {
                $this->cancelById($tenantId, $memberId, (int) $expiredId, true);
            } catch (Throwable $e) {
                // 并发取消/已支付：忽略，下次扫描处理
            }
        }

        $query = Db::table('ch_product_exchange_order')
            ->where('tenant_id', $tenantId)
            ->where('member_id', $memberId)
            ->order('id', 'desc');

        $total = (clone $query)->count();
        $rows = $query->page($page, $limit)->select()->toArray();

        $items = [];
        foreach ($rows as $row) {
            $product = Db::table('eb_store_product')
                ->where('id', (int) $row['product_id'])
                ->find();
            $items[] = [
                'id' => (int) $row['id'],
                'product_id' => (int) $row['product_id'],
                'product_name' => is_array($product) ? (string) $product['store_name'] : '',
                'product_image' => is_array($product) ? (string) $product['image'] : '',
                'points_cost' => (int) $row['points_cost'],
                'cash_cost' => (string) $row['cash_cost'],
                'status' => (string) $row['status'],
                'created_at' => (int) $row['created_at'],
            ];
        }

        return [
            'items' => $items,
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
        ];
    }

    /** 取消未支付的兑换订单（含现金补差）：退积分 + 关支付单。幂等：已取消返回原结果。 */
    public function cancel(TenantContext $tenant, AuthenticatedUserContext $auth, int $orderId): array
    {
        $member = $this->identity->resolve($tenant, $auth);
        $memberId = (int) $member['id'];

        return $this->cancelById($tenant->tenantId(), $memberId, $orderId, false);
    }

    /** @param bool $autoExpire true=lazy 超时释放（静默失败，日志不抛） */
    private function cancelById(int $tenantId, int $memberId, int $orderId, bool $autoExpire): array
    {
        return Db::transaction(function () use ($tenantId, $memberId, $orderId, $autoExpire): array {
            $order = Db::table('ch_product_exchange_order')
                ->where('tenant_id', $tenantId)
                ->where('member_id', $memberId)
                ->where('id', $orderId)
                ->lock(true)
                ->find();
            if (!is_array($order)) {
                throw new MemberTransactionException(404, 'exchange_order_not_found', '兑换订单不存在');
            }
            if ((string) $order['status'] === 'cancelled') {
                return ['id' => $orderId, 'status' => 'cancelled', 'points_refunded' => 0, 'replayed' => true];
            }
            if ((string) $order['status'] !== 'pending') {
                throw new MemberTransactionException(409, 'exchange_order_not_cancellable', '当前订单状态不允许取消');
            }
            $pointsCost = (int) $order['points_cost'];
            $now = time();

            // 1. 原扣减账本（product_exchange, source_id=orderId）——不存在则视为 0 积分（异常防御）
            $deduction = Db::table('ch_point_ledger')
                ->where('tenant_id', $tenantId)
                ->where('member_id', $memberId)
                ->where('source_type', 'product_exchange')
                ->where('source_id', (string) $orderId)
                ->where('status', 1)
                ->find();

            // 2. 积分回补（乐观锁）
            $account = $this->identity->pointsAccount($tenantId, ['id' => $memberId, 'uid' => $order['uid'] ?? 0], true);
            $refund = is_array($deduction) ? (int) $deduction['delta'] * -1 : $pointsCost;
            if ($refund > 0) {
                $balance = (int) $account['balance'];
                $newBalance = $balance + $refund;
                $updated = Db::table('ch_point_account')
                    ->where('id', (int) $account['id'])
                    ->where('tenant_id', $tenantId)
                    ->where('version', (int) $account['version'])
                    ->update([
                        'balance' => $newBalance,
                        'version' => (int) $account['version'] + 1,
                        'update_time' => $now,
                    ]);
                if ($updated !== 1) {
                    throw new MemberTransactionException(409, 'points_conflict', '积分账户已变动，请重试');
                }
                Db::table('ch_point_ledger')->insert([
                    'tenant_id' => $tenantId,
                    'account_id' => (int) $account['id'],
                    'member_id' => $memberId,
                    'uid' => (int) ($order['uid'] ?? 0),
                    'delta' => $refund,
                    'balance_after' => $newBalance,
                    'source_type' => 'product_exchange_cancel',
                    'source_id' => (string) $orderId,
                    'idempotency_key' => hash('sha256', 'product_exchange_cancel:' . $tenantId . ':' . $orderId . ':points'),
                    'status' => 1,
                    'reversal_id' => is_array($deduction) ? (int) $deduction['id'] : 0,
                    'add_time' => $now,
                ]);
            }

            // 3. 订单置 cancelled + 关闭关联微信支付单（未支付挂起单）
            Db::table('ch_product_exchange_order')
                ->where('id', $orderId)
                ->update(['status' => 'cancelled']);
            Db::table('ch_wechat_pay_order')
                ->where('business_type', 'exchange')
                ->where('business_ref', $orderId)
                ->where('status', 'pending')
                ->update(['status' => 'closed', 'update_time' => $now]);

            return ['id' => $orderId, 'status' => 'cancelled', 'points_refunded' => $refund];
        });
    }

    private function orderPayload(int $orderId, array $product, int $pointsCost, string $cashCost, int $now, string $status = 'paid'): array
    {
        return [
            'id' => $orderId,
            'product_id' => (int) $product['id'],
            'product_name' => (string) ($product['store_name'] ?? ''),
            'product_image' => (string) ($product['image'] ?? ''),
            'points_cost' => $pointsCost,
            'cash_cost' => $cashCost === '' ? '0.00' : $cashCost,
            'status' => $status,
            'created_at' => $now,
        ];
    }

    /** CRMEB 积分汇率：1 积分抵多少元（integral_ratio 配置，默认 0.1） */
    private function integralRatio(): float
    {
        try {
            $raw = Db::table('eb_system_config')
                ->where('menu_name', 'integral_ratio')
                ->value('value');
            if ($raw !== null && $raw !== '') {
                $decoded = json_decode((string) $raw, true);
                $ratio = (float) (is_array($decoded) ? ($decoded['integral_ratio'] ?? $decoded) : $decoded);
                if ($ratio > 0) {
                    return $ratio;
                }
            }
        } catch (\Throwable $e) {
            // 配置读取失败时退回默认汇率
        }

        return 0.1;
    }
}
