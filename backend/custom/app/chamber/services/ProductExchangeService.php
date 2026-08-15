<?php

declare(strict_types=1);

namespace app\chamber\services;

use app\chamber\exceptions\MemberTransactionException;
use app\chamber\identity\AuthenticatedUserContext;
use app\chamber\tenancy\TenantContext;
use think\facade\Db;

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
            function (int $now) use ($tenant, $auth, $productId, $pointsCost, $cashCost): array {
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
            }
        );
    }

    public function orders(TenantContext $tenant, AuthenticatedUserContext $auth, int $page, int $limit): array
    {
        $member = $this->identity->resolve($tenant, $auth);
        $memberId = (int) $member['id'];
        $tenantId = $tenant->tenantId();

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
