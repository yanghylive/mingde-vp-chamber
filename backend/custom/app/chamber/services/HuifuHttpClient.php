<?php

declare(strict_types=1);

namespace app\chamber\services;

use app\chamber\exceptions\MemberTransactionException;
use RuntimeException;
use think\facade\Log;

/**
 * 汇付 HTTP 传输层。
 *
 * 不定义具体产品 endpoint，也不把原始请求/响应写日志。业务 adapter 负责生成
 * 官方要求的 data、签名串和请求头，本类只处理 URL、超时、JSON 和统一错误。
 */
final class HuifuHttpClient
{
    private $config;

    public function __construct(?HuifuConfig $config = null)
    {
        $this->config = $config ?: new HuifuConfig();
    }

    /**
     * @param array<string, string> $headers
     * @return array{http_code:int,body:array<string,mixed>,raw_hash:string}
     */
    public function request(string $method, string $path, string $body, array $headers = []): array
    {
        $values = $this->config->values();
        $baseUrl = rtrim((string) $values['baseUrl'], '/');
        if ($baseUrl === '' || !preg_match('#^https://#i', $baseUrl)) {
            throw new MemberTransactionException(503, 'huifu_not_configured', '汇付 API 地址未配置或不是 HTTPS');
        }
        if ($path === '' || $path[0] !== '/' || preg_match('/[\r\n]/', $path)) {
            throw new MemberTransactionException(500, 'huifu_invalid_path', '汇付 API 路径非法');
        }

        $method = strtoupper(trim($method));
        if (!in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            throw new MemberTransactionException(500, 'huifu_invalid_method', '汇付 HTTP 方法非法');
        }

        $defaultHeaders = [
            'Content-Type: application/json',
            'Accept: application/json',
        ];
        foreach ($headers as $name => $value) {
            $name = trim((string) $name);
            if ($name === '' || preg_match('/[\r\n]/', $name . $value)) {
                throw new MemberTransactionException(500, 'huifu_invalid_header', '汇付请求头非法');
            }
            $defaultHeaders[] = $name . ': ' . (string) $value;
        }

        $url = $baseUrl . $path;
        $ch = curl_init($url);
        if ($ch === false) {
            throw new MemberTransactionException(500, 'huifu_curl_init_failed', '汇付请求初始化失败');
        }

        $maxBodyBytes = 1024 * 1024; // 1MB 响应上限，防止异常大响应撑爆 worker 内存
        $bodyBuffer = '';
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $defaultHeaders,
            CURLOPT_TIMEOUT => (int) $values['timeoutSeconds'],
            CURLOPT_CONNECTTIMEOUT => min(5, (int) $values['timeoutSeconds']),
            CURLOPT_RETURNTRANSFER => true,
            // 显式强制 TLS 证书校验，防止运行环境改动默认值导致中间人攻击
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            // 流式累计响应体并限制大小，超限即中止（curl 返回 CURLE_WRITE_ERROR）
            CURLOPT_WRITEFUNCTION => function ($ch, $data) use (&$bodyBuffer, $maxBodyBytes) {
                $len = strlen($data);
                if (strlen($bodyBuffer) + $len > $maxBodyBytes) {
                    return 0;
                }
                $bodyBuffer .= $data;

                return $len;
            },
        ]);
        $execResult = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $rawString = $bodyBuffer !== '' ? $bodyBuffer : (string) $execResult;
        $rawHash = hash('sha256', $rawString);
        if ($errno !== 0) {
            // path 只记接口段（去 query），避免未来 adapter 在 path 拼订单号/金额导致泄敏
            Log::warning('chamber.huifu.network_error', [
                'path' => strtok($path, '?') ?: $path,
                'curl_errno' => $errno,
                'curl_error' => $error,
                'raw_hash' => $rawHash,
            ]);
            throw new MemberTransactionException(502, 'huifu_network_error', '汇付请求失败，请稍后查询订单状态');
        }

        $decoded = json_decode($rawString, true);
        $bodyData = is_array($decoded) ? $decoded : [];
        if ($httpCode < 200 || $httpCode >= 300 || !is_array($decoded)) {
            Log::warning('chamber.huifu.http_error', [
                'path' => strtok($path, '?') ?: $path,
                'http' => $httpCode,
                'raw_hash' => $rawHash,
                'response_code' => is_array($bodyData) ? (string) ($bodyData['resp_code'] ?? '') : '',
            ]);
            throw new MemberTransactionException(502, 'huifu_upstream_error', '汇付返回异常，请稍后查询订单状态');
        }

        return ['http_code' => $httpCode, 'body' => $bodyData, 'raw_hash' => $rawHash];
    }
}
