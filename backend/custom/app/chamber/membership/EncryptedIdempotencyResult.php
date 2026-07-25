<?php

declare(strict_types=1);

namespace app\chamber\membership;

use RuntimeException;

final class EncryptedIdempotencyResult
{
    private const CIPHER = 'aes-256-gcm';
    private const VERSION = 'aes-256-gcm-v1';

    public static function seal(array $data, string $associatedData): array
    {
        $plaintext = BootstrapIdempotency::canonicalJson($data);
        $nonce = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            self::key(),
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            $associatedData,
            16
        );
        if (!is_string($ciphertext) || strlen($tag) !== 16) {
            throw new RuntimeException('Idempotency result encryption failed');
        }

        return [
            'encryption' => self::VERSION,
            'nonce' => base64_encode($nonce),
            'tag' => base64_encode($tag),
            'ciphertext' => base64_encode($ciphertext),
        ];
    }

    public static function open(array $envelope, string $associatedData): array
    {
        if (($envelope['encryption'] ?? null) !== self::VERSION) {
            throw new RuntimeException('Stored idempotency result encryption version is unsupported');
        }
        foreach (['nonce', 'tag', 'ciphertext'] as $field) {
            if (!isset($envelope[$field]) || !is_string($envelope[$field])) {
                throw new RuntimeException('Stored idempotency result encryption envelope is incomplete');
            }
        }

        $nonce = base64_decode($envelope['nonce'], true);
        $tag = base64_decode($envelope['tag'], true);
        $ciphertext = base64_decode($envelope['ciphertext'], true);
        if (!is_string($nonce) || strlen($nonce) !== 12
            || !is_string($tag) || strlen($tag) !== 16
            || !is_string($ciphertext) || $ciphertext === '') {
            throw new RuntimeException('Stored idempotency result encryption envelope is invalid');
        }

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            self::key(),
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            $associatedData
        );
        if (!is_string($plaintext)) {
            throw new RuntimeException('Stored idempotency result authentication failed');
        }
        $data = json_decode($plaintext, true);
        if (!is_array($data) || json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Stored idempotency result payload is invalid');
        }

        return $data;
    }

    private static function key(): string
    {
        $secret = getenv('CHAMBER_IDEMPOTENCY_ENCRYPTION_KEY');
        if (!is_string($secret) || strlen($secret) < 32 || strlen($secret) > 512) {
            throw new RuntimeException('CHAMBER_IDEMPOTENCY_ENCRYPTION_KEY must contain 32-512 bytes');
        }

        return hash('sha256', "chamber-idempotency-result\0v1\0" . $secret, true);
    }
}
