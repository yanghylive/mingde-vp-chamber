<?php

declare(strict_types=1);

namespace app\chamber\controller;

use app\Request;
use app\chamber\identity\AuthenticatedAdminContext;
use app\chamber\tenancy\TenantContext;
use think\facade\Db;
use think\Response;

/**
 * 站点配置管理（admin）——客服二维码 / 会员等级权益 / 积分比例 / AI 入口 / 5 宫格
 * GET /api/chamber/admin/v1/site-config   读取当前配置（含默认值）
 * PUT /api/chamber/admin/v1/site-config   保存配置（写 eb_system_config.chamber_site_config）
 */
final class SiteConfigAdminController
{
    private const CONFIG_KEY = 'chamber_site_config';
    private const MAX_BODY_BYTES = 65536;

    public function index(Request $request, TenantContext $tenant): Response
    {
        unset($request, $tenant);
        $config = Db::table('eb_system_config')
            ->where('menu_name', self::CONFIG_KEY)
            ->find();
        $configured = is_array($config) ? json_decode((string) ($config['value'] ?? ''), true) : null;
        $data = is_array($configured) ? $configured : SiteConfigController::DEFAULT_CONFIG;

        return json(['code' => 0, 'msg' => 'ok', 'data' => $data]);
    }

    public function update(Request $request, TenantContext $tenant, AuthenticatedAdminContext $admin): Response
    {
        $admin->assertPermission('chamber.site_config.write');
        unset($tenant);
        $raw = $request->getContent();
        if (strlen($raw) > self::MAX_BODY_BYTES) {
            return json(['code' => 413, 'msg' => '配置数据过大']);
        }
        $body = json_decode($raw, true);
        if (!is_array($body)) {
            return json(['code' => 400, 'msg' => '配置 JSON 无效']);
        }

        // 与默认配置合并（缺的区块补默认，避免前端 undefined）
        $merged = SiteConfigController::DEFAULT_CONFIG;
        foreach ($merged as $key => $defaultVal) {
            if (array_key_exists($key, $body)) {
                $merged[$key] = $body[$key];
            }
        }
        // 保留未知自定义键（如未来新增区块）
        foreach ($body as $key => $val) {
            if (!array_key_exists($key, $merged)) {
                $merged[$key] = $val;
            }
        }

        $json = json_encode($merged, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return json(['code' => 400, 'msg' => '配置序列化失败']);
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

        return json(['code' => 0, 'msg' => 'ok', 'data' => ['saved' => true]]);
    }
}
