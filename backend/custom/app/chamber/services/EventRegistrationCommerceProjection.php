<?php

declare(strict_types=1);

namespace app\chamber\services;

use app\chamber\commerce\CommerceEvent;
use app\chamber\commerce\CommerceEventType;
use app\chamber\exceptions\MemberTransactionException;
use think\facade\Db;

/** Projects a trusted cash payment into one confirmed event registration. */
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
        if (($payload['business_type'] ?? '') !== 'event_registration'
            || $event->eventType() !== CommerceEventType::ORDER_COMPLETED) {
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
        $context = Db::table('ch_order_context')
            ->where('tenant_id', $event->tenantId())
            ->where('id', (int) $payload['context_id'])
            ->where('business_type', 'event_registration')
            ->lock(true)
            ->find();
        if (!is_array($context)
            || (int) $context['order_pk'] !== $event->orderPk()
            || (int) $context['pay_status'] !== 1
            || (int) $context['uid'] !== (int) $payload['uid']) {
            throw $this->inconsistent();
        }
        $registration = Db::table('ch_event_registration')
            ->where('tenant_id', $event->tenantId())
            ->where('id', (int) $context['business_id'])
            ->lock(true)
            ->find();
        if (!is_array($registration)
            || (int) $registration['order_context_id'] !== (int) $context['id']
            || (int) $registration['uid'] !== (int) $payload['uid']) {
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
