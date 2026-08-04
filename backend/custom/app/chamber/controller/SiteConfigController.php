<?php

declare(strict_types=1);

namespace app\chamber\controller;

use app\Request;
use app\chamber\tenancy\TenantContext;
use think\facade\Db;
use think\Response;

/**
 * 站点配置（用户端读取）——客服二维码 / 会员等级权益 / 积分比例 / AI 入口 / 首页 5 宫格
 * GET /api/chamber/v1/site-config
 * 配置存 eb_system_config（menu_name=chamber_site_config，JSON），无配置回退默认
 */
final class SiteConfigController
{
    /** 默认配置（与前端默认值一致，前端失败也回退同样值） */
    public const DEFAULT_CONFIG = [
        'customer_service' => [
            'qr_image' => '',
            'wechat_id' => '',
        ],
        'member_ladder' => [
            ['tier' => 1, 'name' => '入门会员', 'rights' => ['基础活动报名', '会员列表查看', '官方活动月历', '活动签到获取积分', '会员基础资料']],
            ['tier' => 2, 'name' => '进阶会员', 'rights' => ['开放好友申请', '大咖预约（线上 1v1）', '精选活动优先席位', '成长测评报告', '积分兑换商城']],
            ['tier' => 3, 'name' => '三阶毕业生', 'rights' => ['好友资料全开放', '分销码权益', '大咖预约（线下 1v1）', '闭门私享会席位', '专属成长档案']],
            ['tier' => 4, 'name' => '核心伙伴', 'rights' => ['项目路演优先', 'AI 陪跑席位', '名企 AI 咨询', '理事圆桌闭门会', '生态共创资源池']],
        ],
        'points_ratio' => [
            'points_per_yuan' => 10,
        ],
        'ai_entries' => [
            ['title' => '名企 AI 咨询', 'topic' => '名企 AI 咨询'],
            ['title' => '现有工具箱', 'topic' => '工具箱'],
            ['title' => '陪跑搭建', 'topic' => '陪跑搭建'],
            ['title' => '圈子·课程', 'topic' => '圈子课程'],
        ],
        'home_grids' => [
            ['label' => '官方活动', 'to' => '/events'],
            ['label' => '会员中心', 'to' => '/mine'],
            ['label' => '积分商城', 'to' => '/mall'],
            ['label' => '大咖主页', 'to' => '/experts'],
            ['label' => 'AI生态', 'to' => '/ai-ecosystem'],
        ],
    ];

    public function index(Request $request, TenantContext $tenant): Response
    {
        unset($request, $tenant);
        $config = Db::table('eb_system_config')
            ->where('menu_name', 'chamber_site_config')
            ->find();
        $configured = is_array($config) ? json_decode((string) ($config['value'] ?? ''), true) : null;
        $data = is_array($configured) ? $configured : self::DEFAULT_CONFIG;

        return json(['code' => 0, 'msg' => 'ok', 'data' => $data]);
    }
}
