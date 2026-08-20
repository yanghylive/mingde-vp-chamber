<?php

declare(strict_types=1);

namespace app\chamber\services;

/**
 * 微信小程序虚拟支付（Midas）服务
 *
 * 虚拟商品（会籍 / 积分补差 / 付费活动）必须走 wx.requestVirtualPayment
 * （微信官方「小程序虚拟支付」，底层米大师 Midas），审核合规要求。
 *
 * 签名规则（微信官方）：
 *   signData  = JSON 字符串（下单参数）
 *   paySig    = HMAC-SHA256(AppKey, "requestVirtualPayment&" + signData)
 *   signature = HMAC-SHA256(session_key, signData)   // session_key 取自 eb_wechat_user
 *
 * 配置（.env [PAY] 段，见 .build-workspace/crmeb-runtime/.env）：
 *   VPAY_OFFER_ID      虚拟支付商户号（Midas offerId）
 *   VPAY_SANDBOX_APPKEY 沙箱 AppKey（VPAY_ENV=1 用）
 *   VPAY_LIVE_APPKEY    现网 AppKey（VPAY_ENV=0 用）
 *   VPAY_ENV            1=沙箱 0=现网
 *   VPAY_NOTIFY_URL     回调地址
 */
final class VpayService
{
    /** @var string */
    private $offerId;
    /** @var string */
    private $appKey;
    /** @var int 1=沙箱 0=现网 */
    private $env;

    public function __construct()
    {
        $this->offerId = trim((string) env('pay.vpay_offer_id', ''));
        $this->env = (int) env('pay.vpay_env', 1);
        $this->appKey = $this->env === 1
            ? trim((string) env('pay.vpay_sandbox_appkey', ''))
            : trim((string) env('pay.vpay_live_appkey', ''));
    }

    /** 配置是否就绪（offerId + AppKey 齐全） */
    public function isConfigured(): bool
    {
        return $this->offerId !== '' && $this->appKey !== '';
    }

    /** 当前环境（1=沙箱 0=现网） */
    public function envFlag(): int
    {
        return $this->env;
    }

    /**
     * 构建虚拟支付三要素（前端 wx.requestVirtualPayment 使用）
     *
     * @param array $p ['product_id','amount_cents','out_trade_no','attach']
     * @param string $sessionKey 用户 session_key（eb_wechat_user.session_key）
     */
    public function buildPayParams(array $p, string $sessionKey): array
    {
        $signData = json_encode([
            'offerId' => $this->offerId,
            'buyQuantity' => 1,
            'env' => $this->env,
            'currencyType' => 'CNY',
            'productId' => $p['product_id'],
            'goodsPrice' => (int) $p['amount_cents'],
            'outTradeNo' => $p['out_trade_no'],
            'attach' => (string) ($p['attach'] ?? '')
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return [
            'signData' => $signData,
            'paySig' => hash_hmac('sha256', 'requestVirtualPayment&' . $signData, $this->appKey),
            'signature' => hash_hmac('sha256', $signData, $sessionKey),
            'mode' => 'short_series_goods',
            'offer_id' => $this->offerId,
            'env' => $this->env
        ];
    }

    /**
     * 回调验签（xpay_goods_deliver_notify）
     *
     * 米大师回调带 sign（HMAC-SHA256，AppKey 密钥），参数按 key 升序拼接。
     * ⚠️ 具体签名拼接规则以米大师官方文档 / 沙箱实测为准，上线前必须用真实回调校准。
     */
    public function verifyNotifySignature(array $payload): bool
    {
        $sign = (string) ($payload['sign'] ?? '');
        if ($sign === '') {
            return false;
        }
        unset($payload['sign'], $payload['sign_type']);
        ksort($payload);
        $query = http_build_query($payload);
        $expected = hash_hmac('sha256', $query, $this->appKey);
        return hash_equals($expected, $sign);
    }
}
