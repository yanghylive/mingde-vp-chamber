<?php

declare(strict_types=1);

namespace app\chamber\services;

use app\chamber\contracts\SettlementChannelInterface;
use app\chamber\exceptions\MemberTransactionException;
use app\services\wechat\WechatUserServices;
use crmeb\services\pay\Pay;
use think\facade\Db;

/**
 * 商家转账到零钱通道（对私：大咖个人收款）。
 *
 * 复用 CRMEB「佣金提现」的成熟实现（UserExtractServices::v3Extract），
 * 微信 APIv3 商家转账（merchantPayNew / merchantPay）。
 *
 * 前置：微信支付商户号已配置（pay_weixin_mchid + v3 证书 + v3_pay_public_key），
 *      且接收方（大咖）已在 eb_wechat_user 绑定 openid（小程序登录即绑定）。
 */
final class MerchantTransferChannel implements SettlementChannelInterface
{
    public function pay(array $detail): array
    {
        $receiverId = (int) $detail['receiver_id'];
        $amount = (string) $detail['amount'];

        // 1. 解析 openid：receiver_id（member_id）→ uid → openid
        $member = Db::table('ch_tenant_member')
            ->where('id', $receiverId)
            ->where('is_del', 0)
            ->find();
        if (!is_array($member)) {
            throw new MemberTransactionException(409, 'receiver_member_missing', '接收方会员不存在');
        }
        /** @var WechatUserServices $wechatServices */
        $wechatServices = app()->make(WechatUserServices::class);
        $openid = $wechatServices->uidToOpenid((int) $member['uid'], 'routine');
        if (!$openid) {
            $openid = $wechatServices->uidToOpenid((int) $member['uid'], 'wechat');
        }
        if (!$openid) {
            throw new MemberTransactionException(409, 'receiver_openid_missing', '接收方未绑定微信，无法自动转账');
        }

        // 2. 转账单号 + 金额（元 → 分）
        $orderId = 'ST' . date('YmdHis') . $detail['id'];
        $amountMinor = bcmul($amount, '100', 0);

        // 3. 调 CRMEB 微信 v3 商家转账
        $pay = new Pay('v3_wechat_pay');
        if (sys_config('v3_pay_public_key') !== '') {
            $res = $pay->merchantPayNew(
                'mini',
                $orderId,
                sys_config('v3_transfer_scene_id', '1000'),
                $openid,
                (string) $detail['receiver_name'],
                $amountMinor,
                '明德商会分账结算',
                sys_config('site_url') . '/api/transfer/notify/mini',
                '劳务报酬',
                [
                    ['info_type' => '报酬说明', 'info_content' => '大咖服务结算'],
                ]
            );
        } else {
            $res = $pay->merchantPay($openid, $orderId, $amount, [
                'type' => 'mini',
                'batch_name' => '明德商会分账结算',
                'batch_remark' => '大咖服务结算 ' . $amount . ' 元',
            ]);
        }

        if (!$res) {
            throw new MemberTransactionException(502, 'transfer_failed', '商家转账失败，请稍后重试');
        }

        return [
            'channel_order_no' => (string) ($res['transfer_bill_no'] ?? ($res['out_bill_no'] ?? $orderId)),
            'raw' => json_encode($res, JSON_UNESCAPED_UNICODE),
        ];
    }
}
