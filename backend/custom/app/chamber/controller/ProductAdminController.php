<?php

declare(strict_types=1);

namespace app\chamber\controller;

use app\Request;
use app\chamber\tenancy\TenantContext;
use think\facade\Db;
use think\Response;

/**
 * 积分商城商品管理（admin）
 * 数据源：CRMEB 商城商品表 eb_store_product（与小程序端 ProductController 一致）。
 *
 * GET   /api/chamber/admin/v1/products              商品列表（含积分价/现金价/库存/上下架）
 * PATCH /api/chamber/admin/v1/products/:product_id  编辑商品（积分价/现金价/库存/上下架/名称/简介/图片/单位/排序）
 */
final class ProductAdminController
{
    public function index(Request $request, TenantContext $tenant): Response
    {
        unset($tenant);

        $query = Db::table('eb_store_product')->where('is_del', 0);
        $q = trim((string) ($request->get('q') ?? ''));
        if ($q !== '' && mb_strlen($q) <= 40) {
            $like = '%' . $q . '%';
            $query->where(function ($sub) use ($like) {
                $sub->where('store_name', 'like', $like)
                    ->whereOr('keyword', 'like', $like);
            });
        }

        $rows = $query->order('id', 'desc')->limit(200)->select()->toArray();

        $items = [];
        foreach ($rows as $row) {
            $items[] = $this->serialize($row);
        }

        return json(['code' => 0, 'msg' => 'ok', 'data' => ['items' => $items, 'total' => count($items)]]);
    }

    public function update(int $product_id, Request $request, TenantContext $tenant): Response
    {
        unset($tenant);

        $body = json_decode((string) $request->getContent(), true);
        if (!is_array($body)) {
            return json(['code' => 400, 'msg' => '请求体格式错误']);
        }

        $row = Db::table('eb_store_product')
            ->where('id', $product_id)
            ->where('is_del', 0)
            ->find();
        if (!is_array($row)) {
            return json(['code' => 404, 'msg' => '商品不存在']);
        }

        $data = [];

        if (array_key_exists('store_name', $body)) {
            $name = trim((string) $body['store_name']);
            if ($name === '') {
                return json(['code' => 400, 'msg' => '商品名称不能为空']);
            }
            $data['store_name'] = $name;
        }
        if (array_key_exists('store_info', $body)) {
            $data['store_info'] = trim((string) $body['store_info']);
        }
        if (array_key_exists('image', $body)) {
            $data['image'] = trim((string) $body['image']);
        }
        if (array_key_exists('price', $body)) {
            $price = (float) $body['price'];
            if ($price < 0 || $price > 99999999) {
                return json(['code' => 400, 'msg' => '现金价超出允许范围']);
            }
            $data['price'] = $price;
        }
        if (array_key_exists('integral_price', $body)) {
            $points = (int) $body['integral_price'];
            if ($points < 0 || $points > 100000000) {
                return json(['code' => 400, 'msg' => '积分价超出允许范围']);
            }
            $data['integral_price'] = $points;
        }
        if (array_key_exists('stock', $body)) {
            $stock = (int) $body['stock'];
            if ($stock < 0 || $stock > 100000000) {
                return json(['code' => 400, 'msg' => '库存超出允许范围']);
            }
            $data['stock'] = $stock;
        }
        if (array_key_exists('is_show', $body)) {
            $data['is_show'] = $body['is_show'] ? 1 : 0;
        }
        if (array_key_exists('unit_name', $body)) {
            $unit = trim((string) $body['unit_name']);
            if (mb_strlen($unit) > 16) {
                return json(['code' => 400, 'msg' => '单位名称过长']);
            }
            $data['unit_name'] = $unit !== '' ? $unit : '件';
        }
        if (array_key_exists('sort', $body)) {
            $data['sort'] = (int) $body['sort'];
        }

        if (empty($data)) {
            return json(['code' => 400, 'msg' => '没有可更新的字段']);
        }

        Db::table('eb_store_product')->where('id', $product_id)->update($data);

        $fresh = Db::table('eb_store_product')->where('id', $product_id)->find();
        return json(['code' => 0, 'msg' => 'ok', 'data' => $this->serialize($fresh)]);
    }

    /** @param array<string,mixed> $row */
    private function serialize(array $row): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'store_name' => (string) ($row['store_name'] ?? ''),
            'image' => (string) ($row['image'] ?? ''),
            'store_info' => (string) ($row['store_info'] ?? ''),
            'price' => (string) ($row['price'] ?? '0.00'),
            'integral_price' => (int) ($row['integral_price'] ?? 0),
            'stock' => (int) ($row['stock'] ?? 0),
            'is_show' => (int) ($row['is_show'] ?? 0),
            'unit_name' => (string) ($row['unit_name'] ?? '件'),
            'sort' => (int) ($row['sort'] ?? 0),
            'sales' => (int) ($row['sales'] ?? 0),
        ];
    }
}
