<?php

declare(strict_types=1);

namespace app\chamber\controller;

use app\Request;
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
        $amountCents = (int) ($body['amount_cents'] ?? 0);
        $idempotencyKey = trim((string) ($body['idempotency_key'] ?? ''));
        $businessType = (string) ($body['business_type'] ?? '');
        $businessRef = (int) ($body['business_ref'] ?? 0);
        $orderNo = trim((string) ($body['order_no'] ?? ''));
        $openid = trim((string) ($body['openid'] ?? ''));
        $tradeType = (string) ($body['trade_type'] ?? 'JSAPI');
        $description = trim((string) ($body['description'] ?? '微信支付'));

        // membership：按 CRMEB 订单号解析 order_pk；openid 后端按 uid 反查（小程序登录已存）
        if ($businessType === ChamberWechatPayService::BUSINESS_MEMBERSHIP) {
            if ($orderNo === '') {
                throw new MemberTransactionException(422, 'order_no_required', '会员支付需要订单号');
            }
            $orderPk = (int) Db::table('eb_store_order')
                ->where('order_id', $orderNo)
                ->where('is_del', 0)
                ->value('id');
            if ($orderPk <= 0) {
                throw new MemberTransactionException(404, 'order_not_found', '订单不存在');
            }
            $businessRef = $orderPk;
        }
        if ($openid === '') {
            $openid = $this->memberOpenid($auth);
        }

        $data = $this->pay->createPayment(
            $tenant,
            $auth,
            $amountCents,
            $description,
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
}
