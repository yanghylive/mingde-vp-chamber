<?php

declare(strict_types=1);

namespace app\chamber\controller;

use app\Request;
use app\chamber\commerce\Money;
use app\chamber\exceptions\MemberTransactionException;
use app\chamber\identity\AuthenticatedUserContext;
use app\chamber\services\ChamberWechatPayService;
use app\chamber\services\MemberIdentityService;
use app\chamber\tenancy\TenantContext;
use think\facade\Db;
use think\Response;

/**
 * 微信支付（APIv3 直连，与 3010 ai-content 同一套逻辑）
 *   POST /api/chamber/v1/wechat-pay/orders         创建支付单（认证）
 *   POST /api/chamber/v1/wechat-pay/notify         微信支付回调（公开）
 *   GET  /api/chamber/v1/wechat-pay/orders/:out_trade_no  查询支付单（认证）
 *   GET  /api/chamber/v1/wechat-pay/config-status  配置就绪检查（公开，脱敏）
 */
final class ChamberWechatPayController
{
    private const MAX_BODY_BYTES = 16384;

    /** @var ChamberWechatPayService */
    private $pay;

    public function __construct(?ChamberWechatPayService $pay = null)
    {
        $this->pay = $pay ?: new ChamberWechatPayService();
    }

    /** 创建支付单（JSAPI 小程序拉起 / NATIVE 扫码）。
     *  membership：body 传 order_no（CRMEB 订单号），后端解析 order_pk；openid 按 uid 从 eb_user 反查。
     *  exchange：body 传 business_ref（ch_product_exchange_order.id）。
     */
    public function orders(Request $request, TenantContext $tenant, AuthenticatedUserContext $auth): Response
    {
        $body = $this->decodeJson($request);
        $idempotencyKey = trim((string) ($body['idempotency_key'] ?? ''));
        $businessType = (string) ($body['business_type'] ?? '');
        $businessRef = (int) ($body['business_ref'] ?? 0);
        $orderNo = trim((string) ($body['order_no'] ?? ''));
        $openid = trim((string) ($body['openid'] ?? ''));
        $tradeType = (string) ($body['trade_type'] ?? 'JSAPI');
        $uid = $auth->uid();
        $memberId = $this->memberId($tenant, $auth);

        // 安全约束：金额与订单归属一律由服务端从业务单计算，不信任客户端 amount_cents
        $amountCents = 0;
        if ($businessType === ChamberWechatPayService::BUSINESS_MEMBERSHIP) {
            if ($orderNo === '') {
                throw new MemberTransactionException(422, 'order_no_required', '会员支付需要订单号');
            }
            // 归属校验：订单必须属于当前用户，防替他人订单买单/低价开通
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
            // 应付金额：以 CRMEB 订单 pay_price 为准（客户端 amount_cents 一律忽略）
            $amountCents = Money::toMinor((string) ($order['pay_price'] ?? '0.00'));
        } elseif ($businessType === ChamberWechatPayService::BUSINESS_EXCHANGE) {
            if ($businessRef <= 0) {
                throw new MemberTransactionException(422, 'business_ref_required', '兑换支付需要订单号');
            }
            // 归属校验：兑换订单必须属于当前会员
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
        if ($openid === '') {
            $openid = $this->memberOpenid($auth);
        }

        $data = $this->pay->createPayment(
            $tenant,
            $auth,
            $amountCents,
            $this->description($businessType, $amountCents),
            $idempotencyKey,
            $businessType,
            $businessRef,
            $openid,
            $tradeType
        );

        return json(['code' => 0, 'msg' => 'ok', 'data' => $data]);
    }

    /** 微信支付回调（公开，微信服务器调用；验签/解密/幂等/入账） */
    public function notify(Request $request): Response
    {
        $headers = [];
        foreach ($request->header() as $key => $value) {
            $headers[$key] = $value;
        }
        $rawBody = (string) $request->getContent();
        $result = $this->pay->handleNotify($headers, $rawBody);

        return Response::create([
            'code' => $result['code'],
            'message' => $result['message'],
        ], 'json', $result['code'] === 'SUCCESS' ? 200 : 500);
    }

    /** 查询支付单状态（前端轮询） */
    public function order(Request $request, TenantContext $tenant, AuthenticatedUserContext $auth, $out_trade_no): Response
    {
        unset($request);
        $outTradeNo = (string) $out_trade_no;
        if ($outTradeNo === '' || strlen($outTradeNo) > 64) {
            throw new MemberTransactionException(422, 'invalid_out_trade_no', 'out_trade_no 非法');
        }

        return json(['code' => 0, 'msg' => 'ok', 'data' => $this->pay->getOrderStatus($tenant, $auth, $outTradeNo)]);
    }

    /** 配置就绪检查（脱敏；公开路由，无 tenant 上下文） */
    public function configStatus(Request $request): Response
    {
        unset($request);

        return json(['code' => 0, 'msg' => 'ok', 'data' => $this->pay->configStatus()]);
    }

    private function decodeJson(Request $request): array
    {
        $raw = (string) $request->getContent();
        if (strlen($raw) > self::MAX_BODY_BYTES) {
            throw new MemberTransactionException(413, 'payload_too_large', '请求体过大');
        }
        $body = json_decode($raw, true);

        return is_array($body) ? $body : [];
    }

    /** 按认证用户反查 CRMEB 小程序 openid（JSAPI 支付 payer 需要） */
    private function memberOpenid(AuthenticatedUserContext $auth): string
    {
        $uid = $auth->uid();
        if ($uid <= 0) {
            return '';
        }
        $openid = (string) Db::table('eb_user')->where('uid', $uid)->value('openid');

        return trim($openid);
    }

    /** 当前会员 id（ch_tenant_member.id） */
    private function memberId(TenantContext $tenant, AuthenticatedUserContext $auth): int
    {
        $member = (new MemberIdentityService())->resolve($tenant, $auth);

        return (int) $member['id'];
    }

    /** 支付单描述（服务端生成，不信任客户端） */
    private function description(string $businessType, int $amountCents): string
    {
        if ($businessType === ChamberWechatPayService::BUSINESS_MEMBERSHIP) {
            return '明德商会会籍费 ¥' . number_format($amountCents / 100, 2, '.', '');
        }

        return '积分兑换补差价 ¥' . number_format($amountCents / 100, 2, '.', '');
    }
}
