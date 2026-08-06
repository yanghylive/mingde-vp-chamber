<?php

declare(strict_types=1);

namespace app\chamber\services;

use app\chamber\commerce\CommerceEvent;
use app\chamber\commerce\CommerceEventType;
use app\chamber\commerce\Money;
use app\chamber\exceptions\MemberTransactionException;
use app\chamber\membership\GraduateVerificationState;
use app\chamber\membership\MemberTier;
use app\chamber\membership\OrderContextState;
use app\chamber\membership\MembershipTermState;
use think\facade\Db;
use Throwable;

/**
 * Consumes Chamber commerce facts into append-only membership terms and the
 * small member projection used by request-time authorization.
 */
final class MembershipEntitlementService
{
    private const MAX_SUMMARY_TERMS = 100;
    private const TIMEZONE = 'Asia/Shanghai';

    /**
     * Apply one event. Callers may wrap this in a larger transaction; the
     * method deliberately does not start or commit a transaction itself.
     */
    public function consumeEvent(CommerceEvent $event): void
    {
        $row = Db::table('ch_commerce_event_inbox')
            ->where('event_id', $event->eventId())
            ->lock(true)
            ->find();
        if (!is_array($row)) {
            throw new MemberTransactionException(
                503,
                'membership_order_inconsistent',
                'Commerce event inbox row is unavailable'
            );
        }
        if ((string) $row['payload_hash'] !== $event->payloadHash()) {
            throw new MemberTransactionException(
                409,
                'membership_order_inconsistent',
                'Commerce event payload changed after recording'
            );
        }
        if ((string) $row['status'] === 'processed') {
            return;
        }

        if ($event->eventType() === CommerceEventType::ORDER_COMPLETED) {
            $this->grantMembershipTerm($event);
        } elseif ($event->eventType() === CommerceEventType::REFUND_COMPLETED) {
            $this->applyCompletedRefund($event);
        } else {
            $this->advanceRefundLifecycle($event);
        }

        $now = time();
        Db::table('ch_commerce_event_inbox')
            ->where('id', (int) $row['id'])
            ->where('status', '<>', 'processed')
            ->update([
                'status' => 'processed',
                'attempt_count' => (int) $row['attempt_count'] + 1,
                'lease_token' => '',
                'lease_expire_time' => 0,
                'processed_time' => $now,
                'update_time' => $now,
            ]);
    }

    /**
     * Reconcile due projections. It is safe to call from CRMEB's timer and
     * from a one-off CLI repair command.
     */
    public function reconcileDue(int $limit = 50): array
    {
        if ($limit < 1 || $limit > 500) {
            throw new \InvalidArgumentException('Membership projection limit must be between 1 and 500');
        }
        $now = time();
        $rows = Db::table('ch_tenant_member')
            ->where('is_del', 0)
            ->where(function ($query) use ($now): void {
                $query->where('tier', '>=', 3)
                    ->whereOr(function ($nested) use ($now): void {
                        $nested->whereIn('id', function ($sub) use ($now): void {
                            $sub->table('ch_membership_term')
                                ->where('state', MembershipTermState::GRANTED)
                                ->where('effective_start_time', '<=', $now)
                                ->where('effective_end_time', '>', $now)
                                ->field('member_id');
                        });
                    })
                    ->whereOr(function ($nested) use ($now): void {
                        $nested->whereIn('id', function ($sub) use ($now): void {
                            $sub->table('ch_membership_term')
                                ->where('state', MembershipTermState::GRANTED)
                                ->where('effective_end_time', '<=', $now)
                                ->field('member_id');
                        });
                    });
            })
            ->field('id')
            ->order('id', 'asc')
            ->limit($limit)
            ->select()
            ->toArray();

        $summary = ['scanned' => count($rows), 'reprojected' => 0, 'failed' => 0];
        foreach ($rows as $row) {
            try {
                Db::transaction(function () use ($row, $now): void {
                    $member = Db::table('ch_tenant_member')
                        ->where('id', (int) $row['id'])
                        ->lock(true)
                        ->find();
                    if (is_array($member)) {
                        $this->projectMember($member, $now);
                    }
                });
                $summary['reprojected']++;
            } catch (Throwable $exception) {
                $summary['failed']++;
            }
        }

        return $summary;
    }

    /** Consume received/failed inbox rows that are safe to retry. */
    public function consumePending(int $limit = 50): array
    {
        if ($limit < 1 || $limit > 500) {
            throw new \InvalidArgumentException('Commerce event limit must be between 1 and 500');
        }
        $now = time();
        $rows = Db::table('ch_commerce_event_inbox')
            ->where('business_type', 'membership')
            ->whereIn('status', ['received', 'failed'])
            ->where('next_retry_time', '<=', $now)
            ->order('id', 'asc')
            ->limit($limit)
            ->select()
            ->toArray();
        $summary = ['scanned' => count($rows), 'processed' => 0, 'failed' => 0];
        foreach ($rows as $candidate) {
            try {
                Db::transaction(function () use ($candidate): void {
                    $row = Db::table('ch_commerce_event_inbox')
                        ->where('id', (int) $candidate['id'])
                        ->lock(true)
                        ->find();
                    if (!is_array($row) || (string) $row['status'] === 'processed') {
                        return;
                    }
                    $payload = json_decode((string) $row['payload_json'], true);
                    if (!is_array($payload) || json_last_error() !== JSON_ERROR_NONE) {
                        throw $this->inconsistent();
                    }
                    $event = CommerceEvent::fromArray($payload);
                    $this->consumeEvent($event);
                });
                $summary['processed']++;
            } catch (Throwable $exception) {
                $summary['failed']++;
                $attempt = (int) ($candidate['attempt_count'] ?? 0) + 1;
                $delay = min(3600, max(60, 60 * (2 ** min(5, $attempt))));
                Db::table('ch_commerce_event_inbox')->where('id', (int) $candidate['id'])->update([
                    'status' => 'failed',
                    'attempt_count' => $attempt,
                    'last_error_code' => 'membership_consume_failed',
                    'next_retry_time' => time() + $delay,
                    'lease_token' => '',
                    'lease_expire_time' => 0,
                    'update_time' => time(),
                ]);
            }
        }

        return $summary;
    }

    public function summary(int $tenantId, int $channelId, int $uid): array
    {
        $now = time();
        $member = Db::transaction(function () use ($tenantId, $channelId, $uid, $now): array {
            $row = Db::table('ch_tenant_member')
                ->where('tenant_id', $tenantId)
                ->where('uid', $uid)
                ->where('is_del', 0)
                ->lock(true)
                ->find();
            if (!is_array($row) || (int) $row['current_channel_id'] !== $channelId) {
                throw new MemberTransactionException(404, 'member_not_found', 'Member is not available in this channel');
            }
            $this->projectMember($row, $now);

            return Db::table('ch_tenant_member')
                ->where('id', (int) $row['id'])
                ->find();
        });

        $terms = Db::table('ch_membership_term')
            ->where('tenant_id', $tenantId)
            ->where('member_id', (int) $member['id'])
            ->where('state', MembershipTermState::GRANTED)
            ->where('effective_end_time', '>', $now)
            ->order('effective_start_time', 'asc')
            ->order('tier', 'desc')
            ->order('id', 'asc')
            ->limit(self::MAX_SUMMARY_TERMS)
            ->select()
            ->toArray();

        $termSummaries = [];
        $termIds = [];
        foreach ($terms as $term) {
            $termSummaries[] = $this->termSummary($term, $now);
            $termIds[] = (int) $term['id'];
        }
        $current = null;
        $currentId = (int) ($member['current_membership_term_id'] ?? 0);
        foreach ($termSummaries as $index => $term) {
            if (($termIds[$index] ?? 0) === $currentId) {
                $current = $term;
                break;
            }
        }
        if ($current === null && $currentId > 0) {
            $row = Db::table('ch_membership_term')
                ->where('id', $currentId)
                ->where('state', MembershipTermState::GRANTED)
                ->where('effective_end_time', '>', $now)
                ->find();
            if (is_array($row) && (int) $row['member_id'] === (int) $member['id']) {
                $current = $this->termSummary($row, $now);
            }
        }

        $verificationStatus = GraduateVerificationState::fromDatabase((int) $member['verification_status']);
        return [
            'effective_tier' => MemberTier::fromDatabaseRank((int) $member['tier']),
            'verification_status' => $verificationStatus,
            'tier_expires_at' => (int) $member['tier_expire_time'],
            'current_term' => $current,
            'active_terms' => $termSummaries,
            'can_purchase' => (int) $member['verification_status'] === GraduateVerificationState::toDatabase(
                GraduateVerificationState::APPROVED
            ),
        ];
    }

    private function grantMembershipTerm(CommerceEvent $event): void
    {
        $payload = $event->payload();
        if ($payload['business_type'] !== 'membership') {
            return;
        }
        $context = $this->lockContext($event->tenantId(), $event->orderPk(), (int) $payload['context_id']);
        $this->assertCompletedContext($event, $context);
        $member = Db::table('ch_tenant_member')
            ->where('id', (int) $context['member_id'])
            ->where('tenant_id', $event->tenantId())
            ->lock(true)
            ->find();
        if (!is_array($member) || (int) $member['uid'] !== (int) $payload['uid']) {
            throw $this->inconsistent();
        }

        $existing = Db::table('ch_membership_term')
            ->where('tenant_id', $event->tenantId())
            ->where('order_context_id', (int) $context['id'])
            ->lock(true)
            ->find();
        if (is_array($existing)) {
            $this->projectMember($member, time());
            return;
        }

        $snapshot = $this->decodeObject($context['entitlement_snapshot_json']);
        $refundPolicy = $this->decodeObject($context['refund_policy_snapshot_json']);
        $planSnapshot = [
            'id' => (int) $context['business_id'],
            'code' => $this->identifier($snapshot, 'plan_code'),
            'version' => $this->positiveInt($snapshot, 'plan_version'),
            'tier' => $this->identifier($snapshot, 'tier'),
            'term_months' => $this->positiveInt($snapshot, 'term_months'),
        ];
        $tier = MemberTier::rank($planSnapshot['tier']);
        if (!in_array($tier, [3, 4], true)) {
            throw $this->inconsistent();
        }
        $paidAmount = Money::assertAmount((string) $context['paid_amount'], 'paid_amount');
        $now = (int) $payload['paid_at'];
        if ($now <= 0) {
            $now = time();
        }
        $start = $now;
        $last = Db::table('ch_membership_term')
            ->where('tenant_id', $event->tenantId())
            ->where('member_id', (int) $member['id'])
            ->where('tier', $tier)
            ->where('state', MembershipTermState::GRANTED)
            ->order('effective_end_time', 'desc')
            ->lock(true)
            ->find();
        if (is_array($last) && (int) $last['effective_end_time'] > $start) {
            $start = (int) $last['effective_end_time'];
        }
        $end = $this->addMonths($start, $planSnapshot['term_months']);
        $termNo = substr(hash('sha256', $event->eventId() . ':term'), 0, 32);
        $createdAt = time();
        $termId = (int) Db::table('ch_membership_term')->insertGetId([
            'tenant_id' => $event->tenantId(),
            'channel_id' => $event->channelId(),
            'member_id' => (int) $member['id'],
            'uid' => (int) $payload['uid'],
            'term_no' => $termNo,
            'plan_id' => (int) $context['business_id'],
            'plan_code' => $planSnapshot['code'],
            'tier' => $tier,
            'order_context_id' => (int) $context['id'],
            'order_pk' => $event->orderPk(),
            'order_no' => $payload['order_no'],
            'source_type' => 'purchase',
            'currency' => $payload['currency'],
            'paid_amount' => $paidAmount,
            'refunded_amount' => '0.00',
            'original_start_time' => $start,
            'original_end_time' => $end,
            'effective_start_time' => $start,
            'effective_end_time' => $end,
            'state' => MembershipTermState::GRANTED,
            'grant_event_id' => $event->eventId(),
            'plan_snapshot_json' => $this->json($planSnapshot),
            'benefits_snapshot_json' => $this->json($snapshot['benefits'] ?? []),
            'refund_policy_snapshot_json' => $this->json($refundPolicy),
            'version' => 1,
            'add_time' => $createdAt,
            'update_time' => $createdAt,
        ]);
        if ($termId <= 0) {
            throw $this->inconsistent();
        }

        $effectPayload = [
            'event_id' => $event->eventId(),
            'term_id' => $termId,
            'context_id' => (int) $context['id'],
            'type' => 'grant',
            'after_end' => $end,
        ];
        Db::table('ch_membership_term_effect')->insert([
            'tenant_id' => $event->tenantId(),
            'term_id' => $termId,
            'order_context_id' => (int) $context['id'],
            'effect_key' => hash('sha256', 'grant:' . $event->eventId()),
            'effect_hash' => hash('sha256', $this->json($effectPayload)),
            'event_id' => $event->eventId(),
            'completion_id' => null,
            'effect_type' => 'grant',
            'refund_delta' => '0.00',
            'before_state' => 0,
            'after_state' => MembershipTermState::GRANTED,
            'before_end_time' => 0,
            'after_end_time' => $end,
            'reason_code' => 'payment_completed',
            'operator_type' => 0,
            'operator_id' => 0,
            'add_time' => $createdAt,
        ]);
        $this->projectMember($member, time());
    }

    private function applyCompletedRefund(CommerceEvent $event): void
    {
        $payload = $event->payload();
        if ($payload['business_type'] !== 'membership') {
            return;
        }
        $context = $this->lockContext($event->tenantId(), $event->orderPk(), (int) $payload['context_id']);
        $this->assertCompletedContext($event, $context);
        $term = Db::table('ch_membership_term')
            ->where('tenant_id', $event->tenantId())
            ->where('order_context_id', (int) $context['id'])
            ->lock(true)
            ->find();
        if (!is_array($term)) {
            throw $this->inconsistent();
        }
        $delta = Money::assertAmount((string) $payload['refund_delta'], 'refund_delta');
        $cumulative = Money::assertAmount((string) $payload['cumulative_refunded_amount'], 'cumulative_refunded_amount');
        $paid = Money::assertAmount((string) $context['paid_amount'], 'paid_amount');
        $previous = Money::assertAmount((string) $context['refunded_amount'], 'refunded_amount');
        $previousMinor = Money::toMinor($previous);
        $cumulativeMinor = Money::toMinor($cumulative);
        $deltaMinor = Money::toMinor($delta);
        if ($cumulativeMinor > Money::toMinor($paid)
            || $cumulativeMinor < $previousMinor
            || $cumulativeMinor - $previousMinor !== $deltaMinor) {
            throw $this->inconsistent();
        }

        $effectKey = hash('sha256', 'refund:' . $event->completionFingerprint());
        $effectExists = Db::table('ch_membership_term_effect')
            ->where('tenant_id', $event->tenantId())
            ->where('effect_key', $effectKey)
            ->find();
        if (!$effectExists) {
            $full = Money::toMinor($cumulative) === Money::toMinor($paid);
            $beforeState = (int) $term['state'];
            $afterState = $full ? MembershipTermState::FULLY_REFUNDED : $beforeState;
            Db::table('ch_membership_term_effect')->insert([
                'tenant_id' => $event->tenantId(),
                'term_id' => (int) $term['id'],
                'order_context_id' => (int) $context['id'],
                'effect_key' => $effectKey,
                'effect_hash' => hash('sha256', $event->payloadHash()),
                'event_id' => $event->eventId(),
                'completion_id' => $event->completionId(),
                'effect_type' => $full ? 'full_refund' : 'adjustment',
                'refund_delta' => $delta,
                'before_state' => $beforeState,
                'after_state' => $afterState,
                'before_end_time' => (int) $term['effective_end_time'],
                'after_end_time' => (int) $term['effective_end_time'],
                'reason_code' => $full ? 'refund_completed' : 'partial_refund',
                'operator_type' => 0,
                'operator_id' => 0,
                'add_time' => time(),
            ]);
            Db::table('ch_membership_term')->where('id', (int) $term['id'])->update([
                'refunded_amount' => $cumulative,
                'state' => $afterState,
                'version' => (int) $term['version'] + 1,
                'update_time' => time(),
            ]);
        }
        Db::table('ch_order_context')->where('id', (int) $context['id'])->update([
            'refunded_amount' => $cumulative,
            'refund_status' => Money::toMinor($cumulative) === Money::toMinor($paid) ? 4 : 3,
            'version' => (int) $context['version'] + 1,
            'update_time' => time(),
        ]);
        $member = Db::table('ch_tenant_member')->where('id', (int) $context['member_id'])->lock(true)->find();
        if (is_array($member)) {
            $this->projectMember($member, time());
        }
    }

    private function advanceRefundLifecycle(CommerceEvent $event): void
    {
        $payload = $event->payload();
        if ($payload['business_type'] !== 'membership') {
            return;
        }
        $context = $this->lockContext($event->tenantId(), $event->orderPk(), (int) $payload['context_id']);
        $status = [
            CommerceEventType::REFUND_REQUESTED => 1,
            CommerceEventType::REFUND_PROCESSING => 2,
            CommerceEventType::REFUND_CANCELLED => 5,
            CommerceEventType::REFUND_FAILED => 6,
        ][$event->eventType()] ?? null;
        if ($status === null) {
            return;
        }
        $this->assertCompletedContext($event, $context);
        $currentStatus = (int) $context['refund_status'];
        OrderContextState::assertRefundStatus($currentStatus);
        if ($currentStatus !== $status && !OrderContextState::canRefundTransition($currentStatus, $status)) {
            throw $this->inconsistent();
        }
        if ($currentStatus === $status) {
            return;
        }
        Db::table('ch_order_context')->where('id', (int) $context['id'])->update([
            'refund_status' => $status,
            'version' => (int) $context['version'] + 1,
            'update_time' => time(),
        ]);
    }

    private function projectMember(array $member, int $now): void
    {
        $terms = Db::table('ch_membership_term')
            ->where('tenant_id', (int) $member['tenant_id'])
            ->where('member_id', (int) $member['id'])
            ->where('state', MembershipTermState::GRANTED)
            ->select()
            ->toArray();
        $active = [];
        foreach ($terms as $term) {
            if ((int) $term['effective_start_time'] <= $now && $now < (int) $term['effective_end_time']) {
                $active[] = $term;
            }
        }
        usort($active, function (array $left, array $right): int {
            $tier = ((int) $right['tier']) <=> ((int) $left['tier']);
            if ($tier !== 0) {
                return $tier;
            }
            $end = ((int) $right['effective_end_time']) <=> ((int) $left['effective_end_time']);
            return $end !== 0 ? $end : ((int) $left['id']) <=> ((int) $right['id']);
        });
        $approved = (int) $member['verification_status'] === GraduateVerificationState::toDatabase(
            GraduateVerificationState::APPROVED
        );
        $tierNames = [];
        foreach ($active as $term) {
            $tierNames[] = MemberTier::fromDatabaseRank((int) $term['tier']);
        }
        $projected = MemberTier::project($approved, $tierNames);
        $projectedRank = MemberTier::rank($projected);
        $currentTerm = $active[0] ?? null;
        $expire = $currentTerm ? (int) $currentTerm['effective_end_time'] : 0;
        $currentTermId = $currentTerm ? (int) $currentTerm['id'] : 0;
        if ((int) $member['tier'] === $projectedRank
            && (int) ($member['tier_expire_time'] ?? 0) === $expire
            && (int) ($member['current_membership_term_id'] ?? 0) === $currentTermId) {
            return;
        }
        Db::table('ch_tenant_member')->where('id', (int) $member['id'])->update([
            'tier' => $projectedRank,
            'tier_expire_time' => $expire,
            'current_membership_term_id' => $currentTermId,
            'membership_version' => (int) ($member['membership_version'] ?? 0) + 1,
            'update_time' => time(),
        ]);
    }

    private function lockContext(int $tenantId, int $orderPk, int $contextId): array
    {
        $query = Db::table('ch_order_context')
            ->where('tenant_id', $tenantId)
            ->where('business_type', 'membership')
            ->where('order_pk', $orderPk);
        if ($contextId > 0) {
            $query->where('id', $contextId);
        }
        $context = $query->lock(true)->find();
        if (!is_array($context)) {
            throw $this->inconsistent();
        }
        return $context;
    }

    private function assertCompletedContext(CommerceEvent $event, array $context): void
    {
        $payload = $event->payload();
        if ((int) $context['tenant_id'] !== $event->tenantId()
            || (int) $context['channel_id'] !== $event->channelId()
            || (int) $context['order_pk'] !== $event->orderPk()
            || (string) $context['order_no'] !== (string) $payload['order_no']
            || (int) $context['uid'] !== (int) $payload['uid']
            || (string) $context['currency'] !== (string) $payload['currency']
            || (int) $context['pay_status'] !== OrderContextState::PAY_COMPLETED
            || Money::toMinor(Money::assertAmount((string) $context['paid_amount'], 'paid_amount'))
                !== Money::toMinor($event->paidAmount())) {
            throw $this->inconsistent();
        }
        if ($event->eventType() === CommerceEventType::ORDER_COMPLETED) {
            if ((string) $context['completion_kind'] !== (string) ($payload['completion_kind'] ?? '')
                || (int) $context['paid_time'] !== (int) ($payload['paid_at'] ?? 0)) {
                throw $this->inconsistent();
            }
        }
    }

    /** @return array<string,mixed> */
    private function termSummary(array $term, int $now): array
    {
        $plan = $this->decodeObject($term['plan_snapshot_json']);
        return [
            'term_no' => (string) $term['term_no'],
            'tier' => (string) ($plan['tier'] ?? MemberTier::fromDatabaseRank((int) $term['tier'])),
            'plan_code' => (string) $term['plan_code'],
            'plan_version' => (int) ($plan['version'] ?? 1),
            'status' => MembershipTermState::effectiveStatus(
                (int) $term['state'],
                (int) $term['effective_start_time'],
                (int) $term['effective_end_time'],
                $now
            ),
            'starts_at' => (int) $term['effective_start_time'],
            'ends_at' => (int) $term['effective_end_time'],
            'source_order_no' => (string) $term['order_no'],
        ];
    }

    private function addMonths(int $start, int $months): int
    {
        if ($start <= 0 || $months <= 0) {
            throw $this->inconsistent();
        }
        $date = new \DateTimeImmutable('@' . $start);
        $date = $date->setTimezone(new \DateTimeZone(self::TIMEZONE));
        $date = $date->modify('+' . $months . ' months');
        return $date->setTimezone(new \DateTimeZone('UTC'))->getTimestamp();
    }

    /** @return array<string,mixed> */
    private function decodeObject($json): array
    {
        if (!is_string($json)) {
            throw $this->inconsistent();
        }
        $value = json_decode($json, true);
        if (!is_array($value) || json_last_error() !== JSON_ERROR_NONE) {
            throw $this->inconsistent();
        }
        return $value;
    }

    private function identifier(array $value, string $key): string
    {
        $item = $value[$key] ?? null;
        if (!is_string($item) || $item === '' || strlen($item) > 64) {
            throw $this->inconsistent();
        }
        return $item;
    }

    private function positiveInt(array $value, string $key): int
    {
        $item = $value[$key] ?? null;
        if (is_string($item) && ctype_digit($item)) {
            $item = (int) $item;
        }
        if (!is_int($item) || $item <= 0) {
            throw $this->inconsistent();
        }
        return $item;
    }

    private function json(array $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            throw $this->inconsistent();
        }
        return $json;
    }

    private function inconsistent(): MemberTransactionException
    {
        return new MemberTransactionException(
            503,
            'membership_order_inconsistent',
            'Membership entitlement data is inconsistent'
        );
    }
}
