<?php

declare(strict_types=1);

namespace app\chamber\services;

use app\chamber\commerce\EventRefundGatewayResult;
use app\chamber\commerce\Money;
use app\chamber\commerce\RefundAttemptState;
use app\chamber\contracts\EventRefundGatewayInterface;
use app\chamber\exceptions\MemberTransactionException;
use app\services\order\StoreOrderRefundServices;
use crmeb\services\AliPayService;
use crmeb\services\pay\Pay;
use EasyWeChat\Payment\API;
use Throwable;
use think\facade\Db;

/** CRMEB v6.0.0 adapter. External channels remain non-final without trusted query evidence. */
final class CrmebEventRefundGateway implements EventRefundGatewayInterface
{
    private const SUPPORTED = ['weixin', 'yue', 'alipay', 'allinpay'];

    public function loadOrder(int $orderPk): array
    {
        if ($orderPk <= 0) {
            throw $this->inconsistent();
        }
        $order = Db::table('eb_store_order')->where('id', $orderPk)->find();
        if (!is_array($order)) {
            throw new MemberTransactionException(404, 'event_order_not_found', 'CRMEB event order was not found');
        }
        return $order;
    }

    public function provider(array $order): string
    {
        $payType = $order['pay_type'] ?? null;
        if (!is_string($payType) || !in_array($payType, self::SUPPORTED, true)) {
            throw new MemberTransactionException(
                409,
                'refund_channel_unsupported',
                'The payment channel does not support a closed refund workflow'
            );
        }
        if ($payType === 'weixin') {
            return (bool) sys_config('pay_wechat_type') ? 'weixin_v3' : 'weixin_v2';
        }
        return $payType === 'yue' ? 'balance' : $payType;
    }

    public function supportsAutomaticAmount(array $order, string $amount, string $remaining): bool
    {
        $provider = $this->provider($order);
        return in_array($provider, ['weixin_v3', 'weixin_v2', 'alipay', 'balance', 'allinpay'], true)
            && Money::toMinor($amount) === Money::toMinor($remaining);
    }

    public function submitApplication(
        array $order,
        string $providerRefundNo,
        string $amount,
        string $reason
    ): EventRefundGatewayResult
    {
        $provider = $this->provider($order);
        $now = time();
        $refundRow = $this->createRefundApplication($order, $providerRefundNo, $amount, $reason, $now);
        try {
            $this->submitProviderRefund($provider, $order, $refundRow, $amount, $reason);
            $this->markRefundAccepted($order, $refundRow, $amount, $now);
        } catch (Throwable $exception) {
            return EventRefundGatewayResult::fromArray([
                'status' => RefundAttemptState::UNKNOWN,
                'provider_status' => 'provider_submit_unknown',
                'provider_refund_no' => $providerRefundNo,
                'provider_refund_id' => '',
                'crmeb_refund_id' => (int) $refundRow['id'],
                'response_hash' => hash('sha256', get_class($exception) . "\n" . $exception->getMessage()),
                'failure_code' => 'provider_submit_unknown',
                'final_source' => '',
            ]);
        }
        return EventRefundGatewayResult::fromArray([
            'status' => RefundAttemptState::PROCESSING,
            'provider_status' => 'provider_accepted',
            'provider_refund_no' => $providerRefundNo,
            'provider_refund_id' => '',
            'crmeb_refund_id' => (int) $refundRow['id'],
            'response_hash' => $this->hashRow($refundRow),
            'failure_code' => '',
            'final_source' => '',
        ]);
    }

    public function query(array $attempt): EventRefundGatewayResult
    {
        $order = $this->loadOrder((int) ($attempt['crmeb_order_id'] ?? 0));
        $this->provider($order);
        $row = $this->refundRow((int) $order['id'], (int) ($attempt['crmeb_refund_id'] ?? 0));
        if ($row === null) {
            return $this->unknown('refund_application_missing', 'refund_application_missing');
        }
        $common = [
            'provider_refund_no' => (string) ($attempt['provider_refund_no'] ?? ''),
            'provider_refund_id' => '',
            'crmeb_refund_id' => (int) $row['id'],
            'response_hash' => $this->hashRow($row),
            'final_source' => '',
        ];
        if ((int) $row['is_cancel'] === 1 || (int) $row['is_del'] === 1 || (int) $row['refund_type'] === 3) {
            return EventRefundGatewayResult::fromArray($common + [
                'status' => RefundAttemptState::FAILED,
                'provider_status' => 'application_rejected',
                'failure_code' => 'refund_application_rejected',
            ]);
        }

        $provider = (string) ($attempt['provider'] ?? $this->provider($order));
        if ((int) $order['refund_status'] === 2 && $provider === 'balance') {
            $bill = Db::table('eb_user_bill')
                ->where('uid', (int) $order['uid'])
                ->where('link_id', (string) $order['id'])
                ->where('category', 'now_money')
                ->where('type', 'pay_product_refund')
                ->where('pm', 1)
                ->where('status', 1)
                ->where('number', (string) $attempt['amount'])
                ->order('id', 'desc')->find();
            if (is_array($bill)) {
                return EventRefundGatewayResult::fromArray([
                    'status' => RefundAttemptState::SUCCEEDED,
                    'provider_status' => 'balance_posted',
                    'provider_refund_no' => (string) $attempt['provider_refund_no'],
                    'provider_refund_id' => 'balance-bill-' . (int) $bill['id'],
                    'crmeb_refund_id' => (int) $row['id'],
                    'response_hash' => hash('sha256', implode("\n", [
                        (string) $bill['id'], (string) $bill['uid'], (string) $bill['link_id'],
                        (string) $bill['number'], (string) $bill['status'],
                    ])),
                    'failure_code' => '',
                    'final_source' => RefundAttemptState::SOURCE_BALANCE,
                ]);
            }
            return EventRefundGatewayResult::fromArray($common + [
                'status' => RefundAttemptState::UNKNOWN,
                'provider_status' => 'balance_evidence_missing',
                'failure_code' => 'balance_evidence_missing',
            ]);
        }

        if ((int) $order['refund_status'] === 2 && in_array($provider, ['weixin_v3', 'weixin_v2', 'alipay'], true)) {
            return $this->queryExternal($provider, $order, $attempt, $row);
        }

        if ((int) $order['refund_status'] === 2) {
            return EventRefundGatewayResult::fromArray($common + [
                'status' => RefundAttemptState::UNKNOWN,
                'provider_status' => 'manual_confirmation_required',
                'failure_code' => 'provider_query_unavailable',
            ]);
        }

        return EventRefundGatewayResult::fromArray($common + [
            'status' => RefundAttemptState::PROCESSING,
            'provider_status' => 'application_pending',
            'failure_code' => '',
        ]);
    }

    private function refundRow(int $orderPk, int $refundPk): ?array
    {
        $query = Db::table('eb_store_order_refund');
        if ($refundPk > 0) {
            $query->where('id', $refundPk);
        } else {
            $query->where('store_order_id', $orderPk)->order('id', 'desc');
        }
        $row = $query->find();
        return is_array($row) ? $row : null;
    }

    private function createRefundApplication(
        array $order,
        string $providerRefundNo,
        string $amount,
        string $reason,
        int $now
    ): array {
        $cartRows = Db::table('eb_store_order_cart_info')
            ->where('oid', (int) $order['id'])
            ->select()
            ->toArray();
        $cartInfo = [];
        $refundNum = 0;
        foreach ($cartRows as $cartRow) {
            $refundNum += (int) ($cartRow['cart_num'] ?? 0);
            $decoded = $cartRow['cart_info'] ?? [];
            if (is_string($decoded)) {
                $decoded = json_decode($decoded, true);
            }
            $cartInfo[] = is_array($decoded) ? $decoded : $cartRow;
        }
        $refundId = Db::table('eb_store_order_refund')->insertGetId([
            'store_order_id' => (int) $order['id'],
            'store_id' => (int) ($order['store_id'] ?? 0),
            'order_id' => $providerRefundNo,
            'uid' => (int) $order['uid'],
            'refund_type' => 1,
            'refund_num' => $refundNum,
            'refund_price' => $amount,
            'refunded_price' => '0.00',
            'refund_phone' => (string) ($order['user_phone'] ?? ''),
            'refund_express' => '',
            'refund_express_name' => '',
            'refund_explain' => $reason,
            'refund_img' => '[]',
            'refund_reason' => '',
            'refuse_reason' => '',
            'remark' => '',
            'refunded_time' => 0,
            'cart_info' => json_encode($cartInfo, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]',
            'is_cancel' => 0,
            'is_pink_cancel' => 0,
            'is_del' => 0,
            'add_time' => $now,
            'is_system_del' => 0,
        ]);
        if ($refundId <= 0) {
            throw $this->inconsistent();
        }

        return $this->refundRow((int) $order['id'], (int) $refundId) ?? $this->inconsistentRow($refundId);
    }

    private function submitProviderRefund(string $provider, array $order, array $refundRow, string $amount, string $reason): void
    {
        $refundData = [
            'refund_price' => $amount,
            'pay_price' => (string) $order['pay_price'],
            'refund_id' => (string) $refundRow['order_id'],
            'order_id' => (string) $refundRow['order_id'],
            'refund_reason' => $reason,
            'refund_explain' => '',
            'refund_img' => '[]',
        ];

        if ($provider === 'balance') {
            /** @var StoreOrderRefundServices $refunds */
            $refunds = app()->make(StoreOrderRefundServices::class);
            if (!$refunds->yueRefund($order, $refundData)) {
                throw new MemberTransactionException(409, 'provider_submit_unknown', 'Balance refund failed');
            }
            Db::table('eb_store_order_status')->insert([
                'oid' => (int) $order['id'],
                'change_type' => 'refund_price',
                'change_message' => '退款给用户：' . $amount . '元',
                'change_time' => time(),
            ]);

            return;
        }

        if ($provider === 'weixin_v3' || $provider === 'weixin_v2') {
            $driver = $provider === 'weixin_v3' ? 'v3_wechat_pay' : 'wechat_pay';
            /** @var Pay $pay */
            $pay = app()->make(Pay::class, [$driver]);
            $tradeNo = (string) $order['order_id'];
            if (!empty($order['trade_no'])) {
                $tradeNo = (string) $order['trade_no'];
                $refundData['type'] = 'trade_no';
            }
            if ((int) ($order['is_channel'] ?? 0) === 1) {
                $refundData['trade_no'] = (string) ($order['trade_no'] ?? '');
                $refundData['pay_new_weixin_open'] = sys_config('pay_new_weixin_open');
            } else {
                $refundData['wechat'] = true;
            }
            $pay->refund($tradeNo, $refundData);
            Db::table('eb_store_order_status')->insert([
                'oid' => (int) $order['id'],
                'change_type' => 'refund_price',
                'change_message' => '退款给用户：' . $amount . '元',
                'change_time' => time(),
            ]);

            return;
        }

        if ($provider === 'alipay') {
            $tradeNo = strpos((string) ($order['trade_no'] ?? ''), '_') !== false
                ? (string) $order['trade_no']
                : (string) $order['order_id'];
            mt_srand();
            AliPayService::instance()->refund($tradeNo, (float) $amount, (string) $refundRow['order_id']);
            Db::table('eb_store_order_status')->insert([
                'oid' => (int) $order['id'],
                'change_type' => 'refund_price',
                'change_message' => '退款给用户：' . $amount . '元',
                'change_time' => time(),
            ]);

            return;
        }

        if ($provider === 'allinpay') {
            /** @var Pay $pay */
            $pay = app()->make(Pay::class, ['allin_pay']);
            $pay->refund((string) ($order['trade_no'] ?? ''), [
                'order_id' => (string) $refundRow['order_id'],
                'refund_price' => $amount,
            ]);
            Db::table('eb_store_order_status')->insert([
                'oid' => (int) $order['id'],
                'change_type' => 'refund_price',
                'change_message' => '退款给用户：' . $amount . '元',
                'change_time' => time(),
            ]);

            return;
        }

        throw new MemberTransactionException(409, 'refund_channel_unsupported', 'The payment channel does not support a closed refund workflow');
    }

    private function markRefundAccepted(array $order, array $refundRow, string $amount, int $now): void
    {
        Db::table('eb_store_order')->where('id', (int) $order['id'])->update([
            'status' => -2,
            'refund_status' => 2,
            'refund_type' => 6,
            'refund_express' => (string) ($refundRow['refund_express'] ?? ''),
            'refund_express_name' => (string) ($refundRow['refund_express_name'] ?? ''),
            'refund_reason_wap_img' => (string) ($refundRow['refund_img'] ?? '[]'),
            'refund_reason_wap_explain' => (string) ($refundRow['refund_explain'] ?? ''),
            'refund_reason_time' => $now,
            'refund_reason_wap' => (string) ($refundRow['refund_reason'] ?? ''),
            'refund_price' => $amount,
        ]);
        Db::table('eb_store_order_refund')->where('id', (int) $refundRow['id'])->update([
            'refund_type' => 6,
            'refunded_price' => $amount,
            'refunded_time' => $now,
        ]);
        Db::table('eb_store_order_cart_info')
            ->where('oid', (int) $order['id'])
            ->update(['refund_num' => Db::raw('cart_num')]);
    }

    private function inconsistentRow(int $refundId): array
    {
        throw new MemberTransactionException(
            503,
            'event_order_inconsistent',
            'Event refund row ' . $refundId . ' is inconsistent'
        );
    }

    private function queryExternal(string $provider, array $order, array $attempt, array $row): EventRefundGatewayResult
    {
        try {
            if ($provider === 'weixin_v3') {
                /** @var Pay $pay */
                $pay = app()->make(Pay::class, ['v3_wechat_pay']);
                $response = $pay->queryRefund((string) $attempt['provider_refund_no'], '');
                $data = is_object($response) && method_exists($response, 'toArray')
                    ? $response->toArray()
                    : (is_array($response) ? $response : []);
                $rawStatus = strtoupper((string) ($data['status'] ?? ''));
                $responseHash = hash('sha256', json_encode($data, JSON_UNESCAPED_SLASHES) ?: 'invalid');
                if ((string) ($data['out_refund_no'] ?? '') !== (string) $attempt['provider_refund_no']) {
                    return $this->queryMismatch($attempt, $row, $responseHash);
                }
                if ($rawStatus === 'SUCCESS') {
                    $refundMinor = (int) ($data['amount']['refund'] ?? -1);
                    if ($refundMinor !== Money::toMinor((string) $attempt['amount'])) {
                        return $this->queryMismatch($attempt, $row, $responseHash);
                    }
                    return $this->providerSuccess(
                        $attempt,
                        $row,
                        (string) ($data['refund_id'] ?? ''),
                        $responseHash
                    );
                }
                if ($rawStatus === 'CLOSED') {
                    return $this->providerFailure($attempt, $row, 'provider_refund_closed', $responseHash);
                }
                return $this->providerPending($attempt, $row, $rawStatus === 'PROCESSING' ? 'processing' : 'unknown', $responseHash);
            }

            if ($provider === 'weixin_v2') {
                /** @var Pay $pay */
                $pay = app()->make(Pay::class, ['wechat_pay']);
                $response = $pay->queryRefund(
                    (string) $attempt['provider_refund_no'],
                    '',
                    ['type' => API::OUT_REFUND_NO]
                );
                $data = is_object($response) && method_exists($response, 'toArray')
                    ? $response->toArray()
                    : (is_array($response) ? $response : []);
                $responseHash = hash('sha256', json_encode($data, JSON_UNESCAPED_SLASHES) ?: 'invalid');
                if ((string) ($data['out_refund_no_0'] ?? $data['out_refund_no'] ?? '') !== (string) $attempt['provider_refund_no']) {
                    return $this->queryMismatch($attempt, $row, $responseHash);
                }
                $rawStatus = strtoupper((string) ($data['refund_status_0'] ?? $data['refund_status'] ?? ''));
                if ($rawStatus === 'SUCCESS') {
                    $refundMinor = (int) ($data['refund_fee_0'] ?? $data['refund_fee'] ?? -1);
                    if ($refundMinor !== Money::toMinor((string) $attempt['amount'])) {
                        return $this->queryMismatch($attempt, $row, $responseHash);
                    }
                    return $this->providerSuccess(
                        $attempt,
                        $row,
                        (string) ($data['refund_id_0'] ?? $data['refund_id'] ?? ''),
                        $responseHash
                    );
                }
                if (in_array($rawStatus, ['REFUNDCLOSE', 'CLOSED', 'CHANGE'], true)) {
                    return $this->providerFailure($attempt, $row, 'provider_refund_closed', $responseHash);
                }
                return $this->providerPending($attempt, $row, $rawStatus === 'PROCESSING' ? 'processing' : 'unknown', $responseHash);
            }

            $tradeNo = (string) $order['order_id'];
            if (is_string($order['trade_no'] ?? null) && strpos((string) $order['trade_no'], '_') !== false) {
                $tradeNo = (string) $order['trade_no'];
            }
            $response = AliPayService::instance()->queryRefund(
                $tradeNo,
                (string) $attempt['provider_refund_no']
            );
            $data = is_object($response) && method_exists($response, 'toMap') ? $response->toMap() : [];
            $responseHash = hash('sha256', json_encode($data, JSON_UNESCAPED_SLASHES) ?: 'invalid');
            if ((string) ($data['out_request_no'] ?? '') !== (string) $attempt['provider_refund_no']) {
                return $this->queryMismatch($attempt, $row, $responseHash);
            }
            if (strtoupper((string) ($data['refund_status'] ?? '')) === 'REFUND_SUCCESS') {
                $amount = isset($data['refund_amount']) ? number_format((float) $data['refund_amount'], 2, '.', '') : '';
                if ($amount !== (string) $attempt['amount']) {
                    return $this->queryMismatch($attempt, $row, $responseHash);
                }
                return $this->providerSuccess(
                    $attempt,
                    $row,
                    (string) ($data['refund_settlement_id'] ?? ''),
                    $responseHash
                );
            }
            return $this->providerPending($attempt, $row, 'processing', $responseHash);
        } catch (Throwable $exception) {
            return $this->providerPending(
                $attempt,
                $row,
                'query_unavailable',
                hash('sha256', get_class($exception))
            );
        }
    }

    private function providerSuccess(array $attempt, array $row, string $providerId, string $hash): EventRefundGatewayResult
    {
        return EventRefundGatewayResult::fromArray([
            'status' => RefundAttemptState::SUCCEEDED,
            'provider_status' => 'success',
            'provider_refund_no' => (string) $attempt['provider_refund_no'],
            'provider_refund_id' => $providerId,
            'crmeb_refund_id' => (int) $row['id'],
            'response_hash' => $hash,
            'failure_code' => '',
            'final_source' => RefundAttemptState::SOURCE_PROVIDER_QUERY,
        ]);
    }

    private function providerFailure(array $attempt, array $row, string $code, string $hash): EventRefundGatewayResult
    {
        return EventRefundGatewayResult::fromArray([
            'status' => RefundAttemptState::FAILED,
            'provider_status' => 'closed',
            'provider_refund_no' => (string) $attempt['provider_refund_no'],
            'provider_refund_id' => '',
            'crmeb_refund_id' => (int) $row['id'],
            'response_hash' => $hash,
            'failure_code' => $code,
            'final_source' => '',
        ]);
    }

    private function providerPending(array $attempt, array $row, string $status, string $hash): EventRefundGatewayResult
    {
        return EventRefundGatewayResult::fromArray([
            'status' => $status === 'processing' ? RefundAttemptState::PROCESSING : RefundAttemptState::UNKNOWN,
            'provider_status' => $status,
            'provider_refund_no' => (string) $attempt['provider_refund_no'],
            'provider_refund_id' => '',
            'crmeb_refund_id' => (int) $row['id'],
            'response_hash' => $hash,
            'failure_code' => $status === 'query_unavailable' ? 'provider_query_unavailable' : '',
            'final_source' => '',
        ]);
    }

    private function queryMismatch(array $attempt, array $row, string $hash): EventRefundGatewayResult
    {
        return EventRefundGatewayResult::fromArray([
            'status' => RefundAttemptState::UNKNOWN,
            'provider_status' => 'query_evidence_mismatch',
            'provider_refund_no' => (string) $attempt['provider_refund_no'],
            'provider_refund_id' => '',
            'crmeb_refund_id' => (int) $row['id'],
            'response_hash' => $hash,
            'failure_code' => 'provider_evidence_mismatch',
            'final_source' => '',
        ]);
    }

    private function unknown(string $providerStatus, string $failureCode): EventRefundGatewayResult
    {
        return EventRefundGatewayResult::fromArray([
            'status' => RefundAttemptState::UNKNOWN,
            'provider_status' => $providerStatus,
            'provider_refund_no' => '',
            'provider_refund_id' => '',
            'crmeb_refund_id' => 0,
            'response_hash' => hash('sha256', $providerStatus . "\n" . $failureCode),
            'failure_code' => $failureCode,
            'final_source' => '',
        ]);
    }

    private function hashRow(array $row): string
    {
        return hash('sha256', implode("\n", [
            (string) $row['id'], (string) $row['store_order_id'], (string) $row['order_id'],
            (string) $row['uid'], (string) $row['refund_type'], (string) $row['refund_price'],
            (string) $row['refunded_price'], (string) $row['is_cancel'], (string) $row['is_del'],
        ]));
    }

    private function inconsistent(): MemberTransactionException
    {
        return new MemberTransactionException(503, 'event_order_inconsistent', 'Event payment data is inconsistent');
    }
}
