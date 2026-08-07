<?php

declare(strict_types=1);

namespace app\chamber\coaching;

use RuntimeException;
use think\facade\Config;
use think\facade\Log;

/**
 * Kaypal 模型网关客户端（OpenAI 兼容）。
 *
 * 鉴权协议（实测验证，参考 kaypal-model-proxy.mjs）：
 *   - x-kaypal-api-key  = scoped app credential
 *   - X-Kaypal-Context  = 本地自签 HS256 JWT（iss/aud/sub/tenant_id/app_id/iat/exp，默认 55s）
 *   - Authorization     = Bearer <credential>
 * 端点：{origin}/api/v1/chat/completions
 */
final class KaypalGateway
{
    private const ENDPOINT = '/api/v1/chat/completions';

    public function chat(string $system, string $user, int $maxTokens = 1600, float $temperature = 0.8): string
    {
        $cfg = Config::get('chamber.coaching', []);
        $origin = rtrim((string) ($cfg['kaypal_origin'] ?? 'https://test.kaypal.cn'), '/');
        $credential = (string) ($cfg['kaypal_app_credential'] ?? '');
        $model = (string) ($cfg['kaypal_model'] ?? 'kaypal-fast');
        $timeout = (int) ($cfg['kaypal_timeout'] ?? 30);

        if ($credential === '') {
            throw new RuntimeException('chamber.coaching.kaypal_app_credential is not configured');
        }

        $body = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
            'temperature' => $temperature,
            'max_tokens' => $maxTokens,
            'stream' => false,
        ];

        $headers = [
            'Content-Type: application/json',
            'x-kaypal-api-key: ' . $credential,
            'X-Kaypal-Context: ' . $this->mintContext($cfg),
            'Authorization: Bearer ' . $credential,
        ];

        $response = $this->postJson($origin . self::ENDPOINT, $body, $headers, $timeout);

        if (!is_array($response)) {
            throw new RuntimeException('kaypal gateway returned a malformed response');
        }
        if (isset($response['error'])) {
            $message = is_array($response['error'])
                ? json_encode($response['error'], JSON_UNESCAPED_UNICODE)
                : (string) $response['error'];
            throw new RuntimeException('kaypal gateway error: ' . $message);
        }

        $content = $response['choices'][0]['message']['content'] ?? null;
        if (!is_string($content) || trim($content) === '') {
            throw new RuntimeException('kaypal gateway returned empty content');
        }

        return trim($content);
    }

    /**
     * 自签 X-Kaypal-Context（HS256 JWT）。
     */
    private function mintContext(array $cfg): string
    {
        $secret = (string) ($cfg['kaypal_context_jwt_secret'] ?? '');
        if ($secret === '') {
            throw new RuntimeException('chamber.coaching.kaypal_context_jwt_secret is not configured');
        }

        $now = time();
        $payload = [
            'iss' => $cfg['kaypal_context_iss'] ?? 'kaypal-ai-platform',
            'aud' => $cfg['kaypal_context_aud'] ?? 'kaypal-api-v1',
            'sub' => $cfg['kaypal_sub'] ?? 'cmo9p6i5x000a58uckbcyv45u',
            'tenant_id' => $cfg['kaypal_tenant_id'] ?? 'tenant-highest-enterprise-18230326666',
            'app_id' => $cfg['kaypal_app_id'] ?? 'octop',
            'request_id' => $this->randomId(),
            'jti' => $this->randomId(),
            'iat' => $now,
            'exp' => $now + (int) ($cfg['kaypal_context_ttl'] ?? 55),
        ];

        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $segments = [
            $this->b64u(json_encode($header, JSON_UNESCAPED_UNICODE)),
            $this->b64u(json_encode($payload, JSON_UNESCAPED_UNICODE)),
        ];
        $signingInput = implode('.', $segments);
        $signature = hash_hmac('sha256', $signingInput, $secret, true);

        return $signingInput . '.' . $this->b64u($signature);
    }

    private function postJson(string $url, array $body, array $headers, int $timeout): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $raw = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $raw === '') {
            throw new RuntimeException('kaypal gateway request failed: ' . $error);
        }

        if ($status >= 400) {
            Log::warning('kaypal gateway http error', ['status' => $status, 'body' => substr((string) $raw, 0, 500)]);
            throw new RuntimeException('kaypal gateway http ' . $status);
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('kaypal gateway returned non-JSON response');
        }

        return $decoded;
    }

    private function b64u(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function randomId(): string
    {
        return bin2hex(random_bytes(16));
    }
}
