<?php

declare(strict_types=1);

namespace app\chamber\services;

use app\chamber\contracts\SettlementChannelInterface;
use app\chamber\exceptions\MemberTransactionException;

/**
 * 微信分账通道（对公：三公司 4:4:2）。
 *
 * 与「商家转账」不同，微信「分账」需要平台具备【服务商 + 电商收付通】资质，
 * 且接收方（三公司）须为微信支付商户。CRMEB 未封装分账 API，需直连微信 APIv3。
 *
 * 对接要点（资质到位后按微信官方文档实现）：
 *   1. 请求分账  POST /v3/profitsharing/orders
 *      body: {appid, transaction_id(原支付单号), out_order_no, receivers:[
 *              {type:'MERCHANT_ID', account:'接收方商户号', amount:分, description:'xx'}
 *            ], unfreeze_unsplit:true}
 *   2. 接收方 account = 三公司的微信支付商户号（对公）
 *   3. 分账默认上限 30%，4:4:2 的 40% 需向微信申请提额
 *   4. 查询分账  GET /v3/profitsharing/orders/{out_order_no}
 *   5. 签名走微信 APIv3（商户号 + 证书私钥 + 序列号）
 */
final class WechatSplitChannel implements SettlementChannelInterface
{
    public function pay(array $detail): array
    {
        // 骨架：待「服务商 + 电商收付通」资质 + 接收方商户号就绪后接入
        // 未接入前抛明确错误，结算单会进入 failed + 重试，不会误打款
        throw new MemberTransactionException(
            501,
            'channel_not_ready',
            '微信分账通道待服务商资质开通后接入'
        );
    }
}
