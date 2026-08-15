<?php

declare(strict_types=1);

namespace app\chamber\controller;

use app\Request;
use app\chamber\identity\AuthenticatedUserContext;
use app\chamber\services\MemberIdentityService;
use app\chamber\tenancy\TenantContext;
use think\facade\Db;
use think\Response;

/**
 * 会员偏好设置（通知开关 + 隐私开关）持久化。
 * 独立于 ch_member_profile 的隐私可见范围体系（MemberProfilePrivacy），
 * 这里存设置页的轻量布尔开关：notify(activity/points/system)、privacy(profileVisible/inRecommend/hidePhone)。
 */
final class MemberSettingsController
{
    /** @var MemberIdentityService */
    private $identity;

    public function __construct(MemberIdentityService $identity)
    {
        $this->identity = $identity;
    }

    public function show(Request $request, TenantContext $tenant, AuthenticatedUserContext $auth): Response
    {
        $member = $this->identity->resolve($tenant, $auth);
        $row = Db::table('ch_member_settings')
            ->where('tenant_id', $tenant->tenantId())
            ->where('member_id', (int) $member['id'])
            ->find();

        $settings = is_array($row) && $row['settings_json'] !== '' && $row['settings_json'] !== null
            ? json_decode((string) $row['settings_json'], true)
            : [];
        if (!is_array($settings)) {
            $settings = [];
        }

        return $this->success([
            'coach_name' => trim((string) ($settings['coach_name'] ?? '')),
            'notify' => $this->defaults($settings, 'notify', ['activity' => true, 'points' => true, 'system' => true]),
            'privacy' => $this->defaults($settings, 'privacy', ['profileVisible' => true, 'inRecommend' => true, 'hidePhone' => true]),
        ]);
    }

    public function update(Request $request, TenantContext $tenant, AuthenticatedUserContext $auth): Response
    {
        $body = $request->post();
        $notify = isset($body['notify']) && is_array($body['notify']) ? $body['notify'] : null;
        $privacy = isset($body['privacy']) && is_array($body['privacy']) ? $body['privacy'] : null;
        $coachName = array_key_exists('coach_name', $body) ? (string) $body['coach_name'] : null;

        if ($notify === null && $privacy === null && $coachName === null) {
            return Response::create(['code' => 1, 'msg' => 'notify or privacy or coach_name required', 'data' => null], 'json', 422);
        }

        $member = $this->identity->resolve($tenant, $auth, true);
        $tenantId = $tenant->tenantId();
        $memberId = (int) $member['id'];
        $now = time();

        // 读取现有设置合并
        $row = Db::table('ch_member_settings')
            ->where('tenant_id', $tenantId)
            ->where('member_id', $memberId)
            ->find();
        $current = is_array($row) && $row['settings_json'] !== '' && $row['settings_json'] !== null
            ? json_decode((string) $row['settings_json'], true)
            : [];
        if (!is_array($current)) {
            $current = [];
        }

        if ($notify !== null) {
            $current['notify'] = $this->sanitizeBooleans($notify, ['activity', 'points', 'system']);
        }
        if ($privacy !== null) {
            $current['privacy'] = $this->sanitizeBooleans($privacy, ['profileVisible', 'inRecommend', 'hidePhone']);
        }
        if ($coachName !== null) {
            $trimmed = trim($coachName);
            if ($trimmed !== '' && mb_strlen($trimmed) > 16) {
                return Response::create(['code' => 1, 'msg' => 'coach_name too long (max 16)', 'data' => null], 'json', 422);
            }
            $current['coach_name'] = $trimmed;
        }

        $json = json_encode($current, JSON_UNESCAPED_UNICODE);

        if (is_array($row)) {
            Db::table('ch_member_settings')
                ->where('tenant_id', $tenantId)
                ->where('member_id', $memberId)
                ->update([
                    'settings_json' => $json,
                    'update_time' => $now,
                ]);
        } else {
            Db::table('ch_member_settings')->insert([
                'tenant_id' => $tenantId,
                'member_id' => $memberId,
                'uid' => (int) $member['uid'],
                'settings_json' => $json,
                'add_time' => $now,
                'update_time' => $now,
            ]);
        }

        return $this->success([
            'notify' => $current['notify'] ?? [],
            'privacy' => $current['privacy'] ?? [],
        ]);
    }

    /** 只保留白名单布尔键，非布尔值强制转布尔 */
    private function sanitizeBooleans(array $input, array $keys): array
    {
        $out = [];
        foreach ($keys as $k) {
            $out[$k] = array_key_exists($k, $input) ? (bool) $input[$k] : true;
        }
        return $out;
    }

    private function defaults(array $settings, string $group, array $fallback): array
    {
        return isset($settings[$group]) && is_array($settings[$group])
            ? $this->sanitizeBooleans($settings[$group], array_keys($fallback))
            : $fallback;
    }

    private function success(array $data): Response
    {
        return Response::create([
            'code' => 0,
            'msg' => 'ok',
            'data' => $data,
        ], 'json', 200);
    }
}
