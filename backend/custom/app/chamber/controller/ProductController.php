<?php

namespace app\chamber\controller;

use app\Request;
use app\chamber\tenancy\TenantContext;
use think\facade\Db;
use think\Response;

/**
 * 积分商城（小程序端）：商品列表
 * 路径：GET /api/chamber/v1/products
 * 数据源：ch_exchange_product（积分兑换商品配置） JOIN eb_store_product（CRMEB 商城商品）
 */
final class ProductController
{
    public function index(Request $request, TenantContext $tenant): Response
    {
        unset($request);
        $tenantId = $tenant->tenantId();

        $rows = Db::table('ch_exchange_product')
            ->alias('ep')
            ->join(['eb_store_product' => 'p'], 'p.id = ep.product_id')
            ->where('ep.tenant_id', $tenantId)
            ->where('ep.status', 1)
            ->where('p.is_del', 0)
            ->where('p.is_show', 1)
            ->order('ep.sort', 'asc')
            ->order('ep.id', 'desc')
            ->limit(100)
            ->select()
            ->toArray();

        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'id' => (int) $row['product_id'],
                'name' => (string) ($row['store_name'] ?: $row['name']),
                'store_name' => (string) ($row['store_name'] ?: ''),
                'integral_price' => (int) ($row['points_cost'] ?? 0),
                'points' => (int) ($row['points_cost'] ?? 0),
                'price' => (string) ($row['cash_cost'] ?? '0.00'),
                'category' => (string) ($row['category'] ?? 'product'),
            ];
        }

        return json(['code' => 0, 'msg' => 'ok', 'data' => ['items' => $items]]);
    }
}
