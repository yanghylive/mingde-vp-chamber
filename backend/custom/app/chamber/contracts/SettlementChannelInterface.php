<?php

declare(strict_types=1);

namespace app\chamber\contracts;

/**
 * 分账结算通道抽象。每个真实通道（商家转账/微信分账/对公银行）实现本接口，
 * 供 SettlementService 按 ch_settlement_detail.channel 分发执行。
 */
interface SettlementChannelInterface
{
    /**
     * 执行一笔打款。
     *
     * @param array $detail ch_settlement_detail 一行（含 id/amount/receiver_id/receiver_name/channel）
     * @return array ['channel_order_no' => string, 'raw' => string]
     * @throws \app\chamber\exceptions\MemberTransactionException 打款失败时抛出
     */
    public function pay(array $detail): array;
}
