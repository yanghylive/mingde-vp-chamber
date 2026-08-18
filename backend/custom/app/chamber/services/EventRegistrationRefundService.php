<?php

declare(strict_types=1);

namespace app\chamber\services;

use app\chamber\activity\EventRefundRequest;
use app\chamber\activity\EventTicketOrderSnapshot;
use app\chamber\commerce\CommerceEvent;
use app\chamber\commerce\CommerceEventType;
use app\chamber\commerce\EventRefundGatewayResult;
use app\chamber\commerce\Money;
use app\chamber\commerce\RefundAttemptState;
use app\chamber\contracts\CommerceEventStoreInterface;
use app\chamber\contracts\EventRefundGatewayInterface;
use app\chamber\exceptions\MemberTransactionException;
use app\chamber\identity\AuthenticatedUserContext;
use app\chamber\membership\BootstrapIdempotency;
use app\chamber\membership\OrderContextState;
use app\chamber\tenancy\TenantContext;
use app\chamber\tenancy\TenantRecord;
use think\facade\Db;

/** Initiates trusted CRMEB refunds for paid event registrations. */
final class EventRegistrationRefundService
{
    private const OPERATION = 'createEventRegistrationRefund';
    private const SOURCE_TYPE = 'event_registration';
    private const QUERY_RETRY_SECONDS = 300;

    /** @var EventService */
    private $events;

    /** @var EventIdempotency */
    private $idempotency;

    /** @var CommerceEventStoreInterface */
    private $commerceEvents;

    /** @var EventRegistrationCommerceProjection */
    private $projection;

    /** @var EventRefundGatewayInterface */
    private $refunds;

    /** @var callable */
    private $clock;

    /** @var callable */
    private $refundNoFactory;

    public function __construct(
        EventService $events = null,
        EventIdempotency $idempotency = null,
        CommerceEventStoreInterface $commerceEvents = null,
        EventRegistrationCommerceProjection $projection = null,
        EventRefundGatewayInterface $refunds = null,
        callable $clock = null,
        callable $refundNoFactory = null
    ) {
        $this->events = $events ?: new EventService();
        $this->idempotency = $idempotency ?: new EventIdempotency();
        $this->commerceEvents = $commerceEvents ?: app()->make(CommerceEventStoreInterface::class);
        $this->projection = $projection ?: new EventRegistrationCommerceProjection();
        $this->refunds = $refunds ?: app()->make(EventRefundGatewayInterface::class);
        $this->clock = $clock ?: function (): int {
            return time();
        };
        $this->refundNoFactory = $refundNoFactory ?: function (
            int $tenantId,
            int $registrationId,
            int $uid,
            int $now,
            string $reasonHash
        ): string {
            return strtoupper(substr(hash('sha256', implode(':', [
                'event_registration_refund',
                $tenantId,
                $registrationId,
                $uid,
                $now,
                $reasonHash,
                bin2hex(random_bytes(12)),
            ])), 0, 32));
        };
    }

    public function refund(
        TenantContext $tenant,
        AuthenticatedUserContext $auth,
        int $registrationId,
        EventRefundRequest $request,
        string $callerKey
    ): array {
        if ($registrationId <= 0) {
            throw $this->validation('registration_id', 'invalid_value', 'registration_id must be a positive integer');
        }

        return $this->idempotency->execute(
            $tenant,
            self::OPERATION,
            'crmeb_user',
            $auth->uid(),
            $callerKey,
            [
                'registration_id' => $registrationId,
                'reason' => $request->reason(),
            ],
            201,
            function (int $now) use ($tenant, $auth, $registrationId, $request, $callerKey): array {
                $member = $this->member($tenant, $auth, true);
                $registration = $this->lockedRegistration($tenant, $member, $registrationId, false);
                $context = $this->lockedOrderContext($tenant, $registration);
                $registration = $this->lockedRegistration($tenant, $member, $registrationId, true);
                $snapshot = EventTicketOrderSnapshot::fromContext($context);
                $policy = $this->refundPolicy($snapshot->refundPolicySnapshot());

                $paidAmount = Money::assertAmount((string) $context['paid_amount'], 'paid_amount');
                $refundedAmount = Money::assertAmount((string) $context['refunded_amount'], 'refunded_amount');
                $paidMinor = Money::toMinor($paidAmount);
                $refundedMinor = Money::toMinor($refundedAmount);
                if ($refundedMinor > 0) {
                    throw $this->conflict('refund_already_processed', 'This registration already has refunded funds');
                }
                $refundStatus = (int) $context['refund_status'];
                OrderContextState::assertRefundStatus($refundStatus);
                if (in_array($refundStatus, [
                    OrderContextState::REFUND_REQUESTED,
                    OrderContextState::REFUND_PROCESSING,
                ], true)) {
                    throw $this->conflict('refund_in_progress', 'A refund request is already in progress');
                }
                if (in_array($refundStatus, [
                    OrderContextState::REFUND_PARTIALLY_COMPLETED,
                    OrderContextState::REFUND_COMPLETED,
                ], true)) {
                    throw $this->conflict('refund_already_processed', 'This registration already has refunded funds');
                }
                if ((int) $context['pay_status'] !== OrderContextState::PAY_COMPLETED
                    || (string) $context['completion_kind'] !== OrderContextState::COMPLETION_PAID
                    || $paidMinor <= 0) {
                    throw $this->conflict('refund_not_available', 'This registration is not refundable');
                }
                if ((int) $registration['status'] !== 1 && (int) $registration['status'] !== 5) {
                    throw $this->conflict('refund_not_available', 'This registration is not refundable');
                }
                if (!in_array((string) $policy['mode'], ['full_before_deadline', 'partial_before_deadline'], true)
                    || (int) $policy['deadline_time'] <= 0
                    || $now > (int) $policy['deadline_time']) {
                    throw $this->conflict('refund_not_available', 'This registration is not refundable');
                }

                $amount = null;
                if ((string) $policy['mode'] === 'full_before_deadline') {
                    $amount = $paidAmount;
                } elseif ((string) $policy['mode'] === 'partial_before_deadline'
                    && (int) ($policy['percent'] ?? 0) === 100) {
                    $amount = $paidAmount;
                }
                if (!is_string($amount) || Money::toMinor($amount) <= 0) {
                    throw $this->conflict('refund_amount_unsupported', 'Refund amount is not supported yet');
                }

                $order = $this->refunds->loadOrder((int) $context['order_pk']);
                $provider = $this->refunds->provider($order);
                if (!$this->refunds->supportsAutomaticAmount($order, $amount, $paidAmount)) {
                    throw $this->conflict('refund_amount_unsupported', 'Refund amount is not supported yet');
                }

                $reasonHash = hash('sha256', $request->reason());
                $internalKey = BootstrapIdempotency::deriveInternalKey(
                    $tenant->tenantId(),
                    self::OPERATION,
                    'crmeb_user',
                    $auth->uid(),
                    $callerKey
                );
                $idempotencyRecord = Db::table('ch_idempotency_record')
                    ->where('tenant_id', $tenant->tenantId())
                    ->where('idempotency_key', $internalKey)
                    ->lock(true)
                    ->find();
                if (!is_array($idempotencyRecord)) {
                    throw $this->inconsistent();
                }
                $refundNo = call_user_func(
                    $this->refundNoFactory,
                    $tenant->tenantId(),
                    (int) $registration['id'],
                    $auth->uid(),
                    $now,
                    $reasonHash
                );
                if (!is_string($refundNo) || preg_match('/^[A-Z0-9]{16,32}$/D', $refundNo) !== 1) {
                    throw $this->inconsistent();
                }
                $providerRefundNo = $refundNo;
                $requestHash = BootstrapIdempotency::requestHash($tenant->channelId(), [
                    'registration_id' => $registrationId,
                    'reason' => $request->reason(),
                ]);
                $attemptId = (int) Db::table('ch_refund_attempt')->insertGetId([
                    'tenant_id' => $tenant->tenantId(),
                    'channel_id' => $tenant->channelId(),
                    'refund_no' => $refundNo,
                    'idempotency_record_id' => (int) $idempotencyRecord['id'],
                    'commerce_event_id' => 0,
                    'source_type' => self::SOURCE_TYPE,
                    'source_id' => (string) $registration['id'],
                    'order_context_id' => (int) $context['id'],
                    'requester_uid' => $auth->uid(),
                    'crmeb_order_id' => (int) $context['order_pk'],
                    'crmeb_order_no' => (string) $context['order_no'],
                    'crmeb_refund_id' => 0,
                    'provider' => $provider,
                    'provider_trade_no' => $this->providerTradeNo($order),
                    'provider_refund_no' => $providerRefundNo,
                    'provider_refund_id' => '',
                    'provider_status' => 'requested',
                    'currency' => (string) $context['currency'],
                    'amount' => $amount,
                    'status' => RefundAttemptState::REQUESTED,
                    'request_hash' => $requestHash,
                    'last_response_hash' => '',
                    'query_retry_count' => 0,
                    'next_query_time' => 0,
                    'last_query_time' => 0,
                    'final_confirmed' => 0,
                    'final_confirm_source' => '',
                    'final_confirm_time' => 0,
                    'failure_code' => '',
                    'manual_operator_id' => 0,
                    'manual_reference' => '',
                    'request_time' => $now,
                    'processing_time' => 0,
                    'add_time' => $now,
                    'update_time' => $now,
                    'paid_amount' => $paidAmount,
                    'cumulative_before' => $refundedAmount,
                    'cumulative_after' => $amount,
                    'reason_hash' => $reasonHash,
                    'lease_token' => '',
                    'lease_expire_time' => 0,
                    'version' => 1,
                ]);
                if ($attemptId <= 0) {
                    throw $this->inconsistent();
                }
                $requestEvent = $this->refundEvent(
                    CommerceEventType::REFUND_REQUESTED,
                    $tenant,
                    $auth,
                    $context,
                    $registration,
                    $refundNo,
                    $providerRefundNo,
                    $attemptId,
                    $paidAmount,
                    $now,
                    'requested',
                    ''
                );
                $receipt = $this->commerceEvents->record($requestEvent);
                $inboxId = (int) Db::table('ch_commerce_event_inbox')
                    ->where('event_id', $receipt->eventId())
                    ->value('id');
                if ($inboxId <= 0) {
                    throw $this->inconsistent();
                }
                Db::table('ch_refund_attempt')->where('id', $attemptId)->update([
                    'commerce_event_id' => $inboxId,
                    'update_time' => $now,
                ]);
                $this->projection->consumeEvent($requestEvent);
                $this->audit(
                    $tenant->tenantId(),
                    $attemptId,
                    'requested',
                    '',
                    RefundAttemptState::REQUESTED,
                    $auth->uid(),
                    $now,
                    '',
                    '',
                    ''
                );

                $result = $this->refunds->submitApplication($order, $providerRefundNo, $amount, $request->reason());
                $this->applyGatewayResult(
                    $tenant,
                    $auth,
                    $context,
                    $registration,
                    $attemptId,
                    $refundNo,
                    $providerRefundNo,
                    $paidAmount,
                    $amount,
                    $now,
                    $result
                );

                return $this->events->registrationDetail($tenant, $auth, (int) $registration['id']);
            },
            function () use ($tenant, $auth): void {
                $this->member($tenant, $auth, false);
            }
        );
    }

    /**
     * 查询待确认退款：扫描 processing/unknown 且 next_query_time 到期的退款尝试，
     * 调渠道 query() 收敛（成功 → 最终确认 + 事件投影推进下游；仍挂起 → 更新下次查询时间）。
     *
     * 第三方已接受但本地超时的退款（提交时 PROCESSING/UNKNOWN）必须靠本任务最终收敛，
     * 否则退款永远停在中间态。由 EventReservationRepairJob 定期调用。
     */
    public function queryPending(int $limit = 50): array
    {
        $now = call_user_func($this->clock);
        $rows = Db::table('ch_refund_attempt')
            ->whereIn('status', [RefundAttemptState::PROCESSING, RefundAttemptState::UNKNOWN])
            ->where('next_query_time', '>', 0)
            ->where('next_query_time', '<=', $now)
            ->where(function ($q) use ($now) {
                $q->where('lease_token', '')
                    ->whereOr('lease_expire_time', '<', $now);
            })
            ->order('id', 'asc')
            ->limit($limit)
            ->select()
            ->toArray();

        $succeeded = 0;
        $stillPending = 0;
        $failed = 0;
        foreach ($rows as $attempt) {
            $attemptId = (int) $attempt['id'];
            try {
                // 原子认领（lease）：并发 job 只有一个能查同一笔，防重复查询/重复收敛
                $leaseToken = bin2hex(random_bytes(16));
                $claimed = Db::table('ch_refund_attempt')
                    ->where('id', $attemptId)
                    ->whereIn('status', [RefundAttemptState::PROCESSING, RefundAttemptState::UNKNOWN])
                    ->where(function ($q) use ($now) {
                        $q->where('lease_token', '')
                            ->whereOr('lease_expire_time', '<', $now);
                    })
                    ->update([
                        'lease_token' => $leaseToken,
                        'lease_expire_time' => $now + 300,
                        'query_retry_count' => Db::raw('query_retry_count + 1'),
                        'last_query_time' => $now,
                        'update_time' => $now,
                    ]);
                if ($claimed !== 1) {
                    continue;
                }

                $context = Db::table('ch_order_context')
                    ->where('id', (int) $attempt['order_context_id'])
                    ->find();
                $registration = Db::table('ch_event_registration')
                    ->where('id', (int) $attempt['source_id'])
                    ->find();
                if (!is_array($context) || !is_array($registration)) {
                    throw $this->inconsistent();
                }

                $result = $this->refunds->query($attempt);
                $this->applyGatewayResult(
                    $this->tenantFromAttempt($attempt),
                    new AuthenticatedUserContext((int) $attempt['requester_uid'], true, 'api'),
                    $context,
                    $registration,
                    $attemptId,
                    (string) $attempt['refund_no'],
                    (string) $attempt['provider_refund_no'],
                    (string) $attempt['paid_amount'],
                    (string) $attempt['amount'],
                    $now,
                    $result
                );

                if ($result->status() === RefundAttemptState::SUCCEEDED) {
                    $succeeded++;
                } else {
                    $stillPending++;
                }
            } catch (\Throwable $e) {
                $failed++;
                // 查询/收敛异常：释放 lease 让下次再查（不推进状态，防卡死）
                Db::table('ch_refund_attempt')->where('id', $attemptId)->update([
                    'lease_token' => '',
                    'update_time' => $now,
                ]);
            }
        }

        return ['scanned' => count($rows), 'succeeded' => $succeeded, 'still_pending' => $stillPending, 'failed' => $failed];
    }

    /** 从退款尝试行重建租户上下文（后台 job 无请求上下文）。 */
    private function tenantFromAttempt(array $attempt): TenantContext
    {
        $tenantRow = Db::table('ch_tenant')->where('id', (int) $attempt['tenant_id'])->find();
        $channelRow = Db::table('ch_channel')->where('id', (int) $attempt['channel_id'])->find();
        if (!is_array($tenantRow) || !is_array($channelRow)) {
            throw $this->inconsistent();
        }

        return new TenantContext(new TenantRecord(
            (int) $tenantRow['id'],
            (string) $tenantRow['slug'],
            (int) $channelRow['id'],
            (string) $channelRow['code'],
            true
        ), 'refund_query_job');
    }

    private function applyGatewayResult(
        TenantContext $tenant,
        AuthenticatedUserContext $auth,
        array $context,
        array $registration,
        int $attemptId,
        string $refundNo,
        string $providerRefundNo,
        string $paidAmount,
        string $amount,
        int $now,
        EventRefundGatewayResult $result
    ): void {
        $attempt = Db::table('ch_refund_attempt')
            ->where('id', $attemptId)
            ->lock(true)
            ->find();
        if (!is_array($attempt)) {
            throw $this->inconsistent();
        }
        $status = $result->status();
        $toStatus = $status;
        $nextQueryTime = 0;
        $processingTime = 0;
        $finalConfirmed = 0;
        $finalSource = '';
        $finalTime = 0;
        $providerStatus = $result->providerStatus();
        $failureCode = $result->failureCode();
        $tenantId = (int) $attempt['tenant_id'];
        $version = (int) $attempt['version'] + 1;

        if ($status === RefundAttemptState::PROCESSING) {
            $nextQueryTime = $now + self::QUERY_RETRY_SECONDS;
            $processingTime = $now;
            $processingEvent = $this->refundEvent(
                CommerceEventType::REFUND_PROCESSING,
                $tenant,
                $auth,
                $context,
                $registration,
                $refundNo,
                $providerRefundNo,
                $attemptId,
                $paidAmount,
                $now,
                'processing',
                $providerStatus,
                '0.00',
                '0.00',
                '',
                'provider_accepted'
            );
            $receipt = $this->commerceEvents->record($processingEvent);
            $inboxId = (int) Db::table('ch_commerce_event_inbox')
                ->where('event_id', $receipt->eventId())
                ->value('id');
            if ($inboxId <= 0) {
                throw $this->inconsistent();
            }
            Db::table('ch_refund_attempt')->where('id', $attemptId)->update([
                'commerce_event_id' => $inboxId,
                'status' => RefundAttemptState::PROCESSING,
                'provider_status' => $providerStatus,
                'provider_refund_id' => $result->providerRefundId(),
                'crmeb_refund_id' => $result->crmebRefundId(),
                'last_response_hash' => $result->responseHash(),
                'query_retry_count' => 0,
                'next_query_time' => $nextQueryTime,
                'last_query_time' => 0,
                'final_confirmed' => 0,
                'final_confirm_source' => '',
                'final_confirm_time' => 0,
                'failure_code' => $failureCode,
                'processing_time' => $processingTime,
                'update_time' => $now,
                'version' => $version,
            ]);
            $this->projection->consumeEvent($processingEvent);
            $this->audit(
                $tenantId,
                $attemptId,
                'provider_accepted',
                RefundAttemptState::REQUESTED,
                $toStatus,
                $auth->uid(),
                $now,
                $providerStatus,
                $result->responseHash(),
                hash('sha256', implode("\n", [$refundNo, $providerRefundNo, $amount]))
            );
            return;
        }

        if ($status === RefundAttemptState::UNKNOWN) {
            $nextQueryTime = $now + self::QUERY_RETRY_SECONDS;
            Db::table('ch_refund_attempt')->where('id', $attemptId)->update([
                'status' => RefundAttemptState::UNKNOWN,
                'provider_status' => $providerStatus,
                'provider_refund_id' => $result->providerRefundId(),
                'crmeb_refund_id' => $result->crmebRefundId(),
                'last_response_hash' => $result->responseHash(),
                'query_retry_count' => 0,
                'next_query_time' => $nextQueryTime,
                'last_query_time' => 0,
                'final_confirmed' => 0,
                'final_confirm_source' => '',
                'final_confirm_time' => 0,
                'failure_code' => $failureCode,
                'update_time' => $now,
                'version' => $version,
            ]);
            $this->audit(
                $tenantId,
                $attemptId,
                'provider_unknown',
                RefundAttemptState::REQUESTED,
                $toStatus,
                $auth->uid(),
                $now,
                $providerStatus,
                $result->responseHash(),
                hash('sha256', implode("\n", [$refundNo, $providerRefundNo, $amount])),
                $failureCode
            );
            return;
        }

        if ($status === RefundAttemptState::FAILED) {
            Db::table('ch_refund_attempt')->where('id', $attemptId)->update([
                'status' => RefundAttemptState::FAILED,
                'provider_status' => $providerStatus,
                'provider_refund_id' => $result->providerRefundId(),
                'crmeb_refund_id' => $result->crmebRefundId(),
                'last_response_hash' => $result->responseHash(),
                'query_retry_count' => 0,
                'next_query_time' => 0,
                'last_query_time' => 0,
                'final_confirmed' => 0,
                'final_confirm_source' => '',
                'final_confirm_time' => 0,
                'failure_code' => $failureCode,
                'update_time' => $now,
                'version' => $version,
            ]);
            $this->audit(
                $tenantId,
                $attemptId,
                'provider_failed',
                RefundAttemptState::REQUESTED,
                $toStatus,
                $auth->uid(),
                $now,
                $providerStatus,
                $result->responseHash(),
                hash('sha256', implode("\n", [$refundNo, $providerRefundNo, $amount])),
                $failureCode
            );
            return;
        }

        if ($status === RefundAttemptState::SUCCEEDED) {
            $finalSource = $result->finalSource();
            $finalConfirmed = 1;
            $finalTime = $now;
            $completionEvent = $this->refundEvent(
                CommerceEventType::REFUND_COMPLETED,
                $tenant,
                $auth,
                $context,
                $registration,
                $refundNo,
                $providerRefundNo,
                $attemptId,
                $paidAmount,
                $now,
                'completed',
                $providerStatus,
                $amount,
                $amount,
                $result->providerRefundId(),
                $finalSource
            );
            $receipt = $this->commerceEvents->record($completionEvent);
            $inboxId = (int) Db::table('ch_commerce_event_inbox')
                ->where('event_id', $receipt->eventId())
                ->value('id');
            if ($inboxId <= 0) {
                throw $this->inconsistent();
            }
            Db::table('ch_refund_attempt')->where('id', $attemptId)->update([
                'commerce_event_id' => $inboxId,
                'status' => RefundAttemptState::SUCCEEDED,
                'provider_status' => $providerStatus,
                'provider_refund_id' => $result->providerRefundId(),
                'crmeb_refund_id' => $result->crmebRefundId(),
                'last_response_hash' => $result->responseHash(),
                'query_retry_count' => 0,
                'next_query_time' => 0,
                'last_query_time' => 0,
                'final_confirmed' => $finalConfirmed,
                'final_confirm_source' => $finalSource,
                'final_confirm_time' => $finalTime,
                'failure_code' => '',
                'processing_time' => $now,
                'update_time' => $now,
                'version' => $version,
            ]);
            $this->projection->consumeEvent($completionEvent);
            $this->audit(
                $tenantId,
                $attemptId,
                'provider_completed',
                RefundAttemptState::REQUESTED,
                $toStatus,
                $auth->uid(),
                $now,
                $providerStatus,
                $result->responseHash(),
                hash('sha256', implode("\n", [$refundNo, $providerRefundNo, $amount]))
            );
            return;
        }

        throw $this->inconsistent();
    }

    private function refundEvent(
        string $eventType,
        TenantContext $tenant,
        AuthenticatedUserContext $auth,
        array $context,
        array $registration,
        string $refundNo,
        string $providerRefundNo,
        int $attemptId,
        string $paidAmount,
        int $now,
        string $status,
        string $providerStatus,
        string $refundDelta = '0.00',
        string $cumulative = '0.00',
        string $providerRefundId = '',
        string $completionSource = ''
    ): CommerceEvent {
        $payload = [
            'source' => 'crmeb',
            'source_event_id' => 'refund:' . $refundNo . ':' . $status,
            'event_type' => $eventType,
            'occurred_at' => $now,
            'tenant_id' => $tenant->tenantId(),
            'channel_id' => $tenant->channelId(),
            'order_pk' => (int) $context['order_pk'],
            'order_no' => (string) $context['order_no'],
            'uid' => $auth->uid(),
            'business_type' => self::SOURCE_TYPE,
            'context_id' => (int) $context['id'],
            'currency' => (string) $context['currency'],
            'paid_amount' => $paidAmount,
            'correlation_id' => 'chamber:event:refund:' . (int) $registration['id'] . ':' . $refundNo,
            'refund_pk' => $attemptId,
            'refund_no' => $refundNo,
            'provider_refund_no' => $providerRefundNo,
            'refund_delta' => $refundDelta,
            'cumulative_refunded_amount' => $cumulative,
            'provider_status' => $providerStatus,
        ];
        if ($completionSource !== '') {
            $payload['completion_source'] = $completionSource;
        }
        if ($providerRefundId !== '') {
            $payload['completion_id'] = $providerRefundId;
        }

        return CommerceEvent::fromArray($payload);
    }

    private function lockedRegistration(TenantContext $tenant, array $member, int $registrationId, bool $lock): array
    {
        $query = Db::table('ch_event_registration')->alias('registration')
            ->join(
                ['ch_event' => 'event'],
                'event.id = registration.event_id AND event.tenant_id = registration.tenant_id'
            )
            ->where('registration.tenant_id', $tenant->tenantId())
            ->where('registration.id', $registrationId)
            ->where('registration.member_id', (int) $member['id'])
            ->where('registration.uid', (int) $member['uid'])
            ->where('event.channel_id', $tenant->channelId())
            ->where('event.is_del', 0)
            ->field('registration.*');
        if ($lock) {
            $query->lock(true);
        }
        $row = $query->find();
        if (!is_array($row)) {
            throw new MemberTransactionException(404, 'registration_not_found', 'Event registration was not found');
        }

        return $row;
    }

    private function lockedOrderContext(TenantContext $tenant, array $registration): array
    {
        $contextId = (int) ($registration['order_context_id'] ?? 0);
        if ($contextId <= 0) {
            throw $this->conflict('refund_not_available', 'This registration is not refundable');
        }
        $context = Db::table('ch_order_context')
            ->where('tenant_id', $tenant->tenantId())
            ->where('id', $contextId)
            ->where('business_type', self::SOURCE_TYPE)
            ->lock(true)
            ->find();
        if (!is_array($context)
            || (int) $context['business_id'] !== (int) $registration['id']
            || (int) $context['uid'] !== (int) $registration['uid']
            || (int) $context['channel_id'] !== $tenant->channelId()) {
            throw $this->inconsistent();
        }

        return $context;
    }

    private function member(TenantContext $tenant, AuthenticatedUserContext $auth, bool $lock): array
    {
        $query = Db::table('ch_tenant_member')
            ->where('tenant_id', $tenant->tenantId())
            ->where('uid', $auth->uid());
        if ($lock) {
            $query->lock(true);
        }
        $row = $query->find();
        if (!is_array($row)) {
            throw new MemberTransactionException(404, 'member_not_found', 'Member account was not found');
        }
        if ((int) $row['status'] !== 1 || (int) $row['is_del'] !== 0) {
            throw new MemberTransactionException(403, 'member_disabled', 'Member account is not active');
        }
        if ((int) $row['current_channel_id'] !== $tenant->channelId()) {
            throw new MemberTransactionException(
                403,
                'tenant_scope_denied',
                'Member is not active in the requested channel'
            );
        }

        return $row;
    }

    private function refundPolicy($value): array
    {
        $decoded = [];
        if (is_string($value) && trim($value) !== '') {
            $candidate = json_decode($value, true);
            if (is_array($candidate)) {
                $decoded = $candidate;
            }
        } elseif (is_array($value)) {
            $decoded = $value;
        }

        return [
            'mode' => in_array($decoded['mode'] ?? null, ['none', 'full_before_deadline', 'partial_before_deadline'], true)
                ? $decoded['mode'] : 'none',
            'deadline_time' => max(0, (int) ($decoded['deadline_time'] ?? 0)),
            'percent' => max(0, min(100, (int) ($decoded['percent'] ?? 100))),
            'description' => (string) ($decoded['description'] ?? ''),
        ];
    }

    private function providerTradeNo(array $order): string
    {
        $tradeNo = trim((string) ($order['trade_no'] ?? ''));
        if ($tradeNo !== '') {
            return $tradeNo;
        }

        return (string) ($order['order_id'] ?? '');
    }

    private function audit(
        int $tenantId,
        int $attemptId,
        string $action,
        string $fromStatus,
        string $toStatus,
        int $actorId,
        int $now,
        string $providerStatus,
        string $responseHash,
        string $referenceHash,
        string $failureCode = ''
    ): void {
        Db::table('ch_refund_attempt_audit')->insert([
            'tenant_id' => $tenantId,
            'refund_attempt_id' => $attemptId,
            'action' => $action,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'actor_type' => 'member',
            'actor_id' => $actorId,
            'provider_status' => $providerStatus,
            'response_hash' => $responseHash,
            'reference_hash' => $referenceHash,
            'failure_code' => $failureCode,
            'occurred_time' => $now,
            'add_time' => $now,
        ]);
    }

    private function conflict(string $reason, string $message): MemberTransactionException
    {
        return new MemberTransactionException(409, $reason, $message);
    }

    private function validation(string $field, string $code, string $message): MemberTransactionException
    {
        return new MemberTransactionException(
            422,
            'request_validation_failed',
            $message,
            [['field' => $field, 'code' => $code]]
        );
    }

    private function inconsistent(): MemberTransactionException
    {
        return new MemberTransactionException(
            503,
            'event_order_inconsistent',
            'Event refund data is inconsistent'
        );
    }
}
