<?php

declare(strict_types=1);

namespace app\chamber\controller;

use app\Request;
use app\chamber\commerce\Money;
use app\chamber\exceptions\MemberTransactionException;
use app\chamber\identity\AuthenticatedUserContext;
use app\chamber\services\ChamberWechatPayService;
use app\chamber\services\MemberIdentityService;
use app\chamber\services\VpayService;
use app\chamber\tenancy\TenantContext;
use think\facade\Db;
use think\facade\Log;
use think\Response;

/**
 * 微信小程序虚拟支付（Midas / wx.requestVirtualPayment）
 *   POST /api/chamber/v1/vpay/orders       创建虚拟支付单（认证）→ 返回 signData/paySig/signature/mode
 *   POST /api/chamber/v1/vpay/notify       Midas 回调 xpay_goods_deliver_notify（公开）
 *   GET  /api/chamber/v1/vpay/config-status 配置就绪检查（公开，脱敏）
 *
 * 与 wechat-pay（JSAPI，实物/线下）区分：虚拟商品（会籍/积分补差/付费活动）必须走本通道。
 */
final class ChamberVpayController
{
    private const MAX_BODY_BYTES = 16384;

    /** @var VpayService */
    private $vpay;
    /** @var ChamberWechatPayService */
    private $wxpay;

    public function __construct(?VpayService $vpay = null, ?ChamberWechatPayService $wxpay = null)
    {
        $this->vpay = $vpay ?: new VpayService();
        $this->wxpay = $wxpay ?: new ChamberWechatPayService();
    }

    /** 创建虚拟支付单（认证）：金额与归属一律服务端计算，不信任客户端 */
    public function orders(Request $request, TenantContext $tenant, AuthenticatedUserContext $auth): Response
    {
        if (!$this->vpay->isConfigured()) {
            throw new MemberTransactionException(503, 'vpay_not_configured', '虚拟支付未配置完成，暂不可用');
        }
        $body = $this->decodeJson($request);
        $businessType = (string) ($body['business_type'] ?? '');
        $orderNo = trim((string) ($body['order_no'] ?? ''));
        $businessRef = (int) ($body['business_ref'] ?? 0);
        $idempotencyKey = trim((string) ($body['idempotency_key'] ?? ''));
        $planTier = (int) ($body['plan_tier'] ?? 0);
        $uid = $auth->uid();
        $memberId = $this->memberId($tenant, $auth);

        // 安全约束：金额与订单归属由服务端从业务单计算
        $amountCents = 0;
        if ($businessType === ChamberWechatPayService::BUSINESS_MEMBERSHIP) {
            if ($orderNo === '') {
                throw new MemberTransactionException(422, 'order_no_required', '会员支付需要订单号');
            }
            $order = Db::table('eb_store_order')
                ->where('order_id', $orderNo)
                ->where('uid', $uid)
                ->where('is_del', 0)
                ->find();
            if (!is_array($order)) {
                throw new MemberTransactionException(404, 'order_not_found', '订单不存在或不属于当前账号');
            }
            if ((int) $order['paid'] === 1) {
                throw new MemberTransactionException(409, 'order_already_paid', '订单已支付');
            }
            $businessRef = (int) $order['id'];
            $amountCents = Money::toMinor((string) ($order['pay_price'] ?? '0.00'));
        } elseif ($businessType === ChamberWechatPayService::BUSINESS_EXCHANGE) {
            if ($businessRef <= 0) {
                throw new MemberTransactionException(422, 'business_ref_required', '兑换支付需要订单号');
            }
            $exchangeOrder = Db::table('ch_product_exchange_order')
                ->where('id', $businessRef)
                ->where('tenant_id', $tenant->tenantId())
                ->where('member_id', $memberId)
                ->find();
            if (!is_array($exchangeOrder)) {
                throw new MemberTransactionException(404, 'exchange_order_not_found', '兑换订单不存在或不属于当前账号');
            }
            if ((string) $exchangeOrder['status'] !== 'pending') {
                throw new MemberTransactionException(409, 'exchange_order_not_payable', '当前兑换订单状态不允许支付');
            }
            $amountCents = Money::toMinor((string) ($exchangeOrder['cash_cost'] ?? '0.00'));
        } else {
            throw new MemberTransactionException(422, 'invalid_business_type', 'Business type 非法');
        }
        if ($amountCents <= 0) {
            throw new MemberTransactionException(409, 'zero_amount', '该订单应付金额为 0，无需支付');
        }

        // 幂等落单（复用 ch_wechat_pay_order）
        $outTradeNo = $idempotencyKey !== ''
            ? $idempotencyKey
            : 'VPY' . date('YmdHis') . $businessRef;
        $existing = Db::table('ch_wechat_pay_order')
            ->where('out_trade_no', $outTradeNo)
            ->find();
        if (is_array($existing)) {
            if ((string) $existing['business_type'] !== $businessType
                || (int) $existing['business_ref'] !== $businessRef
                || (int) $existing['amount_cents'] !== $amountCents
                || (int) $existing['user_id'] !== $uid) {
                throw new MemberTransactionException(409, 'idempotency_conflict', '幂等键已被其他支付请求占用');
            }
            if ((string) $existing['status'] === 'closed') {
                Db::table('ch_wechat_pay_order')->where('id', (int) $existing['id'])->delete();
                $existing = null;
            } else {
                // 已存在 pending 单：本地签名可重放，直接返回新签名
                return json(['code' => 0, 'msg' => 'ok', 'data' => $this->vpay->buildPayParams(
                    $this->payParams($businessType, $amountCents, $outTradeNo, $planTier),
                    $this->sessionKey($uid)
                )]);
            }
        }

        $now = time();
        Db::table('ch_wechat_pay_order')->insert([
            'tenant_id' => $tenant->tenantId(),
            'user_id' => $uid,
            'member_id' => $memberId,
            'out_trade_no' => $outTradeNo,
            'mchid' => '',
            'appid' => '',
            'description' => $this->description($businessType, $amountCents),
            'amount_cents' => $amountCents,
            'currency' => 'CNY',
            'business_type' => $businessType,
            'business_ref' => $businessRef,
            'trade_type' => 'VPY',
            'status' => 'pending',
            'add_time' => $now,
            'update_time' => $now,
        ]);

        $params = $this->vpay->buildPayParams(
            $this->payParams($businessType, $amountCents, $outTradeNo, $planTier),
            $this->sessionKey($uid)
        );

        return json(['code' => 0, 'msg' => 'ok', 'data' => $params]);
    }

    /** Midas 回调 xpay_goods_deliver_notify（公开）：验签 → 幂等 → 发货 */
    public function notify(Request $request): Response
    {
        $raw = (string) $request->getContent();
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            return json(['code' => 1, 'msg' => 'invalid payload'])->code(400);
        }
        if (!$this->vpay->verifyNotifySignature($payload)) {
            Log::warning('chamber.vpay.notify_sign_fail', ['payload_keys' => array_keys($payload)]);
            return json(['code' => 1, 'msg' => 'sign error'])->code(403);
        }
        $outTradeNo = trim((string) ($payload['out_trade_no'] ?? ($payload['outTradeNo'] ?? '')));
        $transactionId = trim((string) ($payload['transaction_id'] ?? ($payload['transactionId'] ?? '')));
        if ($outTradeNo === '') {
            return json(['code' => 1, 'msg' => 'out_trade_no missing'])->code(400);
        }

        $order = Db::table('ch_wechat_pay_order')->where('out_trade_no', $outTradeNo)->find();
        if (!is_array($order)) {
            Log::warning('chamber.vpay.notify_order_missing', ['out_trade_no' => $outTradeNo]);
            return json(['code' => 1, 'msg' => 'order not found'])->code(404);
        }
        // 幂等：已 paid 直接成功
        if ((string) $order['status'] === 'paid') {
            return json(['code' => 0, 'msg' => 'ok']);
        }
        // 金额一致性（字段名以米大师回调文档为准，缺失则跳过）
        $callbackAmount = (int) ($payload['pay_amount'] ?? ($payload['goods_price'] ?? 0));
        if ($callbackAmount > 0 && $callbackAmount !== (int) $order['amount_cents']) {
            Log::error('chamber.vpay.notify_amount_mismatch', [
                'order_id' => (int) $order['id'],
                'callback_amount' => $callbackAmount,
                'db_amount' => (int) $order['amount_cents'],
            ]);
            return json(['code' => 1, 'msg' => 'amount mismatch'])->code(409);
        }

        // 发货：复用现有业务事实确认（membership→会员升级 / exchange→兑换单置 paid）
        $this->wxpay->settleBusiness(
            (int) $order['tenant_id'],
            (string) $order['business_type'],
            (int) $order['business_ref'],
            $transactionId
        );
        Db::table('ch_wechat_pay_order')
            ->where('id', (int) $order['id'])
            ->update(['status' => 'paid', 'update_time' => time()]);

        return json(['code' => 0, 'msg' => 'ok']);
    }

    /** 配置就绪检查（公开，脱敏） */
    public function configStatus(Request $request): Response
    {
        return json([
            'code' => 0,
            'msg' => 'ok',
            'data' => [
                'configured' => $this->vpay->isConfigured(),
                'env' => $this->vpay->envFlag()
            ]
        ]);
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    private function decodeJson(Request $request): array
    {
        $raw = (string) $request->getContent();
        if (strlen($raw) > self::MAX_BODY_BYTES) {
            throw new MemberTransactionException(413, 'payload_too_large', '请求体过大');
        }
        $body = json_decode($raw, true);

        return is_array($body) ? $body : [];
    }

    /** 当前会员 id（ch_tenant_member.id） */
    private function memberId(TenantContext $tenant, AuthenticatedUserContext $auth): int
    {
        $member = (new MemberIdentityService())->resolve($tenant, $auth);

        return (int) $member['id'];
    }

    /** 用户 session_key（虚拟支付 signature 计算需要；缺失则要求重新登录） */
    private function sessionKey(int $uid): string
    {
        $key = (string) Db::table('eb_wechat_user')->where('uid', $uid)->value('session_key');
        if (trim($key) === '') {
            throw new MemberTransactionException(409, 'session_key_missing', '微信登录态缺失，请重新登录');
        }

        return trim($key);
    }

    /** 虚拟支付道具映射（productId 需在微信虚拟支付后台配置对应道具） */
    private function payParams(string $businessType, int $amountCents, string $outTradeNo, int $planTier = 0): array
    {
        $productId = 'vip_yearly';
        if ($businessType === ChamberWechatPayService::BUSINESS_MEMBERSHIP) {
            // 会籍按档位映射道具（后台需建 vip_yearly_t2 / vip_yearly_t3 等道具）
            $productId = 'vip_yearly_t' . max(1, min(9, $planTier));
        } elseif ($businessType === ChamberWechatPayService::BUSINESS_EXCHANGE) {
            // 积分补差为动态金额，道具直购模式不适用，暂未启用
            $productId = 'exchange_cash';
        }

        return [
            'product_id' => $productId,
            'amount_cents' => $amountCents,
            'out_trade_no' => $outTradeNo,
            'attach' => $businessType
        ];
    }

    /** 支付单描述（服务端生成） */
    private function description(string $businessType, int $amountCents): string
    {
        $amount = number_format($amountCents / 100, 2, '.', '');
        if ($businessType === ChamberWechatPayService::BUSINESS_MEMBERSHIP) {
            return '明德商会会籍费 ¥' . $amount;
        }
        if ($businessType === ChamberWechatPayService::BUSINESS_EXCHANGE) {
            return '积分商城兑换补差 ¥' . $amount;
        }

        return '明德商会虚拟商品 ¥' . $amount;
    }
}
