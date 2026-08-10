<?php

declare(strict_types=1);

namespace app\chamber\services;

use app\chamber\activity\EventEligibility;
use app\chamber\activity\EventRegistrationRequest;
use app\chamber\activity\EventTicketOrderSnapshot;
use app\chamber\commerce\Money;
use app\chamber\contracts\EventOrderGatewayInterface;
use app\chamber\exceptions\MemberTransactionException;
use app\chamber\identity\AuthenticatedUserContext;
use app\chamber\membership\BootstrapIdempotency;
use app\chamber\tenancy\TenantContext;
use RuntimeException;
use think\facade\Db;

/** Creates immediate free/points registrations under one database transaction. */
final class EventRegistrationService
{
    private const RESERVATION_SECONDS = 900;

    /** @var EventService */
    private $events;

    /** @var EventIdempotency */
    private $idempotency;

    /** @var callable */
    private $registrationNoFactory;

    /** @var EventOrderGatewayInterface|null */
    private $orders;

    public function __construct(
        EventService $events = null,
        EventIdempotency $idempotency = null,
        callable $registrationNoFactory = null,
        EventOrderGatewayInterface $orders = null
    ) {
        $this->events = $events ?: new EventService();
        $this->idempotency = $idempotency ?: new EventIdempotency();
        $this->registrationNoFactory = $registrationNoFactory ?: function (
            int $tenantId,
            int $eventId,
            int $uid,
            int $now
        ): string {
            return strtoupper(substr(hash('sha256', implode(':', [
                'event_registration',
                $tenantId,
                $eventId,
                $uid,
                $now,
                bin2hex(random_bytes(16)),
            ])), 0, 32));
        };
        $this->orders = $orders;
    }

    public function register(
        TenantContext $tenant,
        AuthenticatedUserContext $auth,
        int $eventId,
        EventRegistrationRequest $request,
        string $callerKey,
        array $authenticatedUser = []
    ): array {
        if ($eventId <= 0) {
            throw $this->validation('event_id', 'invalid_value', 'event_id must be a positive integer');
        }

        $internalKey = BootstrapIdempotency::deriveInternalKey(
            $tenant->tenantId(),
            'createEventRegistration',
            'crmeb_user',
            $auth->uid(),
            $callerKey
        );
        $checkoutKey = substr(hash('sha256', 'event_registration_order:' . $internalKey), 0, 32);
        $result = $this->idempotency->execute(
            $tenant,
            'createEventRegistration',
            'crmeb_user',
            $auth->uid(),
            $callerKey,
            array_merge(['event_id' => $eventId], $request->toIdempotencyArray()),
            201,
            function (int $now) use ($tenant, $auth, $eventId, $request, $internalKey, $checkoutKey, $authenticatedUser): array {
                $member = $this->member($tenant, $auth, true);
                $event = $this->event($tenant, $eventId);
                $existing = Db::table('ch_event_registration')
                    ->where('tenant_id', $tenant->tenantId())
                    ->where('event_id', $eventId)
                    ->where('uid', $auth->uid())
                    ->lock(true)
                    ->find();
                if (is_array($existing)) {
                    throw new MemberTransactionException(
                        409,
                        'registration_already_exists',
                        'Member already has a registration for this event'
                    );
                }
                $ticket = $this->ticket($tenant->tenantId(), $eventId, $request->ticketId());
                $amount = Money::assertAmount((string) $ticket['price'], 'ticket.price');
                $integral = (int) $ticket['integral_price'];
                $this->assertExpectedPrice($request, $amount, $integral);

                $account = $this->pointAccount($tenant->tenantId(), $member, $integral > 0);
                $points = is_array($account) ? (int) $account['balance'] : 0;
                $roles = $this->roleCodes($tenant->tenantId(), (int) $member['id'], $now);
                $capacity = (int) $ticket['capacity'];
                $hasCapacity = $capacity === 0
                    || ((int) $ticket['reserved_count'] + (int) $ticket['paid_count']) < $capacity;
                $reason = EventEligibility::reason(
                    $event,
                    $ticket,
                    $member,
                    $roles,
                    $points,
                    $now,
                    $hasCapacity
                );
                if ($reason !== null) {
                    throw new MemberTransactionException(409, $reason, 'Member is not eligible for this event ticket');
                }
                $cashPayment = Money::toMinor($amount) > 0;
                if ($cashPayment) {
                    $this->assertAuthenticatedUser($authenticatedUser, $auth->uid());
                }

                if ($integral > $points) {
                    throw new MemberTransactionException(409, 'points_required', 'Member points are insufficient');
                }

                $registrationNo = call_user_func(
                    $this->registrationNoFactory,
                    $tenant->tenantId(),
                    $eventId,
                    $auth->uid(),
                    $now
                );
                if (!is_string($registrationNo)
                    || preg_match('/^[A-Z0-9]{16,32}$/D', $registrationNo) !== 1) {
                    throw new RuntimeException('Event registration number factory returned an invalid value');
                }

                $registrationId = (int) Db::table('ch_event_registration')->insertGetId([
                    'tenant_id' => $tenant->tenantId(),
                    'event_id' => $eventId,
                    'ticket_id' => (int) $ticket['id'],
                    'member_id' => (int) $member['id'],
                    'uid' => $auth->uid(),
                    'registration_no' => $registrationNo,
                    'order_pk' => 0,
                    'order_no' => '',
                    'order_context_id' => 0,
                    'amount' => $amount,
                    'integral_amount' => $integral,
                    'status' => $cashPayment ? 0 : 1,
                    'reserve_expire_time' => $cashPayment ? $now + self::RESERVATION_SECONDS : 0,
                    'paid_time' => $cashPayment ? 0 : $now,
                    'cancel_time' => 0,
                    'refund_time' => 0,
                    'add_time' => $now,
                    'update_time' => $now,
                ]);
                if ($registrationId <= 0) {
                    throw new MemberTransactionException(409, 'event_registration_failed', 'Event registration could not be created');
                }

                $counterField = $cashPayment ? 'reserved_count' : 'paid_count';
                $updated = Db::table('ch_event_ticket')
                    ->where('tenant_id', $tenant->tenantId())
                    ->where('id', (int) $ticket['id'])
                    ->where($counterField, (int) $ticket[$counterField])
                    ->update([
                        $counterField => (int) $ticket[$counterField] + 1,
                        'update_time' => $now,
                    ]);
                if ($updated !== 1) {
                    throw new MemberTransactionException(409, 'event_full', 'Event ticket capacity changed');
                }

                if ($cashPayment) {
                    $record = Db::table('ch_idempotency_record')
                        ->where('tenant_id', $tenant->tenantId())
                        ->where('idempotency_key', $internalKey)
                        ->lock(true)
                        ->find();
                    if (!is_array($record)) {
                        throw new MemberTransactionException(503, 'event_order_inconsistent', 'Event idempotency context is unavailable');
                    }
                    $snapshot = EventTicketOrderSnapshot::fromRows($event, $ticket);
                    $contextId = (int) Db::table('ch_order_context')->insertGetId([
                        'tenant_id' => $tenant->tenantId(),
                        'channel_id' => $tenant->channelId(),
                        'member_id' => (int) $member['id'],
                        'uid' => $auth->uid(),
                        'context_no' => $checkoutKey,
                        'idempotency_record_id' => (int) $record['id'],
                        'order_pk' => null,
                        'order_no' => null,
                        'business_type' => 'event_registration',
                        'business_id' => $registrationId,
                        'currency' => $snapshot->currency(),
                        'list_amount' => $snapshot->price(),
                        'payable_amount' => $snapshot->price(),
                        'paid_amount' => '0.00',
                        'refunded_amount' => '0.00',
                        'integral_amount' => number_format($integral, 2, '.', ''),
                        'price_snapshot_json' => BootstrapIdempotency::canonicalJson($snapshot->priceSnapshot()),
                        'entitlement_snapshot_json' => '{}',
                        'refund_policy_snapshot_json' => BootstrapIdempotency::canonicalJson($snapshot->refundPolicySnapshot()),
                        'settlement_snapshot_json' => BootstrapIdempotency::canonicalJson($snapshot->settlementSnapshot()),
                        'pay_status' => 0,
                        'completion_kind' => 'pending',
                        'refund_status' => 0,
                        'paid_time' => 0,
                        'version' => 1,
                        'add_time' => $now,
                        'update_time' => $now,
                    ]);
                    if ($contextId <= 0) {
                        throw new MemberTransactionException(503, 'event_order_inconsistent', 'Event order context could not be created');
                    }
                    Db::table('ch_event_registration')->where('id', $registrationId)->update([
                        'order_context_id' => $contextId,
                        'update_time' => $now,
                    ]);
                    if ($integral > 0) {
                        $this->holdPoints($tenant->tenantId(), $member, $account, $registrationId, $integral, $now);
                    }
                } elseif ($integral > 0) {
                    $this->debitPoints($tenant->tenantId(), $member, $account, $registrationId, $integral, $now);
                }

                return $this->events->registrationDetail($tenant, $auth, $registrationId);
            },
            function () use ($tenant, $auth): void {
                $this->member($tenant, $auth, false);
            }
        );

        if (($result['payment_required'] ?? false) === true) {
            return $this->createAndBindCashOrder(
                $tenant,
                $auth,
                (int) $result['id'],
                $checkoutKey,
                $authenticatedUser
            );
        }

        return $result;
    }

    private function createAndBindCashOrder(
        TenantContext $tenant,
        AuthenticatedUserContext $auth,
        int $registrationId,
        string $checkoutKey,
        array $authenticatedUser
    ): array {
        $this->assertAuthenticatedUser($authenticatedUser, $auth->uid());
        $registration = Db::table('ch_event_registration')
            ->where('tenant_id', $tenant->tenantId())
            ->where('id', $registrationId)
            ->where('uid', $auth->uid())
            ->find();
        if (!is_array($registration) || (int) $registration['status'] !== 0) {
            return $this->events->registrationDetail($tenant, $auth, $registrationId);
        }
        $context = Db::table('ch_order_context')
            ->where('tenant_id', $tenant->tenantId())
            ->where('id', (int) $registration['order_context_id'])
            ->where('business_type', 'event_registration')
            ->find();
        if (!is_array($context)
            || (int) $context['business_id'] !== $registrationId
            || !hash_equals((string) $context['context_no'], $checkoutKey)) {
            throw new MemberTransactionException(503, 'event_order_inconsistent', 'Reserved event order context is unavailable');
        }
        $snapshot = EventTicketOrderSnapshot::fromContext($context);
        if ($snapshot->productId() < 1) {
            // 现金票必须关联商城商品才能创建支付订单（防 500：给出明确配置提示）
            throw new MemberTransactionException(
                409,
                'ticket_product_not_configured',
                'Ticket requires payment but no product is linked, please contact the operator'
            );
        }
        $orders = $this->orderGateway();
        $persistedOrder = $orders->findByCheckoutKey($auth->uid(), $checkoutKey);
        if ($persistedOrder === null) {
            $orders->assertTicketProduct($snapshot);
            $persistedOrder = $orders->create($authenticatedUser, $snapshot, $checkoutKey);
        }
        $order = $orders->assertOrderMatches($persistedOrder, $snapshot, $auth->uid(), $checkoutKey);

        $bound = (bool) Db::transaction(function () use ($tenant, $auth, $registrationId, $checkoutKey, $order): bool {
            $registration = Db::table('ch_event_registration')
                ->where('tenant_id', $tenant->tenantId())
                ->where('id', $registrationId)
                ->where('uid', $auth->uid())
                ->lock(true)
                ->find();
            if (!is_array($registration)) {
                throw new MemberTransactionException(503, 'event_order_inconsistent', 'Event registration disappeared during order binding');
            }
            $context = Db::table('ch_order_context')
                ->where('tenant_id', $tenant->tenantId())
                ->where('id', (int) $registration['order_context_id'])
                ->where('business_type', 'event_registration')
                ->lock(true)
                ->find();
            if (!is_array($context) || !hash_equals((string) $context['context_no'], $checkoutKey)) {
                throw new MemberTransactionException(503, 'event_order_inconsistent', 'Event order context is inconsistent');
            }
            if ((int) $registration['status'] !== 0 || (int) $context['pay_status'] !== 0) {
                return false;
            }
            if ($context['order_pk'] !== null || $context['order_no'] !== null) {
                if ((int) $context['order_pk'] !== (int) $order['order_pk']
                    || (string) $context['order_no'] !== (string) $order['order_no']) {
                    throw new MemberTransactionException(503, 'event_order_inconsistent', 'Event order binding is inconsistent');
                }
                return true;
            }
            if ((string) $context['payable_amount'] !== (string) $order['payable_amount']
                || (string) $context['currency'] !== (string) $order['currency']) {
                throw new MemberTransactionException(503, 'event_order_inconsistent', 'Event order amount is inconsistent');
            }
            $now = time();
            $updated = Db::table('ch_order_context')->where('id', (int) $context['id'])
                ->whereNull('order_pk')->whereNull('order_no')->update([
                    'order_pk' => (int) $order['order_pk'],
                    'order_no' => (string) $order['order_no'],
                    'version' => (int) $context['version'] + 1,
                    'update_time' => $now,
                ]);
            if ($updated !== 1) {
                throw new MemberTransactionException(503, 'event_order_inconsistent', 'Event order could not be bound');
            }
            Db::table('ch_event_registration')->where('id', $registrationId)->update([
                'order_pk' => (int) $order['order_pk'],
                'order_no' => (string) $order['order_no'],
                'update_time' => $now,
            ]);
            return true;
        });
        if (!$bound) {
            $orders->cancelUnpaid($persistedOrder);
        }

        return $this->events->registrationDetail($tenant, $auth, $registrationId);
    }

    private function holdPoints(
        int $tenantId,
        array $member,
        array $account,
        int $registrationId,
        int $points,
        int $now
    ): void {
        $balance = (int) $account['balance'];
        if ($balance < $points) {
            throw new MemberTransactionException(409, 'points_required', 'Member points are insufficient');
        }
        $updated = Db::table('ch_point_account')->where('id', (int) $account['id'])
            ->where('tenant_id', $tenantId)->where('version', (int) $account['version'])->update([
                'balance' => $balance - $points,
                'frozen_balance' => (int) ($account['frozen_balance'] ?? 0) + $points,
                'version' => (int) $account['version'] + 1,
                'update_time' => $now,
            ]);
        if ($updated !== 1) {
            throw new MemberTransactionException(409, 'points_required', 'Member points balance changed');
        }
        $id = (int) Db::table('ch_point_hold')->insertGetId([
            'tenant_id' => $tenantId,
            'account_id' => (int) $account['id'],
            'member_id' => (int) $member['id'],
            'uid' => (int) $member['uid'],
            'registration_id' => $registrationId,
            'amount' => $points,
            'status' => 1,
            'idempotency_key' => hash('sha256', 'event_registration_hold:' . $tenantId . ':' . $registrationId),
            'expire_time' => $now + self::RESERVATION_SECONDS,
            'version' => 1,
            'add_time' => $now,
            'update_time' => $now,
        ]);
        if ($id <= 0) {
            throw new MemberTransactionException(503, 'event_order_inconsistent', 'Event points hold could not be recorded');
        }
    }

    private function assertAuthenticatedUser(array $user, int $uid): void
    {
        $actual = $user['uid'] ?? null;
        if (is_string($actual) && preg_match('/^[1-9][0-9]*$/D', $actual) === 1) {
            $actual = (int) $actual;
        }
        if (!is_int($actual) || $actual !== $uid) {
            throw new MemberTransactionException(503, 'event_order_unavailable', 'Authenticated CRMEB user is unavailable');
        }
    }

    private function orderGateway(): EventOrderGatewayInterface
    {
        if ($this->orders === null) {
            $this->orders = app()->make(EventOrderGatewayInterface::class);
        }

        return $this->orders;
    }

    private function member(TenantContext $tenant, AuthenticatedUserContext $auth, bool $lock): array
    {
        $query = Db::table('ch_tenant_member')
            ->where('tenant_id', $tenant->tenantId())
            ->where('uid', $auth->uid());
        if ($lock) {
            $query->lock(true);
        }
        $member = $query->find();
        if (!is_array($member)) {
            throw new MemberTransactionException(404, 'member_not_found', 'Member account was not found');
        }
        if ((int) $member['status'] !== 1 || (int) $member['is_del'] !== 0) {
            throw new MemberTransactionException(403, 'member_disabled', 'Member account is not active');
        }
        if ((int) $member['current_channel_id'] !== $tenant->channelId()) {
            throw new MemberTransactionException(403, 'tenant_scope_denied', 'Member is not active in the requested channel');
        }

        return $member;
    }

    private function event(TenantContext $tenant, int $eventId): array
    {
        $event = Db::table('ch_event')
            ->where('tenant_id', $tenant->tenantId())
            ->where('channel_id', $tenant->channelId())
            ->where('id', $eventId)
            ->where('is_del', 0)
            ->lock(true)
            ->find();
        if (!is_array($event)) {
            throw new MemberTransactionException(404, 'event_not_found', 'Event was not found');
        }

        return $event;
    }

    private function ticket(int $tenantId, int $eventId, int $ticketId): array
    {
        $ticket = Db::table('ch_event_ticket')
            ->where('tenant_id', $tenantId)
            ->where('event_id', $eventId)
            ->where('id', $ticketId)
            ->where('is_del', 0)
            ->lock(true)
            ->find();
        if (!is_array($ticket)) {
            throw new MemberTransactionException(404, 'event_ticket_not_found', 'Event ticket was not found');
        }

        return $ticket;
    }

    private function assertExpectedPrice(
        EventRegistrationRequest $request,
        string $amount,
        int $integral
    ): void {
        if ($request->expectedAmount() !== null && $request->expectedAmount() !== $amount) {
            throw $this->validation('expected_amount', 'snapshot_mismatch', 'Event ticket cash amount changed');
        }
        if ($request->expectedIntegral() !== null && $request->expectedIntegral() !== $integral) {
            throw $this->validation('expected_integral', 'snapshot_mismatch', 'Event ticket points amount changed');
        }
    }

    private function pointAccount(int $tenantId, array $member, bool $required): ?array
    {
        $account = Db::table('ch_point_account')
            ->where('tenant_id', $tenantId)
            ->where('member_id', (int) $member['id'])
            ->lock(true)
            ->find();
        if (!is_array($account)) {
            if ($required) {
                throw new MemberTransactionException(409, 'points_required', 'Member points are insufficient');
            }

            return null;
        }
        if ((int) $account['uid'] !== (int) $member['uid']) {
            throw new MemberTransactionException(409, 'points_required', 'Member points account is inconsistent');
        }

        return $account;
    }

    private function roleCodes(int $tenantId, int $memberId, int $now): array
    {
        $roles = Db::table('ch_member_role')->alias('member_role')
            ->join(
                ['ch_persona_role' => 'persona_role'],
                'persona_role.id = member_role.role_id AND persona_role.tenant_id = member_role.tenant_id'
            )
            ->where('member_role.tenant_id', $tenantId)
            ->where('member_role.member_id', $memberId)
            ->where('member_role.status', 1)
            ->where('member_role.effective_time', '<=', $now)
            ->where(function ($query) use ($now): void {
                $query->where('member_role.expire_time', 0)
                    ->whereOr('member_role.expire_time', '>', $now);
            })
            ->where('persona_role.status', 1)
            ->where('persona_role.is_del', 0)
            ->column('persona_role.code');

        return array_values(array_unique(array_map('strval', $roles)));
    }

    private function debitPoints(
        int $tenantId,
        array $member,
        array $account,
        int $registrationId,
        int $points,
        int $now
    ): void {
        $balance = (int) $account['balance'];
        if ($balance < $points) {
            throw new MemberTransactionException(409, 'points_required', 'Member points are insufficient');
        }
        $balanceAfter = $balance - $points;
        $updated = Db::table('ch_point_account')
            ->where('id', (int) $account['id'])
            ->where('tenant_id', $tenantId)
            ->where('version', (int) $account['version'])
            ->update([
                'balance' => $balanceAfter,
                'version' => (int) $account['version'] + 1,
                'update_time' => $now,
            ]);
        if ($updated !== 1) {
            throw new MemberTransactionException(409, 'points_required', 'Member points balance changed');
        }
        $ledgerId = (int) Db::table('ch_point_ledger')->insertGetId([
            'tenant_id' => $tenantId,
            'account_id' => (int) $account['id'],
            'member_id' => (int) $member['id'],
            'uid' => (int) $member['uid'],
            'delta' => -$points,
            'balance_after' => $balanceAfter,
            'source_type' => 'event_registration',
            'source_id' => (string) $registrationId,
            'idempotency_key' => hash('sha256', implode(':', [
                'event_registration_points',
                $tenantId,
                $registrationId,
            ])),
            'status' => 1,
            'reversal_id' => 0,
            'add_time' => $now,
        ]);
        if ($ledgerId <= 0) {
            throw new MemberTransactionException(409, 'event_registration_failed', 'Event points debit could not be recorded');
        }
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
}
