<?php

declare(strict_types=1);

namespace app\chamber\services;

/**
 * 汇付运行时配置。
 *
 * 只从环境变量读取密钥和证书路径，不把私钥写入数据库或代码。
 * 具体支付/分账产品的 endpoint 由 product adapter 传入，避免把不同汇付产品混用。
 */
final class HuifuConfig
{
    /** @return array<string, string|bool|int> */
    public function values(): array
    {
        return [
            'baseUrl' => $this->env('HUIFU_API_BASE_URL', ''),
            'sysId' => $this->env('HUIFU_SYS_ID', ''),
            'productId' => $this->env('HUIFU_PRODUCT_ID', ''),
            'huifuId' => $this->env('HUIFU_ID', ''),
            'privateKeyPath' => $this->env('HUIFU_PRIVATE_KEY_PATH', ''),
            'publicKeyPath' => $this->env('HUIFU_PUBLIC_KEY_PATH', ''),
            'serialNo' => $this->env('HUIFU_SERIAL_NO', ''),
            'notifyUrl' => $this->env('HUIFU_NOTIFY_URL', ''),
            'live' => $this->booleanEnv('HUIFU_LIVE', false),
            'timeoutSeconds' => $this->intEnv('HUIFU_TIMEOUT_SECONDS', 10, 3, 30),
        ];
    }

    /** @return array{ready:bool,live:bool,items:array<int,array{key:string,ready:bool,required:bool,hint:string}>} */
    public function status(): array
    {
        $config = $this->values();
        $items = [
            ['key' => 'baseUrl', 'ready' => $config['baseUrl'] !== '', 'required' => true, 'hint' => '汇付 API 基础地址'],
            ['key' => 'sysId', 'ready' => $config['sysId'] !== '', 'required' => true, 'hint' => '系统编号 sys_id'],
            ['key' => 'productId', 'ready' => $config['productId'] !== '', 'required' => true, 'hint' => '产品编号 product_id'],
            ['key' => 'huifuId', 'ready' => $config['huifuId'] !== '', 'required' => true, 'hint' => '平台汇付 huifu_id'],
            ['key' => 'privateKeyPath', 'ready' => $config['privateKeyPath'] !== '', 'required' => true, 'hint' => '商户 RSA 私钥路径'],
            ['key' => 'serialNo', 'ready' => $config['serialNo'] !== '', 'required' => false, 'hint' => '证书序列号（按产品合同）'],
            ['key' => 'publicKeyPath', 'ready' => $config['publicKeyPath'] !== '', 'required' => false, 'hint' => '汇付回调公钥/证书路径（按产品合同）'],
            ['key' => 'notifyUrl', 'ready' => $config['notifyUrl'] !== '', 'required' => true, 'hint' => '汇付异步通知地址'],
        ];

        $ready = true;
        foreach ($items as $item) {
            if ($item['required'] && !$item['ready']) {
                $ready = false;
                break;
            }
        }

        return ['ready' => $ready, 'live' => (bool) $config['live'], 'items' => $items];
    }

    public function isReady(): bool
    {
        return (bool) $this->status()['ready'];
    }

    private function env(string $name, string $default): string
    {
        $value = getenv($name);
        if (($value === false || trim((string) $value) === '') && function_exists('env')) {
            $value = env($name, $default);
        }
        if ($value === false || trim((string) $value) === '') {
            $value = $default;
        }

        return trim((string) $value);
    }

    private function booleanEnv(string $name, bool $default): bool
    {
        $value = getenv($name);
        if ($value === false && function_exists('env')) {
            $value = env($name, $default ? '1' : '0');
        }
        if ($value === false) {
            $value = $default ? '1' : '0';
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'on', 'yes'], true);
    }

    private function intEnv(string $name, int $default, int $min, int $max): int
    {
        $value = getenv($name);
        if (($value === false || trim((string) $value) === '') && function_exists('env')) {
            $value = env($name, (string) $default);
        }
        if ($value === false || trim((string) $value) === '') {
            $value = (string) $default;
        }
        $parsed = (int) $value;
        if ($parsed < $min || $parsed > $max) {
            return $default;
        }

        return $parsed;
    }
}
