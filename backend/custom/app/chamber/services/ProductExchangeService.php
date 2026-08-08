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

                $orderId = (int) Db::table('ch_product_exchange_order')->insertGetId([
                    'tenant_id' => $tenant->tenantId(),
                    'member_id' => $memberId,
                    'product_id' => $productId,
                    'points_cost' => $pointsCost,
                    'cash_cost' => $cashCost === '' ? '0.00' : $cashCost,
                    'status' => 'paid',
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

                return $this->orderPayload($orderId, $product, $pointsCost, $cashCost, $now);
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

    private function orderPayload(int $orderId, array $product, int $pointsCost, string $cashCost, int $now): array
    {
        return [
            'id' => $orderId,
            'product_id' => (int) $product['id'],
            'product_name' => (string) ($product['store_name'] ?? ''),
            'product_image' => (string) ($product['image'] ?? ''),
            'points_cost' => $pointsCost,
            'cash_cost' => $cashCost === '' ? '0.00' : $cashCost,
            'status' => 'paid',
            'created_at' => $now,
        ];
    }
}
