<?php

declare(strict_types=1);

namespace app\chamber\controller;

use app\Request;
use app\chamber\tenancy\TenantContext;
use think\facade\Db;
use think\Response;

/**
 * 积分获取路径管理（admin）——文档 2-B 配置化
 * GET  /api/chamber/admin/v1/points-paths  读取当前配置（无配置回退默认 4 条）
 * PUT  /api/chamber/admin/v1/points-paths  保存配置（写入 eb_system_config）
 */
final class PointsPathsAdminController
{
    private const CONFIG_KEY = 'chamber_points_paths';

    private const DEFAULT_PATHS = [
        ['code' => 'coach', 'title' => '做教练 / 开课', 'points' => 200, 'icon' => 'coach'],
        ['code' => 'charity', 'title' => '公益活动', 'points' => 100, 'icon' => 'charity'],
        ['code' => 'roadshow', 'title' => '项目路演', 'points' => 80, 'icon' => 'roadshow'],
        ['code' => 'distribution', 'title' => '推荐新会员', 'points' => 50, 'icon' => 'distribution'],
    ];

    private function readConfig(): array
    {
        $config = Db::table('eb_system_config')
            ->where('menu_name', self::CONFIG_KEY)
            ->find();
        if (!is_array($config) || !$config['value']) {
            return self::DEFAULT_PATHS;
        }
        $decoded = json_decode((string) $config['value'], true);
        return is_array($decoded) && !empty($decoded) ? $decoded : self::DEFAULT_PATHS;
    }

    /** 读取当前配置（含默认值） */
    public function index(Request $request, TenantContext $tenant): Response
    {
        unset($request, $tenant);
        $paths = $this->readConfig();

        $items = [];
        foreach ($paths as $p) {
            $items[] = [
                'code'   => (string) ($p['code'] ?? ''),
                'title'  => (string) ($p['title'] ?? ''),
                'points' => (int) ($p['points'] ?? 0),
                'icon'   => (string) ($p['icon'] ?? ''),
            ];
        }

        return json(['code' => 0, 'msg' => 'ok', 'data' => ['items' => $items, 'is_default' => $this->configExists() ? false : true]]);
    }

    /** 保存配置（全量覆盖） */
    public function update(Request $request, TenantContext $tenant): Response
    {
        unset($tenant);
        $body = json_decode($request->getContent(), true);
        $items = is_array($body['items'] ?? null) ? $body['items'] : null;

        if ($items === null) {
            return json(['code' => 400, 'msg' => 'items 必填']);
        }

        // 归一化 + 校验
        $normalized = [];
        foreach ($items as $p) {
            $title = trim((string) ($p['title'] ?? ''));
            $points = (int) ($p['points'] ?? 0);
            if ($title === '' || $points < 0) {
                return json(['code' => 400, 'msg' => '路径标题或积分数值无效']);
            }
            $normalized[] = [
                'code'   => (string) ($p['code'] ?? ''),
                'title'  => $title,
                'points' => $points,
                'icon'   => (string) ($p['icon'] ?? ''),
            ];
        }

        $json = json_encode($normalized, JSON_UNESCAPED_UNICODE);
        if ($json === false || strlen($json) > 4000) {
            return json(['code' => 400, 'msg' => '配置数据过大或序列化失败']);
        }

        $existing = Db::table('eb_system_config')
            ->where('menu_name', self::CONFIG_KEY)
            ->find();
        if (is_array($existing)) {
            Db::table('eb_system_config')
                ->where('id', (int) $existing['id'])
                ->update(['value' => $json, 'update_time' => time()]);
        } else {
            Db::table('eb_system_config')->insert([
                'menu_name'     => self::CONFIG_KEY,
                'type'          => 'text',
                'input_type'    => 'input',
                'config_tab_id' => 0,
                'parameter'     => '',
                'upload_type'   => 1,
                'required'      => '',
                'width'         => 0,
                'high'          => 0,
                'value'         => $json,
                'add_time'      => time(),
                'update_time'   => time(),
            ]);
        }

        return json(['code' => 0, 'msg' => 'ok', 'data' => ['saved' => count($normalized)]]);
    }

    private function configExists(): bool
    {
        return (bool) Db::table('eb_system_config')->where('menu_name', self::CONFIG_KEY)->find();
    }
}
