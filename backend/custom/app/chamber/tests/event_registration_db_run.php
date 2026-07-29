<?php

declare(strict_types=1);

use app\chamber\activity\EventEligibility;
use app\chamber\activity\EventRegistrationRequest;
use app\chamber\exceptions\MemberTransactionException;
use app\chamber\commerce\CommerceEvent;
use app\chamber\commerce\CommerceEventType;
use app\chamber\identity\AuthenticatedUserContext;
use app\chamber\membership\BootstrapIdempotency;
use app\chamber\services\EventIdempotency;
use app\chamber\services\EventRegistrationService;
use app\chamber\services\EventRegistrationCommerceProjection;
use app\chamber\services\EventReservationRepairService;
use app\chamber\services\EventRewardService;
use app\chamber\services\EventService;
use app\chamber\services\ThinkDbCommerceEventStore;
use app\chamber\tenancy\TenantContext;
use app\chamber\tenancy\TenantRecord;
use think\App;
use think\facade\Db;

require dirname(__DIR__, 3) . '/vendor/autoload.php';
require __DIR__ . '/event_order_gateway_fixture.php';

(new App())->initialize();

$now = time();
$runId = strtolower(bin2hex(random_bytes(6)));
$assertions = 0;
$registrationSequence = 0;
$eventOrders = new TestEventOrderGateway(900000, strtoupper($runId));

Db::startTrans();
try {
    $tenantRow = fixtureTenant('local-primary');
    $channel = fixtureChannel((int) $tenantRow['id'], 'default');
    $otherTenant = fixtureTenant('local-secondary');
    $otherChannel = fixtureChannel((int) $otherTenant['id'], 'default');
    $tenant = tenantContext($tenantRow, $channel);
    $uid = (int) Db::table('ch_tenant_member')->max('uid') + random_int(1000, 10000);
    $memberId = createEligibleMember((int) $tenantRow['id'], (int) $channel['id'], $uid, $now);
    createPointAccount((int) $tenantRow['id'], $memberId, $uid, 100, $now);
    grantMentorRole((int) $tenantRow['id'], $memberId, $uid, $now);
    $auth = new AuthenticatedUserContext($uid, true, 'api');

    $events = new EventService(function () use ($now): int {
        return $now;
    });
    $idempotency = new EventIdempotency(function () use ($now): int {
        return $now;
    });
    $service = new EventRegistrationService(
        $events,
        $idempotency,
        function () use (&$registrationSequence): string {
            $registrationSequence++;

            return 'REG' . str_pad((string) $registrationSequence, 29, '0', STR_PAD_LEFT);
        },
        $eventOrders
    );

    $freeEvent = createRegistrationEvent(
        (int) $tenantRow['id'],
        (int) $channel['id'],
        'RF' . strtoupper($runId),
        $now
    );
    $freeTicket = createRegistrationTicket(
        (int) $tenantRow['id'],
        $freeEvent,
        (int) $channel['id'],
        '0.00',
        0,
        1,
        0,
        $now
    );
    $freeRequest = EventRegistrationRequest::fromArray([
        'ticket_id' => $freeTicket,
        'expected_amount' => '0.00',
        'expected_integral' => 0,
    ]);
    $freeKey = 'event-registration-free-' . $runId;
    $free = $service->register($tenant, $auth, $freeEvent, $freeRequest, $freeKey);
    assertSame('registered', $free['status']);
    assertSame('0.00', $free['amount']);
    assertSame(0, $free['integral_amount']);
    assertSame(false, $free['payment_required']);
    assertSame(1, (int) Db::table('ch_event_ticket')->where('id', $freeTicket)->value('paid_count'));
    assertSame(1, (int) Db::table('ch_event_registration')->where('event_id', $freeEvent)->count());

    $freeReplay = $service->register($tenant, $auth, $freeEvent, $freeRequest, $freeKey);
    assertSame(
        BootstrapIdempotency::canonicalJson($free),
        BootstrapIdempotency::canonicalJson($freeReplay)
    );
    assertSame(1, (int) Db::table('ch_event_ticket')->where('id', $freeTicket)->value('paid_count'));
    expectReason('idempotency_conflict', 409, function () use (
        $service,
        $tenant,
        $auth,
        $freeEvent,
        $freeTicket,
        $freeKey
    ): void {
        $service->register(
            $tenant,
            $auth,
            $freeEvent,
            EventRegistrationRequest::fromArray([
                'ticket_id' => $freeTicket,
                'expected_amount' => '0.00',
                'expected_integral' => 1,
            ]),
            $freeKey
        );
    });
    expectReason('registration_already_exists', 409, function () use (
        $service,
        $tenant,
        $auth,
        $freeEvent,
        $freeRequest,
        $runId
    ): void {
        $service->register(
            $tenant,
            $auth,
            $freeEvent,
            $freeRequest,
            'event-registration-free-second-' . $runId
        );
    });

    $freeInternalKey = BootstrapIdempotency::deriveInternalKey(
        $tenant->tenantId(),
        'createEventRegistration',
        'crmeb_user',
        $uid,
        $freeKey
    );
    $freeRecord = Db::table('ch_idempotency_record')
        ->where('tenant_id', $tenant->tenantId())
        ->where('idempotency_key', $freeInternalKey)
        ->find();
    assertTrue(is_array($freeRecord), 'registration idempotency record must exist');
    assertSame('succeeded', (string) $freeRecord['status']);
    assertSame(false, strpos((string) $freeRecord['result_json'], (string) $free['registration_no']) !== false);

    Db::table('ch_tenant_member')->where('id', $memberId)->update(['status' => 0]);
    expectReason('member_disabled', 403, function () use (
        $service,
        $tenant,
        $auth,
        $freeEvent,
        $freeRequest,
        $freeKey
    ): void {
        $service->register($tenant, $auth, $freeEvent, $freeRequest, $freeKey);
    });
    Db::table('ch_tenant_member')->where('id', $memberId)->update(['status' => 1]);

    $pointsEvent = createRegistrationEvent(
        (int) $tenantRow['id'],
        (int) $channel['id'],
        'RP' . strtoupper($runId),
        $now
    );
    $pointsTicket = createRegistrationTicket(
        (int) $tenantRow['id'],
        $pointsEvent,
        (int) $channel['id'],
        '0.00',
        30,
        2,
        0,
        $now
    );
    $points = $service->register(
        $tenant,
        $auth,
        $pointsEvent,
        EventRegistrationRequest::fromArray([
            'ticket_id' => $pointsTicket,
            'expected_amount' => '0.00',
            'expected_integral' => 30,
        ]),
        'event-registration-points-' . $runId
    );
    assertSame('registered', $points['status']);
    assertSame(30, $points['integral_amount']);
    assertSame(70, (int) Db::table('ch_point_account')->where('member_id', $memberId)->value('balance'));
    $pointLedger = Db::table('ch_point_ledger')
        ->where('tenant_id', (int) $tenantRow['id'])
        ->where('source_type', 'event_registration')
        ->where('source_id', (string) $points['id'])
        ->find();
    assertTrue(is_array($pointLedger), 'registration point debit ledger must exist');
    assertSame(-30, (int) $pointLedger['delta']);
    assertSame(70, (int) $pointLedger['balance_after']);
    assertSame(1, (int) Db::table('ch_event_ticket')->where('id', $pointsTicket)->value('paid_count'));

    $mismatchEvent = createRegistrationEvent(
        (int) $tenantRow['id'],
        (int) $channel['id'],
        'RM' . strtoupper($runId),
        $now
    );
    $mismatchTicket = createRegistrationTicket(
        (int) $tenantRow['id'],
        $mismatchEvent,
        (int) $channel['id'],
        '0.00',
        20,
        1,
        0,
        $now
    );
    expectReason('request_validation_failed', 422, function () use (
        $service,
        $tenant,
        $auth,
        $mismatchEvent,
        $mismatchTicket,
        $runId
    ): void {
        $service->register(
            $tenant,
            $auth,
            $mismatchEvent,
            EventRegistrationRequest::fromArray([
                'ticket_id' => $mismatchTicket,
                'expected_integral' => 21,
            ]),
            'event-registration-mismatch-' . $runId
        );
    });
    assertSame(0, (int) Db::table('ch_event_ticket')->where('id', $mismatchTicket)->value('paid_count'));
    assertSame(0, (int) Db::table('ch_event_registration')->where('event_id', $mismatchEvent)->count());

    $insufficientEvent = createRegistrationEvent(
        (int) $tenantRow['id'],
        (int) $channel['id'],
        'RI' . strtoupper($runId),
        $now
    );
    $insufficientTicket = createRegistrationTicket(
        (int) $tenantRow['id'],
        $insufficientEvent,
        (int) $channel['id'],
        '0.00',
        80,
        1,
        0,
        $now
    );
    expectReason('points_required', 409, function () use (
        $service,
        $tenant,
        $auth,
        $insufficientEvent,
        $insufficientTicket,
        $runId
    ): void {
        $service->register(
            $tenant,
            $auth,
            $insufficientEvent,
            EventRegistrationRequest::fromArray(['ticket_id' => $insufficientTicket]),
            'event-registration-insufficient-' . $runId
        );
    });
    assertSame(70, (int) Db::table('ch_point_account')->where('member_id', $memberId)->value('balance'));
    assertSame(0, (int) Db::table('ch_event_registration')->where('event_id', $insufficientEvent)->count());

    $fullEvent = createRegistrationEvent(
        (int) $tenantRow['id'],
        (int) $channel['id'],
        'RX' . strtoupper($runId),
        $now
    );
    $fullTicket = createRegistrationTicket(
        (int) $tenantRow['id'],
        $fullEvent,
        (int) $channel['id'],
        '0.00',
        0,
        1,
        1,
        $now
    );
    expectReason('event_full', 409, function () use (
        $service,
        $tenant,
        $auth,
        $fullEvent,
        $fullTicket,
        $runId
    ): void {
        $service->register(
            $tenant,
            $auth,
            $fullEvent,
            EventRegistrationRequest::fromArray(['ticket_id' => $fullTicket]),
            'event-registration-full-' . $runId
        );
    });
    assertSame(0, (int) Db::table('ch_event_registration')->where('event_id', $fullEvent)->count());

    $cashEvent = createRegistrationEvent(
        (int) $tenantRow['id'],
        (int) $channel['id'],
        'RC' . strtoupper($runId),
        $now
    );
    $cashTicket = createRegistrationTicket(
        (int) $tenantRow['id'],
        $cashEvent,
        (int) $channel['id'],
        '10.00',
        0,
        1,
        0,
        $now
    );
    $cash = $service->register(
        $tenant,
        $auth,
        $cashEvent,
        EventRegistrationRequest::fromArray([
            'ticket_id' => $cashTicket,
            'expected_amount' => '10.00',
        ]),
        'event-registration-cash-' . $runId,
        ['uid' => $uid]
    );
    assertSame('pending_payment', $cash['status']);
    assertSame(true, $cash['payment_required']);
    assertTrue($cash['order_no'] !== '', 'cash event order must be bound');
    assertSame(1, (int) Db::table('ch_event_ticket')->where('id', $cashTicket)->value('reserved_count'));
    assertSame(0, (int) Db::table('ch_event_ticket')->where('id', $cashTicket)->value('paid_count'));
    assertSame(1, (int) Db::table('ch_event_registration')->where('event_id', $cashEvent)->count());
    $cashReplay = $service->register(
        $tenant,
        $auth,
        $cashEvent,
        EventRegistrationRequest::fromArray([
            'ticket_id' => $cashTicket,
            'expected_amount' => '10.00',
        ]),
        'event-registration-cash-' . $runId,
        ['uid' => $uid]
    );
    assertSame($cash['order_no'], $cashReplay['order_no']);
    assertSame(1, $eventOrders->createCount());

    $mixedEvent = createRegistrationEvent(
        (int) $tenantRow['id'],
        (int) $channel['id'],
        'RMX' . strtoupper($runId),
        $now
    );
    $mixedTicket = createRegistrationTicket(
        (int) $tenantRow['id'],
        $mixedEvent,
        (int) $channel['id'],
        '12.00',
        20,
        1,
        0,
        $now
    );
    $mixed = $service->register(
        $tenant,
        $auth,
        $mixedEvent,
        EventRegistrationRequest::fromArray(['ticket_id' => $mixedTicket]),
        'event-registration-mixed-' . $runId,
        ['uid' => $uid]
    );
    assertSame('pending_payment', $mixed['status']);
    assertSame(50, (int) Db::table('ch_point_account')->where('member_id', $memberId)->value('balance'));
    assertSame(20, (int) Db::table('ch_point_account')->where('member_id', $memberId)->value('frozen_balance'));
    assertSame(1, (int) Db::table('ch_point_hold')->where('registration_id', (int) $mixed['id'])->value('status'));
    assertSame(0, (int) Db::table('ch_point_ledger')->where('source_type', 'event_registration')
        ->where('source_id', (string) $mixed['id'])->count());

    $mixedContext = Db::table('ch_order_context')->where('business_type', 'event_registration')
        ->where('business_id', (int) $mixed['id'])->find();
    assertTrue(is_array($mixedContext), 'mixed registration order context must exist');
    Db::table('ch_order_context')->where('id', (int) $mixedContext['id'])->update([
        'pay_status' => 1,
        'completion_kind' => 'paid',
        'paid_amount' => '12.00',
        'paid_time' => $now,
        'version' => (int) $mixedContext['version'] + 1,
        'update_time' => $now,
    ]);
    $paymentEvent = CommerceEvent::fromArray([
        'source' => 'crmeb',
        'source_event_id' => 'order:' . $mixedContext['order_pk'] . ':paid',
        'event_type' => CommerceEventType::ORDER_COMPLETED,
        'occurred_at' => $now,
        'tenant_id' => (int) $tenantRow['id'],
        'channel_id' => (int) $channel['id'],
        'order_pk' => (int) $mixedContext['order_pk'],
        'order_no' => (string) $mixedContext['order_no'],
        'uid' => $uid,
        'business_type' => 'event_registration',
        'context_id' => (int) $mixedContext['id'],
        'currency' => 'CNY',
        'paid_amount' => '12.00',
        'correlation_id' => 'chamber:event:test:' . $mixed['id'],
        'completion_kind' => 'paid',
        'pay_type' => 'weixin',
        'trade_no' => '',
        'paid_at' => $now,
    ]);
    (new ThinkDbCommerceEventStore())->record($paymentEvent);
    $projection = new EventRegistrationCommerceProjection();
    Db::transaction(function () use ($projection, $paymentEvent): void {
        $projection->consumeEvent($paymentEvent);
    });
    Db::transaction(function () use ($projection, $paymentEvent): void {
        $projection->consumeEvent($paymentEvent);
    });
    assertSame(1, (int) Db::table('ch_event_registration')->where('id', (int) $mixed['id'])->value('status'));
    assertSame(0, (int) Db::table('ch_event_ticket')->where('id', $mixedTicket)->value('reserved_count'));
    assertSame(1, (int) Db::table('ch_event_ticket')->where('id', $mixedTicket)->value('paid_count'));
    assertSame(50, (int) Db::table('ch_point_account')->where('member_id', $memberId)->value('balance'));
    assertSame(0, (int) Db::table('ch_point_account')->where('member_id', $memberId)->value('frozen_balance'));
    assertSame(2, (int) Db::table('ch_point_hold')->where('registration_id', (int) $mixed['id'])->value('status'));
    assertSame(1, (int) Db::table('ch_point_ledger')->where('source_type', 'event_registration')
        ->where('source_id', (string) $mixed['id'])->count());

    Db::table('ch_event_registration')->where('id', (int) $mixed['id'])->update([
        'status' => 5,
        'update_time' => $now,
    ]);
    $attendanceReward = (new EventRewardService())->grant(
        (int) $tenantRow['id'],
        $mixedEvent,
        (int) $mixed['id'],
        $uid,
        'attendance',
        8,
        3,
        hash('sha256', 'event-registration-refund-reward:' . $runId),
        $now
    );
    assertSame(false, $attendanceReward['replayed']);
    assertSame(58, (int) Db::table('ch_point_account')->where('member_id', $memberId)->value('balance'));
    assertSame(1, (int) Db::table('ch_event_reward')->where('id', (int) $attendanceReward['reward_id'])
        ->value('status'));

    $mixedContext = Db::table('ch_order_context')->where('id', (int) $mixedContext['id'])->find();
    assertTrue(is_array($mixedContext), 'paid mixed registration context must remain available');

    $refundRequested = registrationRefundEvent(
        CommerceEventType::REFUND_REQUESTED,
        $mixedContext,
        (int) $tenantRow['id'],
        (int) $channel['id'],
        $uid,
        $now,
        [
            'source_event_id' => 'refund:701:requested',
            'refund_pk' => 701,
            'refund_no' => 'EVENTREFUND701',
        ]
    );
    (new ThinkDbCommerceEventStore())->record($refundRequested);
    Db::transaction(function () use ($projection, $refundRequested): void {
        $projection->consumeEvent($refundRequested);
    });
    assertSame(1, (int) Db::table('ch_order_context')->where('id', (int) $mixedContext['id'])
        ->value('refund_status'));

    $refundProcessing = registrationRefundEvent(
        CommerceEventType::REFUND_PROCESSING,
        $mixedContext,
        (int) $tenantRow['id'],
        (int) $channel['id'],
        $uid,
        $now,
        [
            'source_event_id' => 'refund:701:processing',
            'refund_pk' => 701,
            'refund_no' => 'EVENTREFUND701',
            'completion_source' => 'provider_accepted',
            'provider_status' => 'processing',
        ]
    );
    (new ThinkDbCommerceEventStore())->record($refundProcessing);
    Db::transaction(function () use ($projection, $refundProcessing): void {
        $projection->consumeEvent($refundProcessing);
    });
    assertSame(2, (int) Db::table('ch_order_context')->where('id', (int) $mixedContext['id'])
        ->value('refund_status'));

    $partialRefund = registrationRefundEvent(
        CommerceEventType::REFUND_COMPLETED,
        $mixedContext,
        (int) $tenantRow['id'],
        (int) $channel['id'],
        $uid,
        $now,
        [
            'source_event_id' => 'refund:701:completed',
            'refund_pk' => 701,
            'refund_no' => 'EVENTREFUND701',
            'provider_refund_no' => 'PROVIDER701',
            'refund_delta' => '5.00',
            'cumulative_refunded_amount' => '5.00',
            'completion_id' => 'event-refund-completion-701',
            'completion_source' => 'provider_query_success',
            'provider_status' => 'success',
        ]
    );
    (new ThinkDbCommerceEventStore())->record($partialRefund);
    Db::transaction(function () use ($projection, $partialRefund): void {
        $projection->consumeEvent($partialRefund);
    });
    assertSame(3, (int) Db::table('ch_order_context')->where('id', (int) $mixedContext['id'])
        ->value('refund_status'));
    assertSame('5.00', (string) Db::table('ch_order_context')->where('id', (int) $mixedContext['id'])
        ->value('refunded_amount'));
    assertSame(5, (int) Db::table('ch_event_registration')->where('id', (int) $mixed['id'])->value('status'));
    assertSame(1, (int) Db::table('ch_event_ticket')->where('id', $mixedTicket)->value('paid_count'));
    assertSame(58, (int) Db::table('ch_point_account')->where('member_id', $memberId)->value('balance'));
    assertSame(1, (int) Db::table('ch_event_reward')->where('id', (int) $attendanceReward['reward_id'])
        ->value('status'));
    assertSame(1, (int) Db::table('ch_event_registration_effect')
        ->where('registration_id', (int) $mixed['id'])->where('effect_type', 'partial_refund')->count());

    $partialReobserved = registrationRefundEvent(
        CommerceEventType::REFUND_COMPLETED,
        $mixedContext,
        (int) $tenantRow['id'],
        (int) $channel['id'],
        $uid,
        $now,
        [
            'source_event_id' => 'refund:701:completed:reobserved',
            'refund_pk' => 701,
            'refund_no' => 'EVENTREFUND701',
            'provider_refund_no' => 'PROVIDER701',
            'refund_delta' => '5.00',
            'cumulative_refunded_amount' => '5.00',
            'completion_id' => 'event-refund-completion-701',
            'completion_source' => 'provider_query_success',
            'provider_status' => 'success',
        ]
    );
    (new ThinkDbCommerceEventStore())->record($partialReobserved);
    Db::transaction(function () use ($projection, $partialReobserved): void {
        $projection->consumeEvent($partialReobserved);
    });
    assertSame(1, (int) Db::table('ch_event_registration_effect')
        ->where('registration_id', (int) $mixed['id'])->where('effect_type', 'partial_refund')->count());

    $fullRefund = registrationRefundEvent(
        CommerceEventType::REFUND_COMPLETED,
        $mixedContext,
        (int) $tenantRow['id'],
        (int) $channel['id'],
        $uid,
        $now,
        [
            'source_event_id' => 'refund:702:completed',
            'refund_pk' => 702,
            'refund_no' => 'EVENTREFUND702',
            'provider_refund_no' => 'PROVIDER702',
            'refund_delta' => '7.00',
            'cumulative_refunded_amount' => '12.00',
            'completion_id' => 'event-refund-completion-702',
            'completion_source' => 'provider_query_success',
            'provider_status' => 'success',
        ]
    );
    (new ThinkDbCommerceEventStore())->record($fullRefund);
    Db::transaction(function () use ($projection, $fullRefund): void {
        $projection->consumeEvent($fullRefund);
    });
    Db::transaction(function () use ($projection, $fullRefund): void {
        $projection->consumeEvent($fullRefund);
    });
    assertSame(4, (int) Db::table('ch_order_context')->where('id', (int) $mixedContext['id'])
        ->value('refund_status'));
    assertSame('12.00', (string) Db::table('ch_order_context')->where('id', (int) $mixedContext['id'])
        ->value('refunded_amount'));
    assertSame(3, (int) Db::table('ch_event_registration')->where('id', (int) $mixed['id'])->value('status'));
    assertSame($now, (int) Db::table('ch_event_registration')->where('id', (int) $mixed['id'])
        ->value('refund_time'));
    assertSame(0, (int) Db::table('ch_event_ticket')->where('id', $mixedTicket)->value('paid_count'));
    assertSame(70, (int) Db::table('ch_point_account')->where('member_id', $memberId)->value('balance'));
    assertSame(2, (int) Db::table('ch_point_ledger')->where('source_type', 'event_registration')
        ->where('source_id', (string) $mixed['id'])->value('status'));
    assertSame(1, (int) Db::table('ch_point_ledger')->where('source_type', 'event_registration_refund')
        ->where('source_id', (string) $mixed['id'])->count());
    assertSame(20, (int) Db::table('ch_point_ledger')->where('source_type', 'event_registration_refund')
        ->where('source_id', (string) $mixed['id'])->value('delta'));
    assertSame(2, (int) Db::table('ch_event_registration_effect')
        ->where('registration_id', (int) $mixed['id'])->count());
    assertSame(20, (int) Db::table('ch_event_registration_effect')
        ->where('registration_id', (int) $mixed['id'])->where('effect_type', 'full_refund')->value('points_delta'));
    assertSame(-1, (int) Db::table('ch_event_registration_effect')
        ->where('registration_id', (int) $mixed['id'])->where('effect_type', 'full_refund')->value('seat_delta'));
    assertSame(2, (int) Db::table('ch_event_reward')->where('id', (int) $attendanceReward['reward_id'])
        ->value('status'));
    assertSame(2, (int) Db::table('ch_event_reward')->where('registration_id', (int) $mixed['id'])->count());
    assertSame(1, (int) Db::table('ch_event_reward')->where('registration_id', (int) $mixed['id'])
        ->where('reward_type', 'refund_reversal')->count());
    assertSame(-8, (int) Db::table('ch_point_ledger')->where('source_type', 'event_checkin_refund')
        ->where('source_id', (string) $mixed['id'])->value('delta'));
    assertSame(-3, (int) Db::table('ch_contribution_ledger')->where('source_type', 'event_checkin_refund')
        ->where('source_id', (string) $mixed['id'])->value('delta'));

    $expiryEvent = createRegistrationEvent(
        (int) $tenantRow['id'],
        (int) $channel['id'],
        'REX' . strtoupper($runId),
        $now
    );
    $expiryTicket = createRegistrationTicket(
        (int) $tenantRow['id'],
        $expiryEvent,
        (int) $channel['id'],
        '8.00',
        10,
        1,
        0,
        $now
    );
    $expiring = $service->register(
        $tenant,
        $auth,
        $expiryEvent,
        EventRegistrationRequest::fromArray(['ticket_id' => $expiryTicket]),
        'event-registration-expiry-' . $runId,
        ['uid' => $uid]
    );
    Db::table('ch_event_registration')->where('id', (int) $expiring['id'])->update([
        'reserve_expire_time' => time() - 1,
    ]);
    $expirySummary = (new EventReservationRepairService($eventOrders))->releaseExpired(10);
    assertSame(1, $expirySummary['released']);
    assertSame(2, (int) Db::table('ch_event_registration')->where('id', (int) $expiring['id'])->value('status'));
    assertSame(0, (int) Db::table('ch_event_ticket')->where('id', $expiryTicket)->value('reserved_count'));
    assertSame(70, (int) Db::table('ch_point_account')->where('member_id', $memberId)->value('balance'));
    assertSame(0, (int) Db::table('ch_point_account')->where('member_id', $memberId)->value('frozen_balance'));
    assertSame(3, (int) Db::table('ch_point_hold')->where('registration_id', (int) $expiring['id'])->value('status'));
    assertSame(3, (int) Db::table('ch_order_context')->where('business_type', 'event_registration')
        ->where('business_id', (int) $expiring['id'])->value('pay_status'));

    $foreignEvent = createRegistrationEvent(
        (int) $otherTenant['id'],
        (int) $otherChannel['id'],
        'RO' . strtoupper($runId),
        $now
    );
    $foreignTicket = createRegistrationTicket(
        (int) $otherTenant['id'],
        $foreignEvent,
        (int) $otherChannel['id'],
        '0.00',
        0,
        1,
        0,
        $now
    );
    expectReason('event_not_found', 404, function () use (
        $service,
        $tenant,
        $auth,
        $foreignEvent,
        $foreignTicket,
        $runId
    ): void {
        $service->register(
            $tenant,
            $auth,
            $foreignEvent,
            EventRegistrationRequest::fromArray(['ticket_id' => $foreignTicket]),
            'event-registration-foreign-' . $runId
        );
    });

    fwrite(STDOUT, sprintf(
        "Event registration database integration passed (%d assertions).\n",
        $assertions
    ));
} finally {
    Db::rollback();
}

function fixtureTenant(string $slug): array
{
    $row = Db::table('ch_tenant')->where('slug', $slug)->where('status', 1)->where('is_del', 0)->find();
    if (!is_array($row)) {
        throw new RuntimeException('Tenant fixture was not found: ' . $slug);
    }

    return $row;
}

function fixtureChannel(int $tenantId, string $code): array
{
    $row = Db::table('ch_channel')
        ->where('tenant_id', $tenantId)
        ->where('code', $code)
        ->where('status', 1)
        ->where('is_del', 0)
        ->find();
    if (!is_array($row)) {
        throw new RuntimeException('Channel fixture was not found');
    }

    return $row;
}

function tenantContext(array $tenant, array $channel): TenantContext
{
    return new TenantContext(new TenantRecord(
        (int) $tenant['id'],
        (string) $tenant['slug'],
        (int) $channel['id'],
        (string) $channel['code'],
        true
    ), 'event-registration-db-test');
}

function createEligibleMember(int $tenantId, int $channelId, int $uid, int $now): int
{
    return (int) Db::table('ch_tenant_member')->insertGetId([
        'tenant_id' => $tenantId,
        'uid' => $uid,
        'first_channel_id' => $channelId,
        'current_channel_id' => $channelId,
        'referrer_uid' => 0,
        'tier' => 2,
        'verification_status' => 2,
        'primary_role_id' => 0,
        'status' => 1,
        'join_time' => $now,
        'certified_time' => $now,
        'tier_expire_time' => 0,
        'add_time' => $now,
        'update_time' => $now,
        'is_del' => 0,
    ]);
}

function createPointAccount(int $tenantId, int $memberId, int $uid, int $balance, int $now): void
{
    Db::table('ch_point_account')->insert([
        'tenant_id' => $tenantId,
        'member_id' => $memberId,
        'uid' => $uid,
        'balance' => $balance,
        'version' => 1,
        'add_time' => $now,
        'update_time' => $now,
    ]);
}

function grantMentorRole(int $tenantId, int $memberId, int $uid, int $now): void
{
    $roleId = (int) Db::table('ch_persona_role')
        ->where('tenant_id', $tenantId)
        ->where('code', 'mentor')
        ->where('status', 1)
        ->where('is_del', 0)
        ->value('id');
    if ($roleId <= 0) {
        throw new RuntimeException('Mentor role fixture was not found');
    }
    Db::table('ch_member_role')->insert([
        'tenant_id' => $tenantId,
        'member_id' => $memberId,
        'uid' => $uid,
        'role_id' => $roleId,
        'is_primary' => 1,
        'grant_source' => 0,
        'source_application_id' => 0,
        'status' => 1,
        'effective_time' => $now - 10,
        'expire_time' => 0,
        'revoke_time' => 0,
        'add_time' => $now,
        'update_time' => $now,
    ]);
}

function createRegistrationEvent(int $tenantId, int $channelId, string $eventNo, int $now): int
{
    return (int) Db::table('ch_event')->insertGetId([
        'tenant_id' => $tenantId,
        'channel_id' => $channelId,
        'event_no' => $eventNo,
        'event_type' => 'industry',
        'title' => 'Registration database test',
        'cover_image' => '',
        'summary' => 'Registration transaction fixture',
        'tags_json' => '[]',
        'speakers_json' => '[]',
        'detail' => '',
        'start_time' => $now + 7200,
        'end_time' => $now + 10800,
        'signup_start_time' => $now - 3600,
        'signup_end_time' => $now + 3600,
        'location_name' => 'Test venue',
        'address' => '',
        'longitude' => '0.000000',
        'latitude' => '0.000000',
        'min_tier' => 2,
        'eligibility_json' => json_encode([
            'allowed_channel_ids' => [$channelId],
            'min_points' => 50,
            'required_roles' => ['mentor'],
        ]),
        'refund_policy_json' => '{}',
        'checkin_reward_points' => 0,
        'checkin_reward_contribution' => 0,
        'status' => EventEligibility::EVENT_PUBLISHED,
        'created_admin_id' => 1,
        'publish_time' => $now - 100,
        'add_time' => $now,
        'update_time' => $now,
        'is_del' => 0,
    ]);
}

function createRegistrationTicket(
    int $tenantId,
    int $eventId,
    int $channelId,
    string $price,
    int $integral,
    int $capacity,
    int $paidCount,
    int $now
): int {
    return (int) Db::table('ch_event_ticket')->insertGetId([
        'tenant_id' => $tenantId,
        'event_id' => $eventId,
        'name' => 'Registration test ticket',
        'price' => $price,
        'integral_price' => $integral,
        'product_id' => $price === '0.00' ? 0 : 1,
        'product_attr_unique' => $price === '0.00' ? '' : 'event' . $eventId,
        'capacity' => $capacity,
        'reserved_count' => 0,
        'paid_count' => $paidCount,
        'min_tier' => 2,
        'eligibility_json' => json_encode(['allowed_channel_ids' => [$channelId]]),
        'refund_policy_json' => '{}',
        'sale_start_time' => $now - 3600,
        'sale_end_time' => $now + 3600,
        'status' => 1,
        'sort' => 1,
        'add_time' => $now,
        'update_time' => $now,
        'is_del' => 0,
    ]);
}

function registrationRefundEvent(
    string $eventType,
    array $context,
    int $tenantId,
    int $channelId,
    int $uid,
    int $now,
    array $overrides
): CommerceEvent {
    return CommerceEvent::fromArray(array_replace([
        'source' => 'crmeb',
        'source_event_id' => 'refund:event:test',
        'event_type' => $eventType,
        'occurred_at' => $now,
        'tenant_id' => $tenantId,
        'channel_id' => $channelId,
        'order_pk' => (int) $context['order_pk'],
        'order_no' => (string) $context['order_no'],
        'uid' => $uid,
        'business_type' => 'event_registration',
        'context_id' => (int) $context['id'],
        'currency' => 'CNY',
        'paid_amount' => (string) $context['paid_amount'],
        'correlation_id' => 'chamber:event:refund:' . (int) $context['business_id'],
        'refund_pk' => 700,
        'refund_no' => 'EVENTREFUND700',
        'provider_refund_no' => '',
        'refund_status' => CommerceEventType::refundStatus($eventType),
        'refund_delta' => '0.00',
        'cumulative_refunded_amount' => '0.00',
        'completion_id' => '',
        'completion_source' => '',
        'provider_status' => '',
    ], $overrides));
}

function expectReason(string $reason, int $status, callable $callback): void
{
    try {
        $callback();
    } catch (MemberTransactionException $exception) {
        assertSame($reason, $exception->reason());
        assertSame($status, $exception->httpStatus());
        return;
    }

    throw new RuntimeException('Expected member transaction exception: ' . $reason);
}

function assertTrue(bool $actual, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$actual) {
        throw new RuntimeException($message);
    }
}

function assertSame($expected, $actual): void
{
    global $assertions;
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf(
            'Expected %s, got %s',
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
}
