<?php

declare(strict_types=1);

namespace app\chamber\services;

use app\chamber\contracts\SettlementChannelInterface;
use app\chamber\exceptions\MemberTransactionException;

/**
 * 对公银行转账通道（三公司 4:4:2 的对公打款）。
 *
 * 适用于「不满足微信服务商分账资质」时的替代方案：
 * 平台用企业网银/银行代发 API，把款项打给三公司的对公账户。
 *
 * 对接要点（按所选银行代发产品实现）：
 *   1. 接收方 = 三公司的对公账户（户名/账号/开户行）
 *   2. 平台开通银行「代发/批量转账」API（需对公网银签约）
 *   3. 结算日批量提交打款指令 → 银行异步处理 → 查询回执
 *   4. 幂等：以结算明细 id 做幂等键，防重复打款
 *
 * @deprecated 自 2026-08-18 废弃：资金链路统一到汇付天下，对公银行转账不再接入。
 *             冻结待下线：汇付 adapter（PR-02~05）上线后再清理本类。
 *             替代：汇付结算/分账。
 */
final class BankTransferChannel implements SettlementChannelInterface
{
    public function pay(array $detail): array
    {
        // 骨架：待对接企业网银/银行代发 API 后接入
        throw new MemberTransactionException(
            501,
            'channel_not_ready',
            '对公银行转账通道待网银代发 API 接入'
        );
    }
}
