<?php

declare(strict_types=1);

namespace app\chamber\services;

use app\chamber\commerce\Money;
use app\chamber\contracts\SettlementChannelInterface;
use app\chamber\exceptions\MemberTransactionException;
use think\facade\Db;

/**
 * 分账系统：规则配置化 + 分账单生成 + T+1 结算 + 下期抵扣。
 *
 * 场景：
 *   ① 会员费 → 三公司 4:4:2 对公分账（receiver_type=company）
 *   ② 平台收入 → 按比例给大咖结算（receiver_type=expert，个人走商家转账）
 *
 * 金额统一用「分」（int）计算，落库转「元」（两位小数字符串）。
 * 尾差（floor 除法产生的几分）归平台留存，不生成明细。
 * 退款采用「下期抵扣」：退款时不追回已分账，记 ch_settlement_balance 待抵扣，
 * 下次结算该接收方时先扣抵扣余额再分。
 */
final class SettlementService
{
    /** 业务类型 */
    public const BUSINESS_MEMBERSHIP = 'membership_fee';
    public const BUSINESS_APPOINTMENT = 'appointment';
    public const BUSINESS_EVENT = 'event';
    public const BUSINESS_EXPERT_SERVICE = 'expert_service';

    /** 通道 */
    public const CHANNEL_MERCHANT_TRANSFER = 'merchant_transfer'; // 商家转账到零钱（对私）
    public const CHANNEL_BANK = 'bank';                            // 对公银行转账
    public const CHANNEL_WECHAT_SPLIT = 'wechat_split';            // 微信分账（对公）

    /** 分账规则列表（启用中） */
    public function rules(int $tenantId, string $businessType): array
    {
        $rows = Db::table('ch_settlement_rule')
            ->where('tenant_id', $tenantId)
            ->where('business_type', $businessType)
            ->where('status', 1)
            ->where('is_del', 0)
            ->order('sort', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();

        $items = [];
        foreach ($rows as $r) {
            $items[] = [
                'id' => (int) $r['id'],
                'business_type' => (string) $r['business_type'],
                'receiver_type' => (string) $r['receiver_type'],
                'receiver_id' => (int) $r['receiver_id'],
                'receiver_name' => (string) $r['receiver_name'],
                'ratio' => (int) $r['ratio'],
                'channel' => (string) $r['channel'],
            ];
        }

        return $items;
    }

    /** 保存分账规则（整包替换，比例可改）。$rules 每项：receiver_type/receiver_id/receiver_name/ratio/channel */
    public function saveRules(int $tenantId, string $businessType, array $rules): void
    {
        $total = 0;
        $normalized = [];
        foreach ($rules as $i => $r) {
            if (!is_array($r)) {
                continue;
            }
            $ratio = (int) ($r['ratio'] ?? 0);
            $receiverType = (string) ($r['receiver_type'] ?? 'company');
            if (!in_array($receiverType, ['company', 'expert', 'individual'], true)) {
                $receiverType = 'company';
            }
            $channel = (string) ($r['channel'] ?? self::CHANNEL_MERCHANT_TRANSFER);
            if (!in_array($channel, [self::CHANNEL_MERCHANT_TRANSFER, self::CHANNEL_BANK, self::CHANNEL_WECHAT_SPLIT], true)) {
                $channel = self::CHANNEL_MERCHANT_TRANSFER;
            }
            if ($ratio < 0 || $ratio > 100) {
                throw new MemberTransactionException(422, 'invalid_ratio', '分账比例必须在 0-100 之间');
            }
            $total += $ratio;
            $normalized[] = [
                'receiver_type' => $receiverType,
                'receiver_id' => (int) ($r['receiver_id'] ?? 0),
                'receiver_name' => trim((string) ($r['receiver_name'] ?? '')),
                'ratio' => $ratio,
                'channel' => $channel,
                'sort' => $i,
            ];
        }
        if ($total > 100) {
            throw new MemberTransactionException(422, 'invalid_ratio', '分账比例之和不能超过 100');
        }

        $now = time();
        Db::transaction(function () use ($tenantId, $businessType, $normalized, $now): void {
            Db::table('ch_settlement_rule')
                ->where('tenant_id', $tenantId)
                ->where('business_type', $businessType)
                ->update(['is_del' => 1, 'update_time' => $now]);
            foreach ($normalized as $r) {
                Db::table('ch_settlement_rule')->insert([
                    'tenant_id' => $tenantId,
                    'business_type' => $businessType,
                    'receiver_type' => $r['receiver_type'],
                    'receiver_id' => $r['receiver_id'],
                    'receiver_name' => $r['receiver_name'],
                    'ratio' => $r['ratio'],
                    'channel' => $r['channel'],
                    'status' => 1,
                    'sort' => $r['sort'],
                    'is_del' => 0,
                    'add_time' => $now,
                    'update_time' => $now,
                ]);
            }
        });
    }

    /**
     * 生成分账单（支付完成后调用）：算明细 + 尾差归平台 + 下期抵扣。
     * 幂等：同 (business_type, order_no) 只生成一次。
     */
    public function settle(int $tenantId, string $businessType, string $orderNo, string $orderAmount): array
    {
        $rules = $this->rules($tenantId, $businessType);
        if (!$rules) {
            return ['skipped' => true, 'reason' => 'no_rule'];
        }

        $existing = Db::table('ch_settlement')
            ->where('tenant_id', $tenantId)
            ->where('business_type', $businessType)
            ->where('order_no', $orderNo)
            ->find();
        if (is_array($existing)) {
            return ['skipped' => true, 'reason' => 'exists', 'id' => (int) $existing['id']];
        }

        $orderMinor = Money::toMinor($orderAmount);
        $now = time();

        $settlementId = Db::transaction(function () use ($tenantId, $businessType, $orderNo, $orderMinor, $rules, $now): int {
            $totalRatio = 0;
            foreach ($rules as $r) {
                $totalRatio += (int) $r['ratio'];
            }

            $settlementId = (int) Db::table('ch_settlement')->insertGetId([
                'tenant_id' => $tenantId,
                'business_type' => $businessType,
                'order_no' => $orderNo,
                'order_amount' => $this->minorToYuan($orderMinor),
                'total_ratio' => $totalRatio,
                'status' => 'pending',
                'settle_time' => 0,
                'add_time' => $now,
                'update_time' => $now,
            ]);

            foreach ($rules as $rule) {
                // floor 除法，尾差归平台留存
                $detailMinor = (int) floor($orderMinor * (int) $rule['ratio'] / 100);
                $detailMinor = $this->applyDebit($tenantId, $rule, $detailMinor, $now);

                if ($detailMinor > 0) {
                    Db::table('ch_settlement_detail')->insert([
                        'settlement_id' => $settlementId,
                        'tenant_id' => $tenantId,
                        'receiver_type' => (string) $rule['receiver_type'],
                        'receiver_id' => (int) $rule['receiver_id'],
                        'receiver_name' => (string) $rule['receiver_name'],
                        'ratio' => (int) $rule['ratio'],
                        'amount' => $this->minorToYuan($detailMinor),
                        'channel' => (string) $rule['channel'],
                        'channel_ref' => '',
                        'status' => 'pending',
                        'fail_reason' => '',
                        'retry_count' => 0,
                        'settled_time' => 0,
                        'add_time' => $now,
                        'update_time' => $now,
                    ]);
                }
            }

            return $settlementId;
        });

        return ['skipped' => false, 'id' => $settlementId];
    }

    /**
     * T+1 结算执行：扫 pending/failed 的明细，按通道打款。
     * 由 cron 调用（可幂等重复跑）。
     */
    public function runDue(int $limit = 50): array
    {
        $now = time();
        $rows = Db::table('ch_settlement_detail')
            ->whereIn('status', ['pending', 'failed'])
            ->where('retry_count', '<', 3)
            ->order('id', 'asc')
            ->limit($limit)
            ->select()
            ->toArray();

        $done = 0;
        $failed = 0;
        foreach ($rows as $detail) {
            try {
                $this->executeDetail((int) $detail['id'], $now);
                $done++;
            } catch (\Throwable $e) {
                $failed++;
                Db::table('ch_settlement_detail')
                    ->where('id', (int) $detail['id'])
                    ->update([
                        'status' => 'failed',
                        'fail_reason' => mb_substr($e->getMessage(), 0, 255),
                        'retry_count' => (int) $detail['retry_count'] + 1,
                        'update_time' => $now,
                    ]);
            }
        }

        $this->closeCompleted($now);

        return ['scanned' => count($rows), 'done' => $done, 'failed' => $failed];
    }

    /** 退款下期抵扣：接收方应退金额记入抵扣余额，下次结算少分 */
    public function recordRefundDebit(int $tenantId, string $receiverType, int $receiverId, string $receiverName, string $amount): void
    {
        $minor = Money::toMinor($amount);
        $now = time();
        $row = Db::table('ch_settlement_balance')
            ->where('tenant_id', $tenantId)
            ->where('receiver_type', $receiverType)
            ->where('receiver_id', $receiverId)
            ->find();
        if (is_array($row)) {
            $newMinor = (int) round((float) $row['balance'] * 100) + $minor;
            Db::table('ch_settlement_balance')
                ->where('id', (int) $row['id'])
                ->update([
                    'balance' => $this->minorToYuan($newMinor),
                    'receiver_name' => $receiverName !== '' ? $receiverName : $row['receiver_name'],
                    'update_time' => $now,
                ]);
        } else {
            Db::table('ch_settlement_balance')->insert([
                'tenant_id' => $tenantId,
                'receiver_type' => $receiverType,
                'receiver_id' => $receiverId,
                'receiver_name' => $receiverName,
                'balance' => $this->minorToYuan($minor),
                'add_time' => $now,
                'update_time' => $now,
            ]);
        }
    }

    /** 抵扣余额查询（对账用） */
    public function balances(int $tenantId): array
    {
        $rows = Db::table('ch_settlement_balance')
            ->where('tenant_id', $tenantId)
            ->where('balance', '>', 0)
            ->order('id', 'asc')
            ->select()
            ->toArray();

        $items = [];
        foreach ($rows as $r) {
            $items[] = [
                'id' => (int) $r['id'],
                'receiver_type' => (string) $r['receiver_type'],
                'receiver_id' => (int) $r['receiver_id'],
                'receiver_name' => (string) $r['receiver_name'],
                'balance' => (string) $r['balance'],
            ];
        }

        return $items;
    }

    // ------------------------------------------------------------------
    // 私有
    // ------------------------------------------------------------------

    /** 下期抵扣：扣减明细金额并冲抵余额 */
    private function applyDebit(int $tenantId, array $rule, int $detailMinor, int $now): int
    {
        $row = Db::table('ch_settlement_balance')
            ->where('tenant_id', $tenantId)
            ->where('receiver_type', (string) $rule['receiver_type'])
            ->where('receiver_id', (int) $rule['receiver_id'])
            ->find();
        if (!is_array($row)) {
            return $detailMinor;
        }
        $balanceMinor = (int) round((float) $row['balance'] * 100);
        if ($balanceMinor <= 0) {
            return $detailMinor;
        }
        $debit = min($detailMinor, $balanceMinor);
        $remain = $balanceMinor - $debit;
        Db::table('ch_settlement_balance')
            ->where('id', (int) $row['id'])
            ->update([
                'balance' => $this->minorToYuan($remain),
                'update_time' => $now,
            ]);

        return $detailMinor - $debit;
    }

    /** 执行单条明细打款（settlement.live=true 走真实通道，否则走桩） */
    private function executeDetail(int $detailId, int $now): void
    {
        $detail = Db::table('ch_settlement_detail')
            ->where('id', $detailId)
            ->lock(true)
            ->find();
        if (!is_array($detail) || !in_array((string) $detail['status'], ['pending', 'failed'], true)) {
            return;
        }

        $channel = (string) $detail['channel'];
        $idempotencyKey = hash('sha256', implode(':', [
            'settlement_payout',
            (int) $detail['tenant_id'],
            (int) $detail['id'],
        ]));

        // 幂等：已打款成功则跳过
        $exists = Db::table('ch_payout_record')
            ->where('idempotency_key', $idempotencyKey)
            ->find();
        if (is_array($exists)) {
            return;
        }

        // 通道分发：settlement.live=true 时走真实通道（商户号开通后启用），否则走桩
        if ($this->liveEnabled()) {
            $result = $this->channel($channel)->pay($detail);
            $channelOrderNo = (string) $result['channel_order_no'];
            $rawResponse = (string) $result['raw'];
        } else {
            $channelOrderNo = 'MOCK_' . strtoupper(substr($idempotencyKey, 0, 16));
            $rawResponse = '{"mock":true}';
        }

        Db::table('ch_payout_record')->insert([
            'settlement_detail_id' => $detailId,
            'tenant_id' => (int) $detail['tenant_id'],
            'channel' => $channel,
            'channel_order_no' => $channelOrderNo,
            'amount' => (string) $detail['amount'],
            'status' => 'success',
            'idempotency_key' => $idempotencyKey,
            'raw_response' => $rawResponse,
            'add_time' => $now,
            'update_time' => $now,
        ]);

        Db::table('ch_settlement_detail')
            ->where('id', $detailId)
            ->update([
                'status' => 'success',
                'channel_ref' => $channelOrderNo,
                'settled_time' => $now,
                'update_time' => $now,
            ]);
    }

    /** 是否启用真实通道（config settlement.live，商户号开通后置 true） */
    private function liveEnabled(): bool
    {
        return (bool) \think\facade\Config::get('settlement.live', false);
    }

    /** 通道工厂：按 ch_settlement_detail.channel 分发 */
    private function channel(string $channel): SettlementChannelInterface
    {
        switch ($channel) {
            case self::CHANNEL_MERCHANT_TRANSFER:
                return new MerchantTransferChannel();
            case self::CHANNEL_WECHAT_SPLIT:
                return new WechatSplitChannel();
            case self::CHANNEL_BANK:
                return new BankTransferChannel();
            default:
                throw new MemberTransactionException(501, 'channel_unknown', '未知结算通道：' . $channel);
        }
    }

    /** 分账单全部明细成功后关闭 */
    private function closeCompleted(int $now): void
    {
        $open = Db::table('ch_settlement')
            ->whereIn('status', ['pending', 'processing'])
            ->column('id');
        foreach ($open as $sid) {
            $pendingCount = (int) Db::table('ch_settlement_detail')
                ->where('settlement_id', (int) $sid)
                ->whereIn('status', ['pending', 'failed'])
                ->count();
            if ($pendingCount === 0) {
                Db::table('ch_settlement')
                    ->where('id', (int) $sid)
                    ->update(['status' => 'done', 'settle_time' => $now, 'update_time' => $now]);
            }
        }
    }

    private function minorToYuan(int $minor): string
    {
        return number_format($minor / 100, 2, '.', '');
    }
}
