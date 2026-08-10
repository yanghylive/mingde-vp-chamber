<?php

namespace app\chamber\controller;

use app\Request;
use app\chamber\tenancy\TenantContext;
use think\facade\Db;
use think\Response;

/**
 * 积分商城（小程序端）：商品列表
 * 路径：GET /api/chamber/v1/products
 *
 * 数据源：CRMEB 商城商品表 eb_store_product（与 H5 商城一致）。
 * - 上架(is_show=1)且未删除(is_del=0)的商品全部展示
 * - integral_price = 所需积分（CRMEB 商品页「积分抵扣」填的值，按积分价语义使用）
 * - 积分可抵现金 = integral_price × integral_ratio（CRMEB 配置，默认 0.1 元/积分）
 * - 补现金 = 商品现金价 - 积分可抵现金（不足抵扣时为 0）
 */
final class ProductController
{
    public function index(Request $request, TenantContext $tenant): Response
    {
        unset($request, $tenant);

        $ratio = $this->integralRatio();

        $rows = Db::table('eb_store_product')
            ->where('is_del', 0)
            ->where('is_show', 1)
            ->order('id', 'desc')
            ->limit(100)
            ->select()
            ->toArray();

        $items = [];
        foreach ($rows as $row) {
            $price = (float) ($row['price'] ?? 0);
            $pointsCost = (int) ($row['integral_price'] ?? 0); // 所需积分
            $deduction = $pointsCost * $ratio; // 积分可抵现金（元）
            $cashCost = max(0, $price - $deduction); // 补现金

            $items[] = [
                'id' => (int) $row['id'],
                'name' => (string) ($row['store_name'] ?: ''),
                'store_name' => (string) ($row['store_name'] ?: ''),
                'image' => (string) ($row['image'] ?: ''),
                'integral_price' => $pointsCost,
                'points' => $pointsCost,
                'points_cost' => $pointsCost,
                'price' => (string) $row['price'],
                'cash_cost' => number_format($cashCost, 2, '.', ''),
                'stock' => (int) ($row['stock'] ?? 0),
                'unit_name' => (string) ($row['unit_name'] ?? '件'),
                'category' => 'product',
            ];
        }

        return json(['code' => 0, 'msg' => 'ok', 'data' => ['items' => $items]]);
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
