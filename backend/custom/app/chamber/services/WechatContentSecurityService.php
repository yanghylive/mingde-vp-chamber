<?php

declare(strict_types=1);

namespace app\chamber\services;

use think\facade\Cache;
use think\facade\Log;
use Throwable;

/**
 * 微信小程序内容安全审核（msgSecCheck v2，文本）。
 *
 * 用途：活动/运营内容发布前做违规检测，命中微信内容安全策略（errcode 87014）拒绝发布。
 * 满足微信小程序「UGC/运营内容内容安全」审核要求。
 *
 * 设计决策：
 *  - 直接调微信官方 API（easywechat v3.3 的小程序客户端未注册 security 服务，不依赖其内部实现）
 *  - access_token 走 Redis 缓存（提前 20 分钟过期，官方有效期 7200s）
 *  - 审核服务故障（超时/网络/非违规错误码）→ fail-open 放行 + 告警日志：
 *    内容审核是合规约束而非数据安全边界，微信 API 抖动不应阻塞管理员发布运营内容；
 *    违规内容的兜底是事后运营抽检。命中违规（87014）则严格拒绝。
 */
final class WechatContentSecurityService
{
    private const TOKEN_URL = 'https://api.weixin.qq.com/cgi-bin/token';
    private const CHECK_URL = 'https://api.weixin.qq.com/wxa/msg_sec_check';
    private const TOKEN_CACHE_KEY = 'chamber:wechat:access_token';
    private const TOKEN_TTL = 6000; // 秒（官方 7200，提前 20 分钟过期刷新）
    private const ERR_RISKY = 87014; // 内容命中违规
    private const ERR_TOKEN_INVALID = 40001; // access_token 无效或过期
    private const SEGMENT_MAX = 2400; // msgSecCheck v2 单次 content 上限 2500，留余量

    /**
     * 审核一段文本是否安全。
     * @return bool true=安全/放行，false=命中违规（调用方应拒绝）
     */
    public function checkText(string $content): bool
    {
        $content = trim($content);
        if ($content === '') {
            return true;
        }
        // 超长分段审核（详情富文本可能超过单次上限）
        foreach (mb_str_split($content, self::SEGMENT_MAX) as $segment) {
            if (!$this->checkSegment($segment)) {
                return false;
            }
        }

        return true;
    }

    private function checkSegment(string $segment): bool
    {
        try {
            $token = $this->accessToken();
            $errcode = $this->checkWithToken($token, $segment);
            if ($errcode === self::ERR_TOKEN_INVALID) {
                // token 失效：强制刷新后重试一次
                $token = $this->accessToken(true);
                $errcode = $this->checkWithToken($token, $segment);
            }
            if ($errcode === self::ERR_RISKY) {
                return false;
            }
            // 其余错误码（频率限制/系统繁忙等）fail-open
            if ($errcode !== 0) {
                Log::warning('wechat msg_sec_check returned non-zero errcode, allowing', ['errcode' => $errcode]);
            }

            return true;
        } catch (Throwable $exception) {
            Log::warning('wechat content security check failed, allowing', [
                'error' => $exception->getMessage(),
            ]);

            return true;
        }
    }

    private function checkWithToken(string $token, string $segment): int
    {
        $response = $this->postJson(
            self::CHECK_URL . '?access_token=' . urlencode($token),
            ['content' => $segment, 'version' => 2, 'scene' => 2],
            8
        );

        return (int) ($response['errcode'] ?? 0);
    }

    private function accessToken(bool $force = false): string
    {
        if (!$force) {
            $cached = Cache::store('redis')->get(self::TOKEN_CACHE_KEY);
            if (is_string($cached) && $cached !== '') {
                return $cached;
            }
        }
        $config = \crmeb\services\SystemConfigService::more(['routine_appId', 'routine_appsecret']);
        $appid = trim((string) ($config['routine_appId'] ?? ''));
        $secret = trim((string) ($config['routine_appsecret'] ?? ''));
        if ($appid === '' || $secret === '') {
            throw new \RuntimeException('wechat mini program appid/secret not configured (sys_config routine_appId/routine_appsecret)');
        }
        $response = $this->getJson(
            self::TOKEN_URL . '?grant_type=client_credential&appid=' . urlencode($appid) . '&secret=' . urlencode($secret),
            8
        );
        $token = $response['access_token'] ?? '';
        if (!is_string($token) || $token === '') {
            throw new \RuntimeException('wechat access_token fetch failed: ' . json_encode($response, JSON_UNESCAPED_UNICODE));
        }
        Cache::store('redis')->set(self::TOKEN_CACHE_KEY, $token, self::TOKEN_TTL);

        return $token;
    }

    private function getJson(string $url, int $timeout): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $raw = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);
        if ($raw === false || $raw === '') {
            throw new \RuntimeException('wechat api request failed: ' . $error);
        }
        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('wechat api returned non-JSON response');
        }

        return $decoded;
    }

    private function postJson(string $url, array $body, int $timeout): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $raw = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);
        if ($raw === false || $raw === '') {
            throw new \RuntimeException('wechat api request failed: ' . $error);
        }
        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('wechat api returned non-JSON response');
        }

        return $decoded;
    }
}
