<?php

declare(strict_types=1);

namespace app\chamber\services;

use app\chamber\commerce\CommerceEvent;
use app\chamber\commerce\CommerceEventType;
use app\chamber\commerce\Money;
use app\chamber\contracts\CommerceEventStoreInterface;
use app\chamber\exceptions\MemberTransactionException;
use app\chamber\membership\OrderContextState;
use think\facade\Db;
use Throwable;

/**
 * Trusted CRMEB payment adapter for Chamber-owned membership orders. Native
 * CRMEB post-payment listeners are intentionally bypassed for these orders;
 * ordinary product orders still use the upstream service unchanged.
 */
final class MembershipPaymentCompletionService
{
    /** @var CommerceEventStoreInterface */
    private $events;

    /** @var MembershipEntitlementService */
    private $entitlements;

    public function __construct(
        CommerceEventStoreInterface $events,
        MembershipEntitlementService $entitlements
    ) {
        $this->events = $events;
        $this->entitlements = $entitlements;
    }

    public function complete(array $input, string $payType, array $other = [], bool $zeroAmount = false): bool
    {
        return (bool) Db::transaction(function () use ($input, $payType, $other, $zeroAmount): bool {
            $orderId = (int) ($input['id'] ?? 0);
            if ($orderId <= 0) {
                throw $this->inconsistent();
            }
            $order = Db::table('eb_store_order')->where('id', $orderId)->lock(true)->find();
            if (!is_array($order)) {
                throw $this->inconsistent();
            }
            $context = Db::table('ch_order_context')
                ->where('business_type', 'membership')
                ->where('order_pk', $orderId)
                ->lock(true)
                ->find();
            if (!is_array($context)) {
                throw $this->inconsistent();
            }
            $this->assertOrderContext($order, $context);

            $payStatus = (int) $context['pay_status'];
            OrderContextState::assertPayStatus($payStatus);
            if ($payStatus === OrderContextState::PAY_COMPLETED && (int) $order['paid'] !== 1) {
                throw $this->inconsistent();
            }
            if ($payStatus !== OrderContextState::PAY_PENDING
                && $payStatus !== OrderContextState::PAY_COMPLETED) {
                throw $this->inconsistent();
            }

            $payable = $this->money($context['payable_amount']);
            $completionKind = Money::toMinor($payable) === 0 || $zeroAmount
                ? OrderContextState::COMPLETION_ZERO_AMOUNT
                : OrderContextState::COMPLETION_PAID;
            if ($completionKind === OrderContextState::COMPLETION_ZERO_AMOUNT && Money::toMinor($payable) !== 0) {
                throw $this->inconsistent();
            }
            if (!in_array($payType, ['weixin', 'alipay', 'allinpay', 'yue', 'offline'], true)) {
                throw $this->inconsistent();
            }

            $paidAt = (int) ($order['pay_time'] ?? 0);
            if ($paidAt <= 0) {
                $paidAt = time();
            }
            $tradeNo = isset($other['trade_no']) && is_string($other['trade_no'])
                ? trim($other['trade_no'])
                : '';
            if ($tradeNo !== '' && (strlen($tradeNo) > 96 || preg_match('/^[A-Za-z0-9._:@-]+$/', $tradeNo) !== 1)) {
                throw $this->inconsistent();
            }

            if ($payStatus !== OrderContextState::PAY_COMPLETED) {
                $updates = [
                    'paid' => 1,
                    'pay_type' => $payType,
                    'pay_time' => $paidAt,
                ];
                if ($tradeNo !== '') {
                    $updates['trade_no'] = $tradeNo;
                }
                $changed = Db::table('eb_store_order')
                    ->where('id', $orderId)
                    ->where('paid', '<>', 1)
                    ->update($updates);
                if ($changed !== 1 && (int) $order['paid'] !== 1) {
                    throw $this->inconsistent();
                }
                Db::table('ch_order_context')->where('id', (int) $context['id'])->update([
                    'paid_amount' => $payable,
                    'pay_status' => OrderContextState::PAY_COMPLETED,
                    'completion_kind' => $completionKind,
                    'paid_time' => $paidAt,
                    'version' => (int) $context['version'] + 1,
                    'update_time' => time(),
                ]);
                $this->writeNativeStatus($orderId, $paidAt);
            }

            $freshContext = Db::table('ch_order_context')->where('id', (int) $context['id'])->find();
            if (!is_array($freshContext)) {
                throw $this->inconsistent();
            }
            $event = CommerceEvent::fromArray([
                'source' => 'crmeb',
                'source_event_id' => 'order:' . $orderId . ':paid',
                'event_type' => CommerceEventType::ORDER_COMPLETED,
                'schema_version' => CommerceEvent::SCHEMA_VERSION,
                'occurred_at' => (int) $freshContext['paid_time'],
                'tenant_id' => (int) $freshContext['tenant_id'],
                'channel_id' => (int) $freshContext['channel_id'],
                'order_pk' => $orderId,
                'order_no' => (string) $freshContext['order_no'],
                'uid' => (int) $freshContext['uid'],
                'business_type' => 'membership',
                'context_id' => (int) $freshContext['id'],
                'currency' => (string) $freshContext['currency'],
                'paid_amount' => $this->money($freshContext['paid_amount']),
                'correlation_id' => 'chamber:payment:' . $orderId,
                'completion_kind' => (string) $freshContext['completion_kind'],
                'pay_type' => $payType,
                'trade_no' => $tradeNo !== '' ? $tradeNo : (string) ($order['trade_no'] ?? ''),
                'paid_at' => (int) $freshContext['paid_time'],
            ]);
            $this->events->record($event);
            $this->entitlements->consumeEvent($event);

            return true;
        });
    }

    private function assertOrderContext(array $order, array $context): void
    {
        if ((int) $order['uid'] !== (int) $context['uid']
            || (string) $order['order_id'] !== (string) $context['order_no']
            || (string) $order['unique'] !== (string) $context['context_no']
            || (int) $order['virtual_type'] !== 3
            || (int) $order['total_num'] !== 1
            || (int) $order['is_cancel'] !== 0
            || (int) $order['is_del'] !== 0) {
            throw $this->inconsistent();
        }
        if ($this->money($order['pay_price']) !== $this->money($context['payable_amount'])) {
            throw $this->inconsistent();
        }
        if ((string) $context['currency'] !== 'CNY') {
            throw $this->inconsistent();
        }
        OrderContextState::assertSnapshot(
            (int) $context['pay_status'],
            (string) $context['completion_kind'],
            (int) $context['refund_status'],
            (string) $context['paid_amount'],
            (string) $context['refunded_amount']
        );
    }

    private function writeNativeStatus(int $orderId, int $time): void
    {
        try {
            Db::table('eb_store_order_status')->insert([
                'oid' => $orderId,
                'change_type' => 'pay_success',
                'change_message' => '用户付款成功',
                'change_time' => $time,
            ]);
        } catch (Throwable $exception) {
            // Status history is useful but must not make the trusted payment
            // fact disappear on a CRMEB patch with a different side table.
        }
    }

    private function money($value): string
    {
        if (is_int($value)) {
            $value .= '.00';
        } elseif (is_float($value)) {
            $value = number_format($value, 2, '.', '');
        } elseif (is_string($value) && preg_match('/^(0|[1-9][0-9]{0,13})(?:\.([0-9]{1,2}))?$/D', $value, $m)) {
            $value = $m[1] . '.' . str_pad($m[2] ?? '', 2, '0');
        }
        try {
            return Money::assertAmount($value, 'money');
        } catch (Throwable $exception) {
            throw $this->inconsistent();
        }
    }

    private function inconsistent(): MemberTransactionException
    {
        return new MemberTransactionException(
            503,
            'membership_order_inconsistent',
            'Membership payment data is inconsistent'
        );
    }
}
