<?php

declare(strict_types=1);

namespace app\chamber\services;

use app\chamber\contracts\EventOrderGatewayInterface;
use think\facade\Db;
use Throwable;

/** Releases expired event reservations after the CRMEB payment race is settled. */
final class EventReservationRepairService
{
    /** @var EventOrderGatewayInterface */
    private $orders;

    public function __construct(EventOrderGatewayInterface $orders)
    {
        $this->orders = $orders;
    }

    public function releaseExpired(int $limit = 50): array
    {
        $rows = Db::table('ch_event_registration')->where('status', 0)
            ->where('reserve_expire_time', '>', 0)
            ->where('reserve_expire_time', '<=', time())
            ->field('id,order_context_id')->order('reserve_expire_time', 'asc')
            ->order('id', 'asc')->limit($limit)->select()->toArray();
        $summary = ['scanned' => count($rows), 'released' => 0, 'payment_won' => 0, 'failed' => 0];
        foreach ($rows as $candidate) {
            try {
                $context = Db::table('ch_order_context')->where('id', (int) $candidate['order_context_id'])
                    ->where('business_type', 'event_registration')->find();
                if (!is_array($context)) {
                    throw new \RuntimeException('event context missing');
                }
                $order = $this->orders->findByCheckoutKey((int) $context['uid'], (string) $context['context_no']);
                if ($order !== null && !$this->orders->cancelUnpaid($order)) {
                    $summary['payment_won']++;
                    continue;
                }
                if ($this->release((int) $candidate['id'])) {
                    $summary['released']++;
                }
            } catch (Throwable $exception) {
                $summary['failed']++;
            }
        }

        return $summary;
    }

    private function release(int $registrationId): bool
    {
        return (bool) Db::transaction(function () use ($registrationId): bool {
            $registration = Db::table('ch_event_registration')->where('id', $registrationId)
                ->lock(true)->find();
            if (!is_array($registration) || (int) $registration['status'] !== 0) {
                return false;
            }
            $context = Db::table('ch_order_context')->where('id', (int) $registration['order_context_id'])
                ->where('business_type', 'event_registration')->lock(true)->find();
            if (!is_array($context) || (int) $context['pay_status'] === 1) {
                return false;
            }
            if ((int) $context['pay_status'] !== 0) {
                return false;
            }
            $ticket = Db::table('ch_event_ticket')->where('tenant_id', (int) $registration['tenant_id'])
                ->where('id', (int) $registration['ticket_id'])->lock(true)->find();
            if (!is_array($ticket) || (int) $ticket['reserved_count'] < 1) {
                throw new \RuntimeException('event reservation counter is inconsistent');
            }
            $now = time();
            Db::table('ch_event_ticket')->where('id', (int) $ticket['id'])->update([
                'reserved_count' => (int) $ticket['reserved_count'] - 1,
                'update_time' => $now,
            ]);
            $this->releasePointHold($registration, $now);
            Db::table('ch_order_context')->where('id', (int) $context['id'])->update([
                'pay_status' => 3,
                'version' => (int) $context['version'] + 1,
                'update_time' => $now,
            ]);
            Db::table('ch_event_registration')->where('id', $registrationId)->update([
                'status' => 2,
                'reserve_expire_time' => 0,
                'cancel_time' => $now,
                'update_time' => $now,
            ]);

            return true;
        });
    }

    private function releasePointHold(array $registration, int $now): void
    {
        $hold = Db::table('ch_point_hold')->where('tenant_id', (int) $registration['tenant_id'])
            ->where('registration_id', (int) $registration['id'])->lock(true)->find();
        if (!is_array($hold)) {
            if ((int) $registration['integral_amount'] === 0) {
                return;
            }
            throw new \RuntimeException('event point hold is missing');
        }
        if ((int) $hold['status'] === 3) {
            return;
        }
        if ((int) $hold['status'] !== 1) {
            throw new \RuntimeException('event point hold is not releasable');
        }
        $amount = (int) $hold['amount'];
        $account = Db::table('ch_point_account')->where('id', (int) $hold['account_id'])
            ->where('tenant_id', (int) $registration['tenant_id'])->lock(true)->find();
        if (!is_array($account) || (int) $account['frozen_balance'] < $amount) {
            throw new \RuntimeException('event point balance is inconsistent');
        }
        Db::table('ch_point_account')->where('id', (int) $account['id'])->update([
            'balance' => (int) $account['balance'] + $amount,
            'frozen_balance' => (int) $account['frozen_balance'] - $amount,
            'version' => (int) $account['version'] + 1,
            'update_time' => $now,
        ]);
        Db::table('ch_point_hold')->where('id', (int) $hold['id'])->update([
            'status' => 3,
            'version' => (int) $hold['version'] + 1,
            'update_time' => $now,
        ]);
    }
}
