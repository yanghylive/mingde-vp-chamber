<?php

declare(strict_types=1);

namespace app\chamber\services;

use RuntimeException;

/**
 * 汇付 RSA2 签名工具。
 *
 * 本类只负责密码学操作；不同汇付产品的 canonical message 由对应 adapter
 * 按官方合同生成，避免在通用层猜测 data 字段的排序/编码规则。
 */
final class HuifuSigner
{
    private $config;

    public function __construct(?HuifuConfig $config = null)
    {
        $this->config = $config ?: new HuifuConfig();
    }

    public function sign(string $canonicalMessage): string
    {
        if ($canonicalMessage === '') {
            throw new RuntimeException('Huifu canonical message must not be empty');
        }

        $values = $this->config->values();
        $privateKeyPath = (string) $values['privateKeyPath'];
        if ($privateKeyPath === '' || !is_readable($privateKeyPath)) {
            throw new RuntimeException('Huifu private key is missing or unreadable');
        }
        $privateKey = openssl_pkey_get_private((string) file_get_contents($privateKeyPath));
        if ($privateKey === false) {
            throw new RuntimeException('Huifu private key cannot be loaded');
        }

        $signature = '';
        if (!openssl_sign($canonicalMessage, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Huifu RSA2 signing failed');
        }

        return base64_encode($signature);
    }

    public function verify(string $canonicalMessage, string $signature, ?string $publicKeyPath = null): bool
    {
        if ($canonicalMessage === '' || $signature === '') {
            return false;
        }

        $values = $this->config->values();
        $path = trim((string) ($publicKeyPath ?: $values['publicKeyPath']));
        if ($path === '' || !is_readable($path)) {
            return false;
        }
        $publicKey = openssl_pkey_get_public((string) file_get_contents($path));
        if ($publicKey === false) {
            return false;
        }

        $decoded = base64_decode($signature, true);
        if ($decoded === false) {
            return false;
        }

        return openssl_verify($canonicalMessage, $decoded, $publicKey, OPENSSL_ALGO_SHA256) === 1;
    }
}
