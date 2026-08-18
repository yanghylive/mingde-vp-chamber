<?php

declare(strict_types=1);

namespace app\chamber\services;

use app\chamber\exceptions\MemberTransactionException;
use app\chamber\identity\AuthenticatedUserContext;
use app\chamber\tenancy\TenantContext;
use RuntimeException;
use think\facade\Db;
use think\facade\Log;

/**
 * 微信支付（APIv3 直连）——与 3010 ai-content 用同一套逻辑（同商户号 1116143786、
 * 同配置体系 WXPAY_*、同验签/解密/幂等模式）。
 *
 * 配置（env，未配齐时下单返回 need_config，不假报成功）：
 *   WXPAY_MCHID=1116143786
 *   WXPAY_APP_ID=<小程序/公众号 AppID>
 *   WXPAY_APIV3_KEY=<APIv3 密钥，32 位>
 *   WXPAY_SERIAL_NO=<商户 API 证书序列号>
 *   WXPAY_PRIVATE_KEY_PATH=<商户 API 私钥 apiclient_key.pem 路径>
 *   WXPAY_NOTIFY_URL=<回调通知地址>
 *   WXPAY_PLATFORM_CERT_PATH=<微信平台证书（回调验签，生产收款前必配）>
 *
 * 业务入账：回调验签解密 + 金额一致性校验 + 幂等（out_trade_no）后，
 * 按 business_type 调对应事实确认（membership→MembershipPaymentCompletionService::complete，
 * exchange→兑换订单置 paid）。
 *
 * @deprecated 自 2026-08-18 废弃：资金链路统一到汇付天下（唯一通道），不再单独使用微信支付收款。
 *             冻结待下线：汇付 adapter（PR-02~05）上线并切流完成后再清理本类。
 *             替代：汇付 SPIN 小程序支付（HuifuPaymentChannel + HuifuClient）。
 */
final class ChamberWechatPayService
{
    public const MCHID = '1116143786';

    /** 业务类型 */
    public const BUSINESS_MEMBERSHIP = 'membership';
    public const BUSINESS_EXCHANGE = 'exchange';

    /** 支付单挂起超时（秒）：超时未支付本地单自动 closed（微信侧同 2h 自然失效） */
    public const ORDER_EXPIRE_SECONDS = 7200;

    private const WXPAY_BASE = 'https://api.mch.weixin.qq.com';

    /** @var MembershipPaymentCompletionService|null */
    private $completion;

    public function __construct(?MembershipPaymentCompletionService $completion = null)
    {
        $this->completion = $completion;
    }

    // ------------------------------------------------------------------
    // 配置
    // ------------------------------------------------------------------

    /** @return array{mchid:string,appid:string,apiV3Key:string,serialNo:string,privateKeyPath:string,notifyUrl:string,platformCertPath:string} */
    public function config(): array
    {
        return [
            'mchid' => (string) (getenv('WXPAY_MCHID') ?: env('WXPAY_MCHID', self::MCHID)),
            'appid' => (string) (getenv('WXPAY_APP_ID') ?: env('WXPAY_APP_ID', '')),
            'apiV3Key' => (string) (getenv('WXPAY_APIV3_KEY') ?: env('WXPAY_APIV3_KEY', '')),
            'serialNo' => (string) (getenv('WXPAY_SERIAL_NO') ?: env('WXPAY_SERIAL_NO', '')),
            'privateKeyPath' => (string) (getenv('WXPAY_PRIVATE_KEY_PATH') ?: env('WXPAY_PRIVATE_KEY_PATH', '')),
            'notifyUrl' => (string) (getenv('WXPAY_NOTIFY_URL') ?: env('WXPAY_NOTIFY_URL', '')),
            'platformCertPath' => (string) (getenv('WXPAY_PLATFORM_CERT_PATH') ?: env('WXPAY_PLATFORM_CERT_PATH', '')),
        ];
    }

    public function configReady(): bool
    {
        $config = $this->config();

        return $config['appid'] !== ''
            && $config['apiV3Key'] !== ''
            && $config['serialNo'] !== ''
            && $config['privateKeyPath'] !== ''
            && $config['notifyUrl'] !== '';
    }

    /** 配置就绪检查（脱敏，供管理端/运营一眼看差什么） */
    public function configStatus(): array
    {
        $config = $this->config();
        $items = [
            ['key' => 'mchid', 'ready' => $config['mchid'] !== '', 'hint' => '商户号 ' . $config['mchid']],
            ['key' => 'appid', 'ready' => $config['appid'] !== '', 'hint' => '关联小程序/公众号 AppID'],
            ['key' => 'apiV3Key', 'ready' => $config['apiV3Key'] !== '', 'hint' => 'APIv3 密钥'],
            ['key' => 'serialNo', 'ready' => $config['serialNo'] !== '', 'hint' => '证书序列号'],
            ['key' => 'privateKeyPath', 'ready' => $config['privateKeyPath'] !== '', 'hint' => '商户 API 私钥路径'],
            ['key' => 'notifyUrl', 'ready' => $config['notifyUrl'] !== '', 'hint' => '回调通知地址'],
            ['key' => 'platformCert', 'ready' => $config['platformCertPath'] !== '', 'hint' => '微信平台证书（回调签名验证，生产收款前必配）'],
        ];

        return ['mchid' => $config['mchid'], 'items' => $items, 'ready' => $this->configReady()];
    }

    // ------------------------------------------------------------------
    // 下单
    // ------------------------------------------------------------------

    /**
     * 创建微信支付单（JSAPI/NATIVE）并调微信下单。
     *
     * @param int $amountCents 金额（分）
     * @param string $idempotencyKey 业务幂等键（out_trade_no，唯一）
     * @param string $businessType membership/exchange
     * @param int $businessRef membership=eb_store_order.id；exchange=ch_product_exchange_order.id
     * @param string $openid JSAPI 必填（用户在小程序 appid 下的 openid）
     * @param string $tradeType JSAPI/NATIVE
     */
    public function createPayment(
        TenantContext $tenant,
        AuthenticatedUserContext $auth,
        int $amountCents,
        string $description,
        string $idempotencyKey,
        string $businessType,
        int $businessRef,
        string $openid,
        string $tradeType = 'JSAPI'
    ): array {
        $config = $this->config();
        if (!$this->configReady()) {
            return ['status' => 'need_config', 'message' => '微信支付未配置完成（AppID/APIv3 密钥/商户证书/回调地址），配置后自动可用'];
        }
        if ($amountCents <= 0) {
            throw new MemberTransactionException(422, 'invalid_amount', '支付金额必须大于 0');
        }
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 64
            || preg_match('/^[A-Za-z0-9_-]+$/', $idempotencyKey) !== 1) {
            throw new MemberTransactionException(422, 'invalid_idempotency_key', 'Idempotency key 非法');
        }
        if (!in_array($businessType, [self::BUSINESS_MEMBERSHIP, self::BUSINESS_EXCHANGE], true)) {
            throw new MemberTransactionException(422, 'invalid_business_type', 'Business type 非法');
        }
        $tradeType = $tradeType === 'NATIVE' ? 'NATIVE' : 'JSAPI';
        if ($tradeType === 'JSAPI' && ($openid === '' || strlen($openid) > 128)) {
            throw new MemberTransactionException(422, 'openid_required', 'JSAPI 支付需要用户 openid');
        }

        $member = $this->identity($tenant, $auth);
        $memberId = (int) $member['id'];
        $uid = (int) $member['uid'];
        $tenantId = $tenant->tenantId();
        $now = time();

        // 惰性过期：创建超 2 小时仍 pending 的挂起单标 closed（微信侧同款 2h 自然失效，
        // 防止垃圾单累积 + 幂等键被死单占位）
        Db::table('ch_wechat_pay_order')
            ->where('status', 'pending')
            ->where('add_time', '<', $now - self::ORDER_EXPIRE_SECONDS)
            ->update(['status' => 'closed', 'update_time' => $now]);

        // 幂等：同 out_trade_no 已存在返回原单（对齐 ai-content）
        // 指纹校验：同 key 换业务/金额/归属 → 409 拒绝（防串单）
        $existing = Db::table('ch_wechat_pay_order')
            ->where('out_trade_no', $idempotencyKey)
            ->find();
        if (is_array($existing)) {
            if ((string) $existing['business_type'] !== $businessType
                || (int) $existing['business_ref'] !== $businessRef
                || (int) $existing['amount_cents'] !== $amountCents
                || (int) $existing['tenant_id'] !== $tenantId
                || (int) $existing['user_id'] !== $uid) {
                throw new MemberTransactionException(409, 'idempotency_conflict', '幂等键已被其他支付请求占用');
            }
            // closed 单（下单失败/超时/取消）：删除旧行重建，允许用户重新支付
            if ((string) $existing['status'] === 'closed') {
                Db::table('ch_wechat_pay_order')->where('id', (int) $existing['id'])->delete();
                $existing = null;
            } else {
                return $this->orderPayload($existing);
            }
        }

        $orderId = (int) Db::table('ch_wechat_pay_order')->insertGetId([
            'tenant_id' => $tenantId,
            'user_id' => $uid,
            'member_id' => $memberId,
            'out_trade_no' => $idempotencyKey,
            'mchid' => $config['mchid'],
            'appid' => $config['appid'],
            'description' => mb_substr($description, 0, 127),
            'amount_cents' => $amountCents,
            'currency' => 'CNY',
            'business_type' => $businessType,
            'business_ref' => $businessRef,
            'trade_type' => $tradeType,
            'status' => 'pending',
            'add_time' => $now,
            'update_time' => $now,
        ]);
        if ($orderId <= 0) {
            throw new MemberTransactionException(500, 'order_create_failed', '支付单创建失败');
        }

        // 调微信下单（对齐 ai-content：配置齐全必须真实调 API，否则订单挂 pending 假单）
        $payBody = json_encode([
            'appid' => $config['appid'],
            'mchid' => $config['mchid'],
            'description' => $description,
            'out_trade_no' => $idempotencyKey,
            'notify_url' => $config['notifyUrl'],
            'amount' => ['total' => $amountCents, 'currency' => 'CNY'],
        ], JSON_UNESCAPED_UNICODE);
        if ($tradeType === 'JSAPI') {
            $payBody = json_encode(array_merge(json_decode($payBody, true), ['payer' => ['openid' => $openid]]), JSON_UNESCAPED_UNICODE);
        }
        $path = $tradeType === 'JSAPI'
            ? '/v3/pay/transactions/jsapi'
            : '/v3/pay/transactions/native';

        try {
            $wxResult = $this->wxRequest('POST', $path, $payBody);
        } catch (RuntimeException $e) {
            // 网络异常：订单保留 pending（可重试查单）
            Log::warning('chamber.wechat_pay.downstream', ['out_trade_no' => $idempotencyKey, 'err' => $e->getMessage()]);

            return [
                'status' => 'order_pending_retry',
                'out_trade_no' => $idempotencyKey,
                'amount_cents' => $amountCents,
                'message' => '微信下单网络异常，可稍后重试',
            ];
        }

        if ($tradeType === 'JSAPI') {
            $prepayId = (string) ($wxResult['prepay_id'] ?? '');
            if ($prepayId === '') {
                $this->markClosed($orderId, $wxResult);

                return [
                    'status' => 'order_failed',
                    'out_trade_no' => $idempotencyKey,
                    'message' => '微信下单失败：' . (string) ($wxResult['message'] ?? 'unknown'),
                ];
            }

            return [
                'status' => 'pending',
                'out_trade_no' => $idempotencyKey,
                'amount_cents' => $amountCents,
                'pay_params' => $this->buildJsapiPayParams($config, $prepayId, $idempotencyKey),
                'message' => '支付单已创建，请拉起微信支付',
            ];
        }

        $codeUrl = (string) ($wxResult['code_url'] ?? '');
        if ($codeUrl === '') {
            $this->markClosed($orderId, $wxResult);

            return [
                'status' => 'order_failed',
                'out_trade_no' => $idempotencyKey,
                'message' => '微信下单失败：' . (string) ($wxResult['message'] ?? 'unknown'),
            ];
        }

        return [
            'status' => 'pending',
            'out_trade_no' => $idempotencyKey,
            'amount_cents' => $amountCents,
            'code_url' => $codeUrl,
            'message' => '支付单已创建，请扫码支付',
        ];
    }

    // ------------------------------------------------------------------
    // 回调
    // ------------------------------------------------------------------

    /**
     * 微信支付回调（V3）：验签 + 解密 resource + 金额一致性 + 幂等 + 业务入账。
     * 对齐 ai-content 的安全约束：无 apiV3Key 或缺少可解密 resource 时拒绝入账
     * （防任何人 POST outTradeNo 伪造支付到账）。
     */
    public function handleNotify(array $headers, string $rawBody): array
    {
        $config = $this->config();
        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            return ['code' => 'FAIL', 'message' => '回调不是合法 JSON'];
        }

        // 验签（APIv3：时间戳新鲜度 + 平台证书公钥 RSA 验签）
        // 安全策略：无平台证书 → 拒绝入账（真实回调无法验证签名，绝不静默放行）。
        $signature = $this->headerValue($headers, 'wechatpay-signature');
        $timestamp = $this->headerValue($headers, 'wechatpay-timestamp');
        $nonce = $this->headerValue($headers, 'wechatpay-nonce');
        $serial = $this->headerValue($headers, 'wechatpay-serial');
        if ($signature === '' || $timestamp === '' || $nonce === '') {
            return ['code' => 'FAIL', 'message' => '缺少验签头'];
        }
        $ts = (int) $timestamp;
        if ($ts <= 0 || abs(time() - $ts) > 300) {
            return ['code' => 'FAIL', 'message' => '回调时间戳过期'];
        }
        if ($config['platformCertPath'] === '') {
            Log::warning('chamber.wechat_pay.platform_cert_missing', ['serial' => $serial]);

            return ['code' => 'FAIL', 'message' => '平台证书未配置，拒绝处理回调'];
        }
        if (!$this->verifyPlatformSignature($config['platformCertPath'], $rawBody, $timestamp, $nonce, $signature)) {
            Log::warning('chamber.wechat_pay.verify_failed', ['serial' => $serial]);

            return ['code' => 'FAIL', 'message' => '验签失败'];
        }

        // 事件类型：仅处理支付成功事件
        $eventType = (string) ($payload['event_type'] ?? '');
        if ($eventType !== '' && $eventType !== 'TRANSACTION.SUCCESS') {
            Log::warning('chamber.wechat_pay.unexpected_event', ['event_type' => $eventType]);

            return ['code' => 'FAIL', 'message' => '非支付成功事件'];
        }

        // 解密 resource（AES-256-GCM，key=apiV3Key）——订单号/金额/状态都在解密后的明文里
        $resource = is_array($payload['resource'] ?? null) ? $payload['resource'] : null;
        if ($config['apiV3Key'] === ''
            || !isset($resource['ciphertext'], $resource['nonce'], $resource['associated_data'])) {
            Log::warning('chamber.wechat_pay.reject_unverifiable', ['resource_type' => (string) ($payload['resource_type'] ?? '')]);

            return ['code' => 'FAIL', 'message' => '回调资源不可验，拒绝入账'];
        }
        try {
            $transaction = $this->decryptResource($resource, $config['apiV3Key']);
        } catch (RuntimeException $e) {
            Log::warning('chamber.wechat_pay.decrypt_failed', ['err' => $e->getMessage()]);

            return ['code' => 'FAIL', 'message' => '解密失败'];
        }

        $outTradeNo = (string) ($transaction['out_trade_no'] ?? '');
        $transactionId = (string) ($transaction['transaction_id'] ?? '');
        if ($outTradeNo === '') {
            return ['code' => 'FAIL', 'message' => '缺少订单号'];
        }
        // 交易状态：仅 SUCCESS 才入账
        $tradeState = strtoupper((string) ($transaction['trade_state'] ?? ''));
        if ($tradeState !== 'SUCCESS') {
            Log::warning('chamber.wechat_pay.trade_not_success', ['out_trade_no' => $outTradeNo, 'trade_state' => $tradeState]);

            return ['code' => 'FAIL', 'message' => '交易未成功'];
        }

        // 幂等：已 paid 直接返回成功
        $order = Db::table('ch_wechat_pay_order')->where('out_trade_no', $outTradeNo)->find();
        if (!is_array($order)) {
            return ['code' => 'FAIL', 'message' => '订单不存在'];
        }
        $orderId = (int) $order['id'];
        if ((string) $order['status'] === 'paid') {
            return ['code' => 'SUCCESS', 'message' => 'OK'];
        }

        // 落审计（原始回调）
        Db::table('ch_wechat_pay_order')->where('id', $orderId)->update([
            'transaction_id' => $transactionId !== '' ? $transactionId : (string) $order['transaction_id'],
            'notify_payload' => mb_substr($rawBody, 0, 4096),
            'update_time' => time(),
        ]);

        // 金额一致性：解密明文金额 ≠ 本地支付单金额 → 拒绝（本地支付单金额由服务端按订单应付生成）
        $paidCents = (int) ($transaction['amount']['total'] ?? -1);
        if ($paidCents <= 0 || $paidCents !== (int) $order['amount_cents']) {
            Log::warning('chamber.wechat_pay.amount_mismatch', [
                'out_trade_no' => $outTradeNo,
                'order' => (int) $order['amount_cents'],
                'paid' => $paidCents,
            ]);

            return ['code' => 'FAIL', 'message' => '金额不一致，拒绝入账'];
        }

        // 幂等入账（事务内标记 paid + 业务事实确认）
        try {
            Db::transaction(function () use ($orderId, $order, $transactionId, $paidCents): void {
                $claimed = Db::table('ch_wechat_pay_order')
                    ->where('id', $orderId)
                    ->where('status', '<>', 'paid')
                    ->update([
                        'status' => 'paid',
                        'transaction_id' => $transactionId,
                        'paid_at' => time(),
                        'update_time' => time(),
                    ]);
                if ($claimed !== 1) {
                    return; // 并发回调已处理
                }
                $this->applyBusiness((int) $order['tenant_id'], (string) $order['business_type'], (int) $order['business_ref'], $transactionId);
            });
        } catch (Throwable $e) {
            Log::error('chamber.wechat_pay.apply_failed', ['out_trade_no' => $outTradeNo, 'err' => $e->getMessage()]);

            return ['code' => 'FAIL', 'message' => '入账失败'];
        }

        Log::info('chamber.wechat_pay.paid', ['out_trade_no' => $outTradeNo, 'amount_cents' => $paidCents]);

        return ['code' => 'SUCCESS', 'message' => 'OK'];
    }

    /** 业务事实确认：membership→支付完成事件（会员升级）；exchange→兑换订单置 paid */
    private function applyBusiness(int $tenantId, string $businessType, int $businessRef, string $transactionId): void
    {
        if ($businessType === self::BUSINESS_MEMBERSHIP) {
            if ($businessRef <= 0) {
                throw new RuntimeException('membership business_ref(order_pk) missing');
            }
            if ($this->completion === null) {
                $this->completion = app()->make(MembershipPaymentCompletionService::class);
            }
            $this->completion->complete(
                ['id' => $businessRef],
                'weixin',
                ['trade_no' => $transactionId !== '' ? $transactionId : null]
            );

            return;
        }

        if ($businessType === self::BUSINESS_EXCHANGE) {
            $exchangeOrder = Db::table('ch_product_exchange_order')
                ->where('id', $businessRef)
                ->where('tenant_id', $tenantId)
                ->find();
            if (!is_array($exchangeOrder)) {
                throw new RuntimeException('exchange order missing');
            }
            // 兑换单已 cancelled（用户取消后支付才到账的竞态）：钱已收但业务单已取消，
            // 拒绝入账并记录异常单（避免微信回调无限重试；运营人工处理退款）
            if ((string) $exchangeOrder['status'] === 'cancelled') {
                Log::warning('chamber.wechat_pay.exchange_cancelled_but_paid', [
                    'out_trade_no' => (string) ($exchangeOrder['idempotency_key'] ?? ''),
                    'exchange_order_id' => $businessRef,
                ]);

                return;
            }
            $updated = Db::table('ch_product_exchange_order')
                ->where('id', $businessRef)
                ->where('tenant_id', $tenantId)
                ->where('status', 'pending')
                ->update(['status' => 'paid', 'update_time' => time()]);
            if ($updated !== 1) {
                throw new RuntimeException('exchange order not payable');
            }

            return;
        }

        throw new RuntimeException('unsupported business type: ' . $businessType);
    }

    // ------------------------------------------------------------------
    // 查询
    // ------------------------------------------------------------------

    public function getOrderStatus(TenantContext $tenant, AuthenticatedUserContext $auth, string $outTradeNo): array
    {
        $member = $this->identity($tenant, $auth);
        $order = Db::table('ch_wechat_pay_order')
            ->where('out_trade_no', $outTradeNo)
            ->where('tenant_id', $tenant->tenantId())
            ->where('user_id', (int) $member['uid'])
            ->find();
        if (!is_array($order)) {
            return ['status' => 'not_found'];
        }

        return [
            'status' => (string) $order['status'],
            'amount_cents' => (int) $order['amount_cents'],
            'business_type' => (string) $order['business_type'],
            'business_ref' => (int) $order['business_ref'],
            'paid_at' => (int) $order['paid_at'],
            'transaction_id' => (string) $order['transaction_id'],
        ];
    }

    // ------------------------------------------------------------------
    // 微信 API 交互
    // ------------------------------------------------------------------

    /** 调微信 API（V3 签名 + 解析响应） */
    private function wxRequest(string $method, string $path, string $body): array
    {
        $config = $this->config();
        $ch = curl_init(self::WXPAY_BASE . $path);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: ' . $this->buildV3Authorization($method, $path, $body),
            ],
            CURLOPT_TIMEOUT => 10,
            CURLOPT_RETURNTRANSFER => true,
        ]);
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($errno !== 0) {
            throw new RuntimeException('wx api network error (curl ' . $errno . ')');
        }
        $decoded = json_decode((string) $raw, true);
        $result = is_array($decoded) ? $decoded : [];
        if ($httpCode >= 400) {
            Log::warning('chamber.wechat_pay.wx_error', ['path' => $path, 'http' => $httpCode, 'body' => mb_substr((string) $raw, 0, 500)]);
        }

        return $result;
    }

    /** V3 请求签名（商户私钥 RSA-SHA256） */
    private function buildV3Authorization(string $method, string $path, string $body): string
    {
        $config = $this->config();
        $privateKey = $this->readPrivateKey($config['privateKeyPath']);
        $timestamp = (string) time();
        $nonceStr = substr(bin2hex(random_bytes(16)), 0, 32);
        $message = $method . "\n" . $path . "\n" . $timestamp . "\n" . $nonceStr . "\n" . $body . "\n";
        $signature = '';
        openssl_sign($message, $signature, $privateKey, OPENSSL_ALGO_SHA256);

        return sprintf(
            'WECHATPAY2-SHA256-RSA2048 mchid="%s",nonce_str="%s",signature="%s",timestamp="%s",serial_no="%s"',
            $config['mchid'],
            $nonceStr,
            base64_encode($signature),
            $timestamp,
            $config['serialNo']
        );
    }

    /** JSAPI 二次签名（wx.requestPayment 参数） */
    private function buildJsapiPayParams(array $config, string $prepayId, string $outTradeNo): array
    {
        $timeStamp = (string) time();
        $nonceStr = substr(bin2hex(random_bytes(16)), 0, 32);
        $package = 'prepay_id=' . $prepayId;
        $message = $config['appid'] . "\n" . $timeStamp . "\n" . $nonceStr . "\n" . $package . "\n";
        $privateKey = $this->readPrivateKey($config['privateKeyPath']);
        $signature = '';
        openssl_sign($message, $signature, $privateKey, OPENSSL_ALGO_SHA256);

        return [
            'appId' => $config['appid'],
            'timeStamp' => $timeStamp,
            'nonceStr' => $nonceStr,
            'package' => $package,
            'signType' => 'RSA',
            'paySign' => base64_encode($signature),
            'out_trade_no' => $outTradeNo,
        ];
    }

    /** 回调验签：微信平台证书公钥 RSA-SHA256 验 message=timestamp\nnonce\nbody */
    private function verifyPlatformSignature(string $certPath, string $body, string $timestamp, string $nonce, string $signature): bool
    {
        if (!is_file($certPath)) {
            return false;
        }
        $pem = (string) file_get_contents($certPath);
        $cert = openssl_x509_read($pem);
        if ($cert === false) {
            return false;
        }
        $publicKey = openssl_pkey_get_public($cert);
        if ($publicKey === false) {
            return false;
        }
        $message = $timestamp . "\n" . $nonce . "\n" . $body . "\n";
        $ok = openssl_verify($message, base64_decode($signature), $publicKey, OPENSSL_ALGO_SHA256);

        return $ok === 1;
    }

    /** AES-256-GCM 解密微信回调 resource（返回金额分） */
    /** AES-256-GCM 解密微信回调 resource，返回解密后的完整交易明文（订单号/金额/状态都在里面） */
    private function decryptResource(array $resource, string $apiV3Key): array
    {
        $key = $apiV3Key;
        $ciphertext = base64_decode((string) $resource['ciphertext'], true);
        if ($ciphertext === false || strlen($ciphertext) <= 16) {
            throw new RuntimeException('ciphertext too short');
        }
        $nonce = (string) $resource['nonce'];
        $aad = (string) ($resource['associated_data'] ?? '');
        $authTag = substr($ciphertext, -16);
        $data = substr($ciphertext, 0, -16);
        $decrypted = openssl_decrypt($data, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $authTag, $aad);
        if ($decrypted === false) {
            throw new RuntimeException('aes decrypt failed');
        }
        $parsed = json_decode($decrypted, true);
        if (!is_array($parsed)) {
            throw new RuntimeException('decrypted payload invalid');
        }

        return $parsed;
    }

    private function readPrivateKey(string $path): string
    {
        if ($path === '' || !is_file($path)) {
            throw new MemberTransactionException(503, 'wxpay_config_incomplete', '微信支付私钥未配置');
        }
        $key = (string) file_get_contents($path);

        return $key;
    }

    private function markClosed(int $orderId, array $wxResult): void
    {
        Db::table('ch_wechat_pay_order')->where('id', $orderId)->update([
            'status' => 'closed',
            'notify_payload' => mb_substr(json_encode($wxResult, JSON_UNESCAPED_UNICODE) ?: '{}', 0, 4096),
            'update_time' => time(),
        ]);
    }

    private function orderPayload(array $order): array
    {
        return [
            'status' => (string) $order['status'],
            'out_trade_no' => (string) $order['out_trade_no'],
            'amount_cents' => (int) $order['amount_cents'],
            'business_type' => (string) $order['business_type'],
            'business_ref' => (int) $order['business_ref'],
        ];
    }

    private function identity(TenantContext $tenant, AuthenticatedUserContext $auth): array
    {
        return (new MemberIdentityService())->resolve($tenant, $auth);
    }

    private function headerValue(array $headers, string $key): string
    {
        $value = $headers[$key] ?? $headers[strtolower($key)] ?? '';
        if (is_array($value)) {
            return (string) ($value[0] ?? '');
        }

        return (string) $value;
    }
}
