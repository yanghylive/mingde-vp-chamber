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

    private const EMBEDDING_ENDPOINT = '/api/v1/embeddings';

    /** embedding 输入截断长度（bge 系 512 token 上下文，中文保守截断） */
    private const EMBED_MAX_CHARS = 1000;

    /** embedding 调用单独超时（毫秒级感知，避免拖慢对话） */
    private const EMBED_TIMEOUT = 15;

    /**
     * 文本向量化（kaypal-embedding，384 维）。
     *
     * @param string|array $inputs 单条文本或批量文本数组
     * @param array|null $usageOut 引用参数：带回网关 usage（prompt_tokens/total_tokens）
     * @param int $timeout 超时秒数；对话期 query 传短值快速降级，添加知识传长值
     * @return array<int, array<int, float>> 按输入顺序返回向量列表
     */
    public function embed($inputs, ?array &$usageOut = null, int $timeout = self::EMBED_TIMEOUT): array
    {
        $cfg = Config::get('coaching', []);
        $origin = rtrim((string) ($cfg['kaypal_origin'] ?? ''), '/');
        $credential = (string) ($cfg['kaypal_app_credential'] ?? '');
        $sslVerify = (bool) ($cfg['kaypal_ssl_verify'] ?? true);

        if ($origin === '') {
            throw new RuntimeException('chamber.coaching.kaypal_origin is not configured');
        }
        if ($credential === '') {
            throw new RuntimeException('chamber.coaching.kaypal_app_credential is not configured');
        }

        $list = is_array($inputs) ? array_values($inputs) : [$inputs];
        $maxChars = self::EMBED_MAX_CHARS;
        $list = array_map(static function ($s) use ($maxChars) {
            $text = trim((string) $s);
            if ($text === '') {
                return '';
            }

            return mb_strlen($text) > $maxChars ? mb_substr($text, 0, $maxChars) : $text;
        }, $list);
        $list = array_values(array_filter($list, static fn ($s) => $s !== ''));
        if (!$list) {
            return [];
        }

        $headers = [
            'Content-Type: application/json',
            'x-kaypal-api-key: ' . $credential,
            'X-Kaypal-Context: ' . $this->mintContext($cfg),
            'Authorization: Bearer ' . $credential,
        ];

        $response = $this->postJson($origin . self::EMBEDDING_ENDPOINT, ['model' => 'kaypal-embedding', 'input' => $list], $headers, $timeout, $sslVerify);

        if (!is_array($response)) {
            throw new RuntimeException('kaypal embedding returned a malformed response');
        }
        if (isset($response['error'])) {
            $message = is_array($response['error'])
                ? json_encode($response['error'], JSON_UNESCAPED_UNICODE)
                : (string) $response['error'];
            throw new RuntimeException('kaypal embedding error: ' . $message);
        }

        if (is_array($usageOut)) {
            $usageOut['prompt_tokens'] = (int) ($response['usage']['prompt_tokens'] ?? 0);
            $usageOut['total_tokens'] = (int) ($response['usage']['total_tokens'] ?? 0);
        }

        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        usort($data, static function ($a, $b) {
            return (int) ($a['index'] ?? 0) <=> (int) ($b['index'] ?? 0);
        });

        $vectors = [];
        foreach ($data as $item) {
            $emb = $item['embedding'] ?? null;
            $vectors[] = is_array($emb) ? array_values(array_map(static fn ($v) => (float) $v, $emb)) : [];
        }

        return $vectors;
    }

    /**
     * @param string $system system prompt
     * @param string $user user prompt
     * @param int $maxTokens
     * @param float $temperature
     * @param array|null $usageOut 引用参数：成功时带回网关返回的 usage（OpenAI 兼容：prompt_tokens/completion_tokens/total_tokens），供服务端计费权威记录
     */
    public function chat(string $system, string $user, int $maxTokens = 1600, float $temperature = 0.8, ?array &$usageOut = null): string
    {
        return $this->chatWithHistory($system, [], $user, $maxTokens, $temperature, $usageOut);
    }

    /**
     * 多轮对话：messages = [system] + history(role/content) + [user]。
     * 用于 AI 智能分身：训练对话（带大咖此前轮次）+ 分身对话（注入记忆条目）。
     *
     * @param string $system system prompt
     * @param array $history 历史消息 [['role' => 'user'|'assistant', 'content' => '...'], ...]
     * @param string $user 当前用户消息
     */
    public function chatWithHistory(string $system, array $history, string $user, int $maxTokens = 1600, float $temperature = 0.8, ?array &$usageOut = null): string
    {
        $cfg = Config::get('coaching', []);
        $origin = rtrim((string) ($cfg['kaypal_origin'] ?? ''), '/');
        $credential = (string) ($cfg['kaypal_app_credential'] ?? '');
        $model = (string) ($cfg['kaypal_model'] ?? 'kaypal-fast');
        $timeout = (int) ($cfg['kaypal_timeout'] ?? 30);
        $sslVerify = (bool) ($cfg['kaypal_ssl_verify'] ?? true);

        if ($origin === '') {
            throw new RuntimeException('chamber.coaching.kaypal_origin is not configured');
        }
        if ($credential === '') {
            throw new RuntimeException('chamber.coaching.kaypal_app_credential is not configured');
        }

        $messages = [['role' => 'system', 'content' => $system]];
        foreach ($history as $item) {
            $role = ($item['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
            $content = (string) ($item['content'] ?? '');
            if ($content === '') {
                continue;
            }
            $messages[] = ['role' => $role, 'content' => $content];
        }
        $messages[] = ['role' => 'user', 'content' => $user];

        $body = [
            'model' => $model,
            'messages' => $messages,
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

        $response = $this->postJson($origin . self::ENDPOINT, $body, $headers, $timeout, $sslVerify);

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

        // 带回 usage 计量（服务端计费权威依据，OpenAI 兼容响应结构）
        if (is_array($usageOut)) {
            $usageOut['prompt_tokens'] = (int) ($response['usage']['prompt_tokens'] ?? 0);
            $usageOut['completion_tokens'] = (int) ($response['usage']['completion_tokens'] ?? 0);
            $usageOut['total_tokens'] = (int) ($response['usage']['total_tokens'] ?? 0);
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

    private function postJson(string $url, array $body, array $headers, int $timeout, bool $sslVerify): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
            CURLOPT_SSL_VERIFYPEER => $sslVerify,
            CURLOPT_SSL_VERIFYHOST => $sslVerify ? 2 : 0,
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
