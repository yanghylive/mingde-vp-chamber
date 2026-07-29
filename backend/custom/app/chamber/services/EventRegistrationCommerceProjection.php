<?php

declare(strict_types=1);

namespace app\chamber\services;

use app\chamber\commerce\CommerceEvent;
use app\chamber\commerce\CommerceEventType;
use app\chamber\commerce\Money;
use app\chamber\exceptions\MemberTransactionException;
use app\chamber\membership\OrderContextState;
use think\facade\Db;

/** Projects trusted payment and refund facts into event registrations. */
final class EventRegistrationCommerceProjection
{
    public function consumePending(int $limit = 50): array
    {
        $rows = Db::table('ch_commerce_event_inbox')
            ->where('business_type', 'event_registration')
            ->whereIn('status', ['received', 'failed'])
            ->where('next_retry_time', '<=', time())
            ->order('id', 'asc')->limit($limit)->select()->toArray();
        $summary = ['scanned' => count($rows), 'processed' => 0, 'failed' => 0];
        foreach ($rows as $row) {
            try {
                Db::transaction(function () use ($row): void {
                    $payload = json_decode((string) $row['payload_json'], true);
                    if (!is_array($payload)) {
                        throw $this->inconsistent();
                    }
                    $this->consumeEvent(CommerceEvent::fromArray($payload));
                });
                $summary['processed']++;
            } catch (\Throwable $exception) {
                $summary['failed']++;
                Db::table('ch_commerce_event_inbox')->where('id', (int) $row['id'])->update([
                    'status' => 'failed',
                    'attempt_count' => (int) $row['attempt_count'] + 1,
                    'last_error_code' => 'event_registration_consume_failed',
                    'next_retry_time' => time() + 60,
                    'update_time' => time(),
                ]);
            }
        }

        return $summary;
    }

    public function consumeEvent(CommerceEvent $event): void
    {
        $payload = $event->payload();
        if (($payload['business_type'] ?? '') !== 'event_registration') {
            throw $this->inconsistent();
        }
        $inbox = Db::table('ch_commerce_event_inbox')
            ->where('event_id', $event->eventId())
            ->where('business_type', 'event_registration')
            ->lock(true)
            ->find();
        if (!is_array($inbox) || !hash_equals((string) $inbox['payload_hash'], $event->payloadHash())) {
            throw $this->inconsistent();
        }
        if ((string) $inbox['status'] === 'processed') {
            return;
        }

        if ($event->eventType() === CommerceEventType::ORDER_COMPLETED) {
            $this->applyPayment($event);
        } elseif (CommerceEventType::isRefund($event->eventType())) {
            $this->applyRefund($event);
        } else {
            throw $this->inconsistent();
        }

        $now = time();
        Db::table('ch_commerce_event_inbox')->where('id', (int) $inbox['id'])
            ->where('status', '<>', 'processed')->update([
                'status' => 'processed',
                'attempt_count' => (int) $inbox['attempt_count'] + 1,
                'lease_token' => '',
                'lease_expire_time' => 0,
                'processed_time' => $now,
                'update_time' => $now,
            ]);
    }

    private function applyPayment(CommerceEvent $event): void
    {
        [$context, $registration] = $this->lockRegistration($event);
        $payload = $event->payload();
        if ((int) $context['pay_status'] !== OrderContextState::PAY_COMPLETED
            || Money::toMinor(Money::assertAmount((string) $context['paid_amount'], 'paid_amount'))
                !== Money::toMinor($event->paidAmount())) {
            throw $this->inconsistent();
        }

        if ((int) $registration['status'] === 0) {
            $ticket = Db::table('ch_event_ticket')
                ->where('tenant_id', $event->tenantId())
                ->where('id', (int) $registration['ticket_id'])
                ->lock(true)
                ->find();
            if (!is_array($ticket) || (int) $ticket['reserved_count'] < 1) {
                throw $this->inconsistent();
            }
            $changed = Db::table('ch_event_ticket')->where('id', (int) $ticket['id'])
                ->where('reserved_count', (int) $ticket['reserved_count'])->update([
                    'reserved_count' => (int) $ticket['reserved_count'] - 1,
                    'paid_count' => (int) $ticket['paid_count'] + 1,
                    'update_time' => time(),
                ]);
            if ($changed !== 1) {
                throw $this->inconsistent();
            }
            $this->capturePointHold($event->tenantId(), $registration);
            Db::table('ch_event_registration')->where('id', (int) $registration['id'])->update([
                'status' => 1,
                'reserve_expire_time' => 0,
                'paid_time' => (int) $payload['paid_at'],
                'update_time' => time(),
            ]);
        } elseif ((int) $registration['status'] !== 1) {
            throw $this->inconsistent();
        }
    }

    private function applyRefund(CommerceEvent $event): void
    {
        [$context, $registration] = $this->lockRegistration($event);
        if ((int) $context['pay_status'] !== OrderContextState::PAY_COMPLETED
            || (string) $context['completion_kind'] !== OrderContextState::COMPLETION_PAID
            || Money::toMinor(Money::assertAmount((string) $context['paid_amount'], 'paid_amount'))
                !== Money::toMinor($event->paidAmount())) {
            throw $this->inconsistent();
        }

        if ($event->eventType() !== CommerceEventType::REFUND_COMPLETED) {
            $this->advanceRefundLifecycle($event, $context);
            return;
        }

        $previous = Money::assertAmount((string) $context['refunded_amount'], 'refunded_amount');
        $cumulative = Money::assertAmount($event->cumulativeRefundedAmount(), 'cumulative_refunded_amount');
        $delta = Money::assertAmount($event->refundDelta(), 'refund_delta');
        $paid = Money::assertAmount((string) $context['paid_amount'], 'paid_amount');
        $effectKey = hash('sha256', 'event_registration_refund:' . $event->completionFingerprint());
        $effect = Db::table('ch_event_registration_effect')
            ->where('tenant_id', $event->tenantId())->where('effect_key', $effectKey)->lock(true)->find();
        if (is_array($effect)) {
            $replayedFull = Money::toMinor($cumulative) === Money::toMinor($paid);
            if (!hash_equals((string) $effect['effect_hash'], $event->completionFingerprint())
                || (int) $effect['registration_id'] !== (int) $registration['id']
                || (int) $effect['order_context_id'] !== (int) $context['id']
                || Money::toMinor((string) $effect['refund_delta']) !== Money::toMinor($delta)
                || Money::toMinor((string) $effect['cumulative_refunded_amount']) !== Money::toMinor($cumulative)
                || (string) $effect['effect_type'] !== ($replayedFull ? 'full_refund' : 'partial_refund')
                || Money::toMinor((string) $context['refunded_amount']) < Money::toMinor($cumulative)
                || ($replayedFull && ((int) $context['refund_status'] !== OrderContextState::REFUND_COMPLETED
                    || (int) $registration['status'] !== 3))) {
                throw $this->inconsistent();
            }
            return;
        }

        $previousMinor = Money::toMinor($previous);
        $cumulativeMinor = Money::toMinor($cumulative);
        $paidMinor = Money::toMinor($paid);
        if ($cumulativeMinor > $paidMinor || $cumulativeMinor <= $previousMinor
            || $cumulativeMinor - $previousMinor !== Money::toMinor($delta)) {
            throw $this->inconsistent();
        }

        $full = $cumulativeMinor === $paidMinor;
        $pointsDelta = 0;
        $seatDelta = 0;
        if ($full) {
            [$pointsDelta, $seatDelta] = $this->reverseRegistration($event, $registration);
        }
        Db::table('ch_event_registration_effect')->insert([
            'tenant_id' => $event->tenantId(),
            'registration_id' => (int) $registration['id'],
            'order_context_id' => (int) $context['id'],
            'effect_key' => $effectKey,
            'effect_hash' => $event->completionFingerprint(),
            'event_id' => $event->eventId(),
            'completion_id' => $event->completionId(),
            'effect_type' => $full ? 'full_refund' : 'partial_refund',
            'refund_delta' => $delta,
            'cumulative_refunded_amount' => $cumulative,
            'points_delta' => $pointsDelta,
            'seat_delta' => $seatDelta,
            'add_time' => time(),
        ]);
        Db::table('ch_order_context')->where('id', (int) $context['id'])->update([
            'refunded_amount' => $cumulative,
            'refund_status' => $full
                ? OrderContextState::REFUND_COMPLETED
                : OrderContextState::REFUND_PARTIALLY_COMPLETED,
            'version' => (int) $context['version'] + 1,
            'update_time' => time(),
        ]);
    }

    private function advanceRefundLifecycle(CommerceEvent $event, array $context): void
    {
        $status = [
            CommerceEventType::REFUND_REQUESTED => OrderContextState::REFUND_REQUESTED,
            CommerceEventType::REFUND_PROCESSING => OrderContextState::REFUND_PROCESSING,
            CommerceEventType::REFUND_CANCELLED => OrderContextState::REFUND_CANCELLED,
            CommerceEventType::REFUND_FAILED => OrderContextState::REFUND_FAILED,
        ][$event->eventType()] ?? null;
        if ($status === null) {
            throw $this->inconsistent();
        }
        $current = (int) $context['refund_status'];
        OrderContextState::assertRefundStatus($current);
        if ($current === $status) {
            return;
        }
        if (!OrderContextState::canRefundTransition($current, $status)) {
            throw $this->inconsistent();
        }
        Db::table('ch_order_context')->where('id', (int) $context['id'])->update([
            'refund_status' => $status,
            'version' => (int) $context['version'] + 1,
            'update_time' => time(),
        ]);
    }

    private function reverseRegistration(CommerceEvent $event, array $registration): array
    {
        if (!in_array((int) $registration['status'], [1, 5], true)) {
            throw $this->inconsistent();
        }
        $ticket = Db::table('ch_event_ticket')->where('tenant_id', $event->tenantId())
            ->where('id', (int) $registration['ticket_id'])->lock(true)->find();
        if (!is_array($ticket) || (int) $ticket['paid_count'] < 1) {
            throw $this->inconsistent();
        }
        $changed = Db::table('ch_event_ticket')->where('id', (int) $ticket['id'])
            ->where('paid_count', (int) $ticket['paid_count'])->update([
                'paid_count' => (int) $ticket['paid_count'] - 1,
                'update_time' => time(),
            ]);
        if ($changed !== 1) {
            throw $this->inconsistent();
        }

        $points = $this->restoreRegistrationPoints($event, $registration);
        Db::table('ch_event_registration')->where('id', (int) $registration['id'])->update([
            'status' => 3,
            'refund_time' => (int) $event->payload()['occurred_at'],
            'update_time' => time(),
        ]);
        return [$points, -1];
    }

    private function restoreRegistrationPoints(CommerceEvent $event, array $registration): int
    {
        $points = (int) $registration['integral_amount'];
        if ($points === 0) {
            return 0;
        }
        $hold = Db::table('ch_point_hold')->where('tenant_id', $event->tenantId())
            ->where('registration_id', (int) $registration['id'])->lock(true)->find();
        $original = Db::table('ch_point_ledger')->where('tenant_id', $event->tenantId())
            ->where('idempotency_key', hash(
                'sha256',
                'event_registration_points:' . $event->tenantId() . ':' . (int) $registration['id']
            ))
            ->lock(true)->find();
        if (!is_array($hold) || (int) $hold['status'] !== 2 || (int) $hold['amount'] !== $points
            || !is_array($original) || (int) $original['delta'] !== -$points || (int) $original['status'] !== 1) {
            throw $this->inconsistent();
        }
        $account = Db::table('ch_point_account')->where('tenant_id', $event->tenantId())
            ->where('id', (int) $hold['account_id'])->lock(true)->find();
        if (!is_array($account) || (int) $account['uid'] !== (int) $registration['uid']) {
            throw $this->inconsistent();
        }
        $balance = (int) $account['balance'] + $points;
        Db::table('ch_point_account')->where('id', (int) $account['id'])->update([
            'balance' => $balance,
            'version' => (int) $account['version'] + 1,
            'update_time' => time(),
        ]);
        $reversalId = (int) Db::table('ch_point_ledger')->insertGetId([
            'tenant_id' => $event->tenantId(),
            'account_id' => (int) $account['id'],
            'member_id' => (int) $registration['member_id'],
            'uid' => (int) $registration['uid'],
            'delta' => $points,
            'balance_after' => $balance,
            'source_type' => 'event_registration_refund',
            'source_id' => (string) $registration['id'],
            'idempotency_key' => hash('sha256', 'event_registration_refund_points:' . $event->completionFingerprint()),
            'status' => 1,
            'reversal_id' => (int) $original['id'],
            'add_time' => time(),
        ]);
        if ($reversalId <= 0) {
            throw $this->inconsistent();
        }
        Db::table('ch_point_ledger')->where('id', (int) $original['id'])->update([
            'status' => 2,
            'reversal_id' => $reversalId,
        ]);
        return $points;
    }

    private function lockRegistration(CommerceEvent $event): array
    {
        $payload = $event->payload();
        $context = Db::table('ch_order_context')->where('tenant_id', $event->tenantId())
            ->where('id', (int) $payload['context_id'])->where('business_type', 'event_registration')
            ->lock(true)->find();
        if (!is_array($context) || (int) $context['channel_id'] !== $event->channelId()
            || (int) $context['order_pk'] !== $event->orderPk()
            || (string) $context['order_no'] !== (string) $payload['order_no']
            || (int) $context['uid'] !== (int) $payload['uid']
            || (string) $context['currency'] !== (string) $payload['currency']) {
            throw $this->inconsistent();
        }
        $registration = Db::table('ch_event_registration')->where('tenant_id', $event->tenantId())
            ->where('id', (int) $context['business_id'])->lock(true)->find();
        if (!is_array($registration) || (int) $registration['order_context_id'] !== (int) $context['id']
            || (int) $registration['uid'] !== (int) $payload['uid']) {
            throw $this->inconsistent();
        }
        return [$context, $registration];
    }

    private function capturePointHold(int $tenantId, array $registration): void
    {
        $points = (int) $registration['integral_amount'];
        if ($points === 0) {
            return;
        }
        $hold = Db::table('ch_point_hold')->where('tenant_id', $tenantId)
            ->where('registration_id', (int) $registration['id'])->lock(true)->find();
        if (!is_array($hold)) {
            throw $this->inconsistent();
        }
        if ((int) $hold['status'] === 2) {
            return;
        }
        if ((int) $hold['status'] !== 1 || (int) $hold['amount'] !== $points) {
            throw $this->inconsistent();
        }
        $account = Db::table('ch_point_account')->where('tenant_id', $tenantId)
            ->where('id', (int) $hold['account_id'])->lock(true)->find();
        if (!is_array($account) || (int) $account['frozen_balance'] < $points) {
            throw $this->inconsistent();
        }
        Db::table('ch_point_account')->where('id', (int) $account['id'])->update([
            'frozen_balance' => (int) $account['frozen_balance'] - $points,
            'version' => (int) $account['version'] + 1,
            'update_time' => time(),
        ]);
        Db::table('ch_point_ledger')->insert([
            'tenant_id' => $tenantId,
            'account_id' => (int) $account['id'],
            'member_id' => (int) $registration['member_id'],
            'uid' => (int) $registration['uid'],
            'delta' => -$points,
            'balance_after' => (int) $account['balance'],
            'source_type' => 'event_registration',
            'source_id' => (string) $registration['id'],
            'idempotency_key' => hash('sha256', 'event_registration_points:' . $tenantId . ':' . $registration['id']),
            'status' => 1,
            'reversal_id' => 0,
            'add_time' => time(),
        ]);
        Db::table('ch_point_hold')->where('id', (int) $hold['id'])->update([
            'status' => 2,
            'version' => (int) $hold['version'] + 1,
            'update_time' => time(),
        ]);
    }

    private function inconsistent(): MemberTransactionException
    {
        return new MemberTransactionException(503, 'event_order_inconsistent', 'Event payment data is inconsistent');
    }
}
