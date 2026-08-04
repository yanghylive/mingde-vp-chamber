<?php

declare(strict_types=1);

namespace app\chamber\controller;

use app\Request;
use app\chamber\tenancy\TenantContext;
use think\facade\Db;
use think\Response;

/**
 * 积分获取路径（可配置）——文档 2-B
 * GET /api/chamber/v1/points/paths
 * 配置存 eb_system_config（menu_name=chamber_points_paths，JSON），无配置回退默认 4 条
 */
final class PointsPathsController
{
    /** 默认 4 条路径（无配置时回退） */
    private const DEFAULT_PATHS = [
        ['code' => 'coach', 'title' => '做教练 / 开课', 'points' => 200, 'icon' => 'coach'],
        ['code' => 'charity', 'title' => '公益活动', 'points' => 100, 'icon' => 'charity'],
        ['code' => 'roadshow', 'title' => '项目路演', 'points' => 80, 'icon' => 'roadshow'],
        ['code' => 'distribution', 'title' => '推荐新会员', 'points' => 50, 'icon' => 'distribution'],
    ];

    public function index(Request $request, TenantContext $tenant): Response
    {
        unset($request);
        $tenantId = $tenant->tenantId();

        $config = Db::table('eb_system_config')
            ->where('menu_name', 'chamber_points_paths')
            ->find();
        $configured = $config ? json_decode((string) ($config['value'] ?? ''), true) : null;

        $paths = is_array($configured) && !empty($configured) ? $configured : self::DEFAULT_PATHS;

        // 归一化字段
        $items = [];
        foreach ($paths as $p) {
            $items[] = [
                'code'   => (string) ($p['code'] ?? ''),
                'title'  => (string) ($p['title'] ?? ''),
                'points' => (int) ($p['points'] ?? 0),
                'icon'   => (string) ($p['icon'] ?? ''),
            ];
        }

        return json([
            'code' => 0,
            'msg'  => 'ok',
            'data' => ['items' => $items],
        ]);
    }
}
