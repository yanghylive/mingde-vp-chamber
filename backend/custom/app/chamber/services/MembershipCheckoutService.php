<?php

declare(strict_types=1);

namespace app\chamber\services;

use app\chamber\contracts\MembershipOrderGatewayInterface;
use app\chamber\exceptions\MemberTransactionException;
use app\chamber\identity\AuthenticatedUserContext;
use app\chamber\membership\BootstrapIdempotency;
use app\chamber\membership\EncryptedIdempotencyResult;
use app\chamber\membership\GraduateVerificationState;
use app\chamber\membership\MemberTier;
use app\chamber\membership\MembershipCheckoutIdempotency;
use app\chamber\membership\MembershipCheckoutRequest;
use app\chamber\membership\MembershipPlanSnapshot;
use app\chamber\membership\MembershipPurchasePolicy;
use app\chamber\tenancy\TenantContext;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use think\facade\Db;

final class MembershipCheckoutService
{
    private const LEASE_SECONDS = 60;
    private const RETENTION_SECONDS = 604800;
    private const PRINCIPAL_TYPE = 'crmeb_user';
    private const SUCCESS_HTTP_STATUS = 201;

    /** @var MembershipOrderGatewayInterface */
    private $orders;

    /** @var callable */
    private $clock;

    /** @var callable */
    private $leaseTokenFactory;

    /** @var callable|null */
    private $afterOrderCommitted;

    public function __construct(
        MembershipOrderGatewayInterface $orders,
        callable $clock = null,
        callable $leaseTokenFactory = null,
        callable $afterOrderCommitted = null
    ) {
        $this->orders = $orders;
        $this->clock = $clock ?: function (): int {
            return time();
        };
        $this->leaseTokenFactory = $leaseTokenFactory ?: function (): string {
            return $this->uuid();
        };
        $this->afterOrderCommitted = $afterOrderCommitted;
    }

    public function listPlans(TenantContext $tenant, AuthenticatedUserContext $auth): array
    {
        $member = $this->memberByUser($tenant->tenantId(), $auth->uid(), false);
        $this->assertMemberCurrentChannel($tenant, $member);
        $this->assertActiveMember($member);
        $now = $this->now();

        $rows = Db::table('ch_membership_plan')
            ->where('tenant_id', $tenant->tenantId())
            ->where('channel_id', $tenant->channelId())
            ->where('status', 1)
            ->where('purchase_enabled', 1)
            ->where('effective_time', '<=', $now)
            ->where(function ($query) use ($now): void {
                $query->where('end_time', 0)->whereOr('end_time', '>', $now);
            })
            ->order('tier', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();

        $approved = (int) $member['verification_status'] === GraduateVerificationState::toDatabase(
            GraduateVerificationState::APPROVED
        );
        $effectiveTier = $this->memberTier($member);
        $plans = [];
        foreach ($rows as $row) {
            $plan = $this->planFromRow($row);
            $reason = MembershipPurchasePolicy::ineligibleReason(
                $plan,
                $approved,
                $effectiveTier,
                $now
            );
            if ($reason === null) {
                try {
                    $this->orders->assertPlanProduct($plan);
                } catch (Throwable $exception) {
                    $reason = MembershipPurchasePolicy::PLAN_UNAVAILABLE;
                }
            }
            $plans[] = $plan->toPublicArray($reason === null, $reason);
        }

        return ['plans' => $plans];
    }

    public function checkout(
        TenantContext $tenant,
        AuthenticatedUserContext $auth,
        MembershipCheckoutRequest $request,
        string $callerKey,
        array $authenticatedUser
    ): array {
        $this->assertAuthenticatedUser($authenticatedUser, $auth->uid());
        try {
            BootstrapIdempotency::assertCallerKey($callerKey);
        } catch (InvalidArgumentException $exception) {
            throw new MemberTransactionException(
                400,
                'request_validation_failed',
                'Idempotency-Key is invalid',
                [['field' => 'Idempotency-Key', 'code' => 'invalid_format']]
            );
        }

        $now = $this->now();
        $leaseToken = $this->leaseToken();
        $internalKey = MembershipCheckoutIdempotency::deriveInternalKey(
            $tenant->tenantId(),
            $auth->uid(),
            $callerKey
        );
        $requestHash = MembershipCheckoutIdempotency::requestHash($tenant->channelId(), $request);
        $checkoutKey = $this->checkoutKey($internalKey);

        $reservation = Db::transaction(function () use (
            $tenant,
            $auth,
            $request,
            $internalKey,
            $requestHash,
            $checkoutKey,
            $leaseToken,
            $now
        ): array {
            $record = $this->lockIdempotencyRecord(
                $tenant->tenantId(),
                $internalKey,
                $requestHash,
                $leaseToken,
                $now
            );
            $member = $this->memberByUser($tenant->tenantId(), $auth->uid(), true);
            $this->assertMemberCurrentChannel($tenant, $member);
            $this->assertActiveMember($member);

            if ((string) $record['status'] === 'succeeded') {
                return [
                    'replay' => true,
                    'record' => $record,
                    'member' => $member,
                    'context' => $this->contextByRecord((int) $record['id'], true),
                    'plan' => null,
                ];
            }

            $context = $this->contextByRecord((int) $record['id'], true);
            if ($context === null) {
                $plan = $this->planForCheckout($tenant, $request, true);
                $this->assertPurchaseAllowed($member, $plan, $request, $now);
                $this->orders->assertPlanProduct($plan);
                $context = $this->reserveContext(
                    $tenant,
                    $member,
                    $plan,
                    (int) $record['id'],
                    $checkoutKey,
                    $now
                );
            } else {
                $this->assertContextOwnership($context, $tenant, $member, $checkoutKey);
                $plan = $this->planFromContext($context);
                $this->assertRequestMatchesContext($request, $context, $plan);
                $this->assertPurchaseAllowed($member, $plan, $request, $now, false);
            }

            return [
                'replay' => false,
                'record' => $record,
                'member' => $member,
                'context' => $context,
                'plan' => $plan,
            ];
        });

        if ($reservation['replay']) {
            return $this->replayResult(
                $tenant,
                $auth,
                $reservation['record'],
                $reservation['context'],
                $internalKey,
                $checkoutKey
            );
        }

        $recordId = (int) $reservation['record']['id'];
        try {
            /** @var MembershipPlanSnapshot $plan */
            $plan = $reservation['plan'];
            $order = $this->orders->findByCheckoutKey($auth->uid(), $checkoutKey);
            if ($order === null) {
                $currentPlan = $this->planForCheckout($tenant, $request, false);
                $this->assertCurrentPlanMatchesReservation($plan, $currentPlan);
                $this->assertPurchaseAllowed(
                    $reservation['member'],
                    $currentPlan,
                    $request,
                    $this->now()
                );
                $this->orders->assertPlanProduct($currentPlan);
                $plan = $currentPlan;
                $order = $this->orders->create($authenticatedUser, $plan, $checkoutKey);
            }
            $order = $this->orders->assertOrderMatches($order, $plan, $auth->uid(), $checkoutKey);
            if ($this->afterOrderCommitted !== null) {
                call_user_func($this->afterOrderCommitted, $order, $reservation['context']);
            }

            return Db::transaction(function () use (
                $tenant,
                $auth,
                $request,
                $plan,
                $order,
                $recordId,
                $internalKey,
                $checkoutKey,
                $leaseToken
            ): array {
                $now = $this->now();
                $record = $this->idempotencyRecordById($recordId, true);
                $this->assertLeaseOwner($record, $leaseToken);
                $member = $this->memberByUser($tenant->tenantId(), $auth->uid(), true);
                $this->assertMemberCurrentChannel($tenant, $member);
                $this->assertActiveMember($member);
                $context = $this->contextByRecord($recordId, true);
                if ($context === null) {
                    throw new RuntimeException('Reserved membership order context is unavailable');
                }
                $this->assertContextOwnership($context, $tenant, $member, $checkoutKey);
                $this->assertRequestMatchesContext($request, $context, $plan);
                $this->bindOrder($context, $order, $now);
                $result = $this->checkoutResult($context, $order, false);
                $this->completeRecord($record, $leaseToken, $internalKey, $auth->uid(), $result, $now);

                return $result;
            });
        } catch (Throwable $exception) {
            $this->markUnknown($recordId, $leaseToken);
            throw $exception;
        }
    }

    /**
     * Rebinds CRMEB orders that committed before the Chamber context transaction.
     * It never creates a new CRMEB order.
     */
    public function reconcilePending(int $limit = 50): array
    {
        if ($limit < 1 || $limit > 500) {
            throw new InvalidArgumentException('Reconciliation limit must be between 1 and 500');
        }
        $now = $this->now();
        $rows = Db::table('ch_order_context')
            ->alias('context')
            ->join(['ch_idempotency_record' => 'record'], 'record.id = context.idempotency_record_id')
            ->where('context.business_type', 'membership')
            ->whereNull('context.order_pk')
            ->whereNull('context.order_no')
            ->where('record.operation', MembershipCheckoutIdempotency::OPERATION)
            ->where(function ($query) use ($now): void {
                $query->whereIn('record.status', ['failed', 'unknown'])
                    ->whereOr(function ($nested) use ($now): void {
                        $nested->where('record.status', 'processing')
                            ->where('record.lease_expire_time', '<', $now);
                    });
            })
            ->field('context.id,context.idempotency_record_id')
            ->order('record.update_time', 'asc')
            ->order('record.attempt_count', 'asc')
            ->order('context.id', 'asc')
            ->limit($limit)
            ->select()
            ->toArray();

        $summary = [
            'scanned' => count($rows),
            'repaired' => 0,
            'order_missing' => 0,
            'failed' => 0,
        ];
        foreach ($rows as $candidate) {
            $recordId = (int) $candidate['idempotency_record_id'];
            $leaseToken = $this->leaseToken();
            try {
                $work = Db::transaction(function () use ($recordId, $leaseToken): ?array {
                    $now = $this->now();
                    $record = $this->idempotencyRecordById($recordId, true);
                    if ((string) $record['operation'] !== MembershipCheckoutIdempotency::OPERATION
                        || (string) $record['status'] === 'succeeded') {
                        return null;
                    }
                    if ((string) $record['status'] === 'processing'
                        && (int) $record['lease_expire_time'] >= $now) {
                        return null;
                    }
                    $updated = Db::table('ch_idempotency_record')
                        ->where('id', $recordId)
                        ->where('status', (string) $record['status'])
                        ->update([
                            'status' => 'processing',
                            'lease_token' => $leaseToken,
                            'lease_expire_time' => $now + self::LEASE_SECONDS,
                            'attempt_count' => (int) $record['attempt_count'] + 1,
                            'update_time' => $now,
                        ]);
                    if ($updated !== 1) {
                        return null;
                    }
                    $context = $this->contextByRecord($recordId, true);
                    if ($context === null || $context['order_pk'] !== null || $context['order_no'] !== null) {
                        return null;
                    }

                    return ['record' => $record, 'context' => $context];
                });
                if ($work === null) {
                    continue;
                }

                $context = $work['context'];
                $plan = $this->planFromContext($context);
                $order = $this->orders->findByCheckoutKey((int) $context['uid'], (string) $context['context_no']);
                if ($order === null) {
                    $summary['order_missing']++;
                    $this->markUnknown($recordId, $leaseToken);
                    continue;
                }
                $order = $this->orders->assertOrderMatches(
                    $order,
                    $plan,
                    (int) $context['uid'],
                    (string) $context['context_no']
                );

                Db::transaction(function () use ($recordId, $leaseToken, $context, $order): void {
                    $now = $this->now();
                    $record = $this->idempotencyRecordById($recordId, true);
                    $this->assertLeaseOwner($record, $leaseToken);
                    $lockedContext = $this->contextByRecord($recordId, true);
                    if ($lockedContext === null) {
                        throw new RuntimeException('Membership order context disappeared during repair');
                    }
                    $this->assertSameContext($context, $lockedContext);
                    $this->bindOrder($lockedContext, $order, $now);
                    $result = $this->checkoutResult($lockedContext, $order, false);
                    $this->completeRecord(
                        $record,
                        $leaseToken,
                        (string) $record['idempotency_key'],
                        (int) $lockedContext['uid'],
                        $result,
                        $now
                    );
                });
                $summary['repaired']++;
            } catch (Throwable $exception) {
                $summary['failed']++;
                $this->markUnknown($recordId, $leaseToken);
            }
        }

        return $summary;
    }

    private function lockIdempotencyRecord(
        int $tenantId,
        string $internalKey,
        string $requestHash,
        string $leaseToken,
        int $now
    ): array {
        Db::execute(
            'INSERT INTO `ch_idempotency_record` '
            . '(`tenant_id`,`idempotency_key`,`operation`,`request_hash`,`status`,`lease_token`,'
            . '`lease_expire_time`,`attempt_count`,`result_http_status`,`result_code`,`result_hash`,'
            . '`completed_time`,`expire_time`,`add_time`,`update_time`) '
            . 'VALUES (?,?,?,?,\'processing\',?,?,1,0,\'\',\'\',0,?,?,?) '
            . 'ON DUPLICATE KEY UPDATE `id`=`id`',
            [
                $tenantId,
                $internalKey,
                MembershipCheckoutIdempotency::OPERATION,
                $requestHash,
                $leaseToken,
                $now + self::LEASE_SECONDS,
                $now + self::RETENTION_SECONDS,
                $now,
                $now,
            ]
        );
        $row = Db::table('ch_idempotency_record')
            ->where('tenant_id', $tenantId)
            ->where('idempotency_key', $internalKey)
            ->lock(true)
            ->find();
        if (!is_array($row)
            || (string) ($row['operation'] ?? '') !== MembershipCheckoutIdempotency::OPERATION) {
            throw new RuntimeException('Membership checkout idempotency record is inconsistent');
        }
        if (!is_string($row['request_hash']) || !hash_equals($row['request_hash'], $requestHash)) {
            throw new MemberTransactionException(
                409,
                'idempotency_conflict',
                'Idempotency-Key was already used with a different request'
            );
        }
        if ((string) $row['status'] === 'succeeded') {
            return $row;
        }
        if (!in_array((string) $row['status'], ['processing', 'failed', 'unknown'], true)) {
            throw new RuntimeException('Membership checkout idempotency status is invalid');
        }
        if ((string) $row['status'] === 'processing'
            && hash_equals((string) $row['lease_token'], $leaseToken)) {
            return $row;
        }
        if ((string) $row['status'] === 'processing' && (int) $row['lease_expire_time'] >= $now) {
            throw new MemberTransactionException(
                409,
                'idempotency_conflict',
                'Request with this Idempotency-Key is already processing'
            );
        }

        $updated = Db::table('ch_idempotency_record')
            ->where('id', (int) $row['id'])
            ->update([
                'status' => 'processing',
                'lease_token' => $leaseToken,
                'lease_expire_time' => $now + self::LEASE_SECONDS,
                'attempt_count' => (int) $row['attempt_count'] + 1,
                'result_http_status' => 0,
                'result_code' => '',
                'result_hash' => '',
                'result_json' => null,
                'completed_time' => 0,
                'expire_time' => $now + self::RETENTION_SECONDS,
                'update_time' => $now,
            ]);
        if ($updated !== 1) {
            throw new RuntimeException('Membership checkout idempotency lease could not be acquired');
        }
        $row['status'] = 'processing';
        $row['lease_token'] = $leaseToken;
        $row['lease_expire_time'] = $now + self::LEASE_SECONDS;

        return $row;
    }

    private function reserveContext(
        TenantContext $tenant,
        array $member,
        MembershipPlanSnapshot $plan,
        int $recordId,
        string $checkoutKey,
        int $now
    ): array {
        $settlement = [
            'adapter' => 'crmeb-store-order-v1',
            'discounts_allowed' => false,
            'integral_allowed' => false,
            'commission_allowed' => false,
            'order_reward_integral_allowed' => false,
            'plan_name' => $plan->name(),
            'product_attr_unique' => $plan->productAttrUnique(),
            'product_id' => $plan->productId(),
            'quantity' => 1,
        ];
        $id = (int) Db::table('ch_order_context')->insertGetId([
            'tenant_id' => $tenant->tenantId(),
            'channel_id' => $tenant->channelId(),
            'member_id' => (int) $member['id'],
            'uid' => (int) $member['uid'],
            'context_no' => $checkoutKey,
            'idempotency_record_id' => $recordId,
            'order_pk' => null,
            'order_no' => null,
            'business_type' => 'membership',
            'business_id' => $plan->id(),
            'currency' => $plan->currency(),
            'list_amount' => $plan->price(),
            'payable_amount' => $plan->price(),
            'paid_amount' => '0.00',
            'refunded_amount' => '0.00',
            'integral_amount' => '0.00',
            'price_snapshot_json' => BootstrapIdempotency::canonicalJson($plan->priceSnapshot()),
            'entitlement_snapshot_json' => BootstrapIdempotency::canonicalJson(
                $plan->entitlementSnapshot()
            ),
            'refund_policy_snapshot_json' => BootstrapIdempotency::canonicalJson(
                $plan->refundPolicySnapshot()
            ),
            'settlement_snapshot_json' => BootstrapIdempotency::canonicalJson($settlement),
            'pay_status' => 0,
            'completion_kind' => 'pending',
            'refund_status' => 0,
            'paid_time' => 0,
            'version' => 1,
            'add_time' => $now,
            'update_time' => $now,
        ]);
        if ($id <= 0) {
            throw new RuntimeException('Membership order context was not reserved');
        }
        $context = $this->contextByRecord($recordId, true);
        if ($context === null || (int) $context['id'] !== $id) {
            throw new RuntimeException('Reserved membership order context is inconsistent');
        }

        return $context;
    }

    private function replayResult(
        TenantContext $tenant,
        AuthenticatedUserContext $auth,
        array $record,
        ?array $context,
        string $internalKey,
        string $checkoutKey
    ): array {
        if ($context === null) {
            throw new MemberTransactionException(
                503,
                'membership_order_inconsistent',
                'Stored membership checkout context is unavailable'
            );
        }
        $member = $this->memberByUser($tenant->tenantId(), $auth->uid(), false);
        $this->assertMemberCurrentChannel($tenant, $member);
        $this->assertActiveMember($member);
        $this->assertContextOwnership($context, $tenant, $member, $checkoutKey);
        $stored = $this->decodeRecord($record, $internalKey, $auth->uid());
        $plan = $this->planFromContext($context);
        $order = $this->orders->findByCheckoutKey($auth->uid(), $checkoutKey);
        if ($order === null) {
            throw new MemberTransactionException(
                503,
                'membership_order_inconsistent',
                'Stored membership checkout order is unavailable'
            );
        }
        $order = $this->orders->assertOrderMatches($order, $plan, $auth->uid(), $checkoutKey);
        $this->assertBoundOrder($context, $order);
        $expected = $this->checkoutResult($context, $order, false);
        foreach (['context_no', 'order_no', 'payable_amount', 'currency'] as $field) {
            if (($stored[$field] ?? null) !== $expected[$field]) {
                throw new MemberTransactionException(
                    503,
                    'membership_order_inconsistent',
                    'Stored membership checkout result is inconsistent'
                );
            }
        }
        $stored['replayed'] = true;

        return $stored;
    }

    private function completeRecord(
        array $record,
        string $leaseToken,
        string $internalKey,
        int $uid,
        array $result,
        int $now
    ): void {
        $envelope = [
            'principal_type' => self::PRINCIPAL_TYPE,
            'principal_id' => $uid,
            'sealed' => EncryptedIdempotencyResult::seal(
                $result,
                $this->associatedData($internalKey, $uid)
            ),
        ];
        $resultJson = BootstrapIdempotency::canonicalJson($envelope);
        $updated = Db::table('ch_idempotency_record')
            ->where('id', (int) $record['id'])
            ->where('status', 'processing')
            ->where('lease_token', $leaseToken)
            ->update([
                'status' => 'succeeded',
                'lease_token' => '',
                'lease_expire_time' => 0,
                'result_http_status' => self::SUCCESS_HTTP_STATUS,
                'result_code' => 'ok',
                'result_hash' => hash('sha256', $resultJson),
                'result_json' => $resultJson,
                'completed_time' => $now,
                'expire_time' => $now + self::RETENTION_SECONDS,
                'update_time' => $now,
            ]);
        if ($updated !== 1) {
            throw new RuntimeException('Membership checkout idempotency result could not be completed');
        }
    }

    private function decodeRecord(array $record, string $internalKey, int $uid): array
    {
        if ((int) $record['result_http_status'] !== self::SUCCESS_HTTP_STATUS
            || (string) $record['result_code'] !== 'ok'
            || !is_string($record['result_json'])
            || !is_string($record['result_hash'])) {
            throw new RuntimeException('Stored membership checkout result is incomplete');
        }
        $decoded = json_decode($record['result_json'], true);
        if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Stored membership checkout result is invalid JSON');
        }
        $computedHash = hash('sha256', BootstrapIdempotency::canonicalJson($decoded));
        if (!hash_equals((string) $record['result_hash'], $computedHash)
            || !hash_equals((string) $record['idempotency_key'], $internalKey)
            || ($decoded['principal_type'] ?? null) !== self::PRINCIPAL_TYPE
            || ($decoded['principal_id'] ?? null) !== $uid
            || !isset($decoded['sealed'])
            || !is_array($decoded['sealed'])) {
            throw new RuntimeException('Stored membership checkout result identity is invalid');
        }

        return EncryptedIdempotencyResult::open(
            $decoded['sealed'],
            $this->associatedData($internalKey, $uid)
        );
    }

    private function associatedData(string $internalKey, int $uid): string
    {
        return BootstrapIdempotency::canonicalJson([
            'http_status' => self::SUCCESS_HTTP_STATUS,
            'internal_key' => $internalKey,
            'operation' => MembershipCheckoutIdempotency::OPERATION,
            'principal_id' => $uid,
            'principal_type' => self::PRINCIPAL_TYPE,
        ]);
    }

    private function bindOrder(array $context, array $order, int $now): void
    {
        if ($context['order_pk'] !== null || $context['order_no'] !== null) {
            $this->assertBoundOrder($context, $order);
            return;
        }
        $updated = Db::table('ch_order_context')
            ->where('id', (int) $context['id'])
            ->whereNull('order_pk')
            ->whereNull('order_no')
            ->update([
                'order_pk' => (int) $order['order_pk'],
                'order_no' => (string) $order['order_no'],
                'version' => (int) $context['version'] + 1,
                'update_time' => $now,
            ]);
        if ($updated !== 1) {
            throw new MemberTransactionException(
                503,
                'membership_order_inconsistent',
                'Membership order could not be bound to its context'
            );
        }
    }

    private function assertBoundOrder(array $context, array $order): void
    {
        if ((int) ($context['order_pk'] ?? 0) !== (int) $order['order_pk']
            || (string) ($context['order_no'] ?? '') !== (string) $order['order_no']) {
            throw new MemberTransactionException(
                503,
                'membership_order_inconsistent',
                'Membership order binding is inconsistent'
            );
        }
    }

    private function checkoutResult(array $context, array $order, bool $replayed): array
    {
        return [
            'context_no' => (string) $context['context_no'],
            'order_no' => (string) $order['order_no'],
            'order_status' => (string) $order['order_status'],
            'payable_amount' => $this->amount($context['payable_amount'], 'payable_amount'),
            'currency' => (string) $context['currency'],
            'payment_required' => (bool) $order['payment_required'],
            'replayed' => $replayed,
        ];
    }

    private function planForCheckout(
        TenantContext $tenant,
        MembershipCheckoutRequest $request,
        bool $lock
    ): MembershipPlanSnapshot {
        $query = Db::table('ch_membership_plan')
            ->where('tenant_id', $tenant->tenantId())
            ->where('channel_id', $tenant->channelId())
            ->where('plan_code', $request->planCode());
        if ($lock) {
            $query->lock(true);
        }
        $row = $query->find();
        if (!is_array($row)) {
            throw new MemberTransactionException(
                409,
                MembershipPurchasePolicy::PLAN_UNAVAILABLE,
                'Selected membership plan is unavailable'
            );
        }

        return $this->planFromRow($row);
    }

    private function planFromRow(array $row): MembershipPlanSnapshot
    {
        try {
            return MembershipPlanSnapshot::fromArray([
                'id' => (int) $row['id'],
                'tenant_id' => (int) $row['tenant_id'],
                'channel_id' => (int) $row['channel_id'],
                'code' => (string) $row['plan_code'],
                'version' => (int) $row['config_version'],
                'name' => (string) $row['name'],
                'tier' => MemberTier::fromDatabaseRank((int) $row['tier']),
                'purchase_enabled' => (int) $row['purchase_enabled'] === 1,
                'price' => $this->amount($row['price'], 'price'),
                'currency' => (string) $row['currency'],
                'term_months' => (int) $row['term_months'],
                'product_id' => (int) $row['product_id'],
                'product_attr_unique' => (string) $row['product_attr_unique'],
                'benefits' => $this->jsonArray($row['benefits_json'], 'benefits_json'),
                'renewal_policy' => $this->jsonObject($row['renewal_policy_json'], 'renewal_policy_json'),
                'upgrade_policy' => $this->jsonObject($row['upgrade_policy_json'], 'upgrade_policy_json'),
                'refund_policy' => $this->jsonObject($row['refund_policy_json'], 'refund_policy_json'),
                'status' => (int) $row['status'],
                'effective_time' => (int) $row['effective_time'],
                'end_time' => (int) $row['end_time'],
            ]);
        } catch (Throwable $exception) {
            throw new MemberTransactionException(
                503,
                MembershipPurchasePolicy::PLAN_UNAVAILABLE,
                'Membership plan configuration is invalid'
            );
        }
    }

    private function planFromContext(array $context): MembershipPlanSnapshot
    {
        try {
            $price = $this->jsonObject($context['price_snapshot_json'], 'price_snapshot_json');
            $entitlement = $this->jsonObject(
                $context['entitlement_snapshot_json'],
                'entitlement_snapshot_json'
            );
            $refund = $this->jsonObject(
                $context['refund_policy_snapshot_json'],
                'refund_policy_snapshot_json'
            );
            $settlement = $this->jsonObject(
                $context['settlement_snapshot_json'],
                'settlement_snapshot_json'
            );

            return MembershipPlanSnapshot::fromArray([
                'id' => (int) $context['business_id'],
                'tenant_id' => (int) $context['tenant_id'],
                'channel_id' => (int) $context['channel_id'],
                'code' => (string) ($price['plan_code'] ?? ''),
                'version' => (int) ($price['plan_version'] ?? 0),
                'name' => (string) ($settlement['plan_name'] ?? ''),
                'tier' => (string) ($entitlement['tier'] ?? ''),
                'purchase_enabled' => true,
                'price' => $this->amount($context['payable_amount'], 'payable_amount'),
                'currency' => (string) $context['currency'],
                'term_months' => (int) ($entitlement['term_months'] ?? 0),
                'product_id' => (int) ($settlement['product_id'] ?? 0),
                'product_attr_unique' => (string) ($settlement['product_attr_unique'] ?? ''),
                'benefits' => $entitlement['benefits'] ?? null,
                'renewal_policy' => [],
                'upgrade_policy' => [],
                'refund_policy' => $refund,
                'status' => 1,
                'effective_time' => 1,
                'end_time' => 0,
            ]);
        } catch (Throwable $exception) {
            throw new MemberTransactionException(
                503,
                'membership_order_inconsistent',
                'Membership order context snapshot is invalid'
            );
        }
    }

    private function assertPurchaseAllowed(
        array $member,
        MembershipPlanSnapshot $plan,
        MembershipCheckoutRequest $request,
        int $now,
        bool $requireCurrentAvailability = true
    ): void {
        try {
            MembershipPurchasePolicy::assertRequestMatchesPlan($request, $plan);
        } catch (InvalidArgumentException $exception) {
            throw new MemberTransactionException(
                409,
                MembershipPurchasePolicy::PLAN_UNAVAILABLE,
                'Selected membership plan changed; refresh plans and retry'
            );
        }
        $approved = (int) $member['verification_status'] === GraduateVerificationState::toDatabase(
            GraduateVerificationState::APPROVED
        );
        if (!$requireCurrentAvailability) {
            if (!$approved || $this->memberTier($member) === MemberTier::L1) {
                throw new MemberTransactionException(
                    403,
                    MembershipPurchasePolicy::VERIFICATION_REQUIRED,
                    'Approved graduate verification is required for membership purchase'
                );
            }
            if ($this->memberTier($member) === MemberTier::L4 && $plan->tier() === MemberTier::L3) {
                throw new MemberTransactionException(
                    409,
                    MembershipPurchasePolicy::DOWNGRADE_NOT_ALLOWED,
                    'Downgrading from L4 to L3 is not allowed'
                );
            }
            return;
        }

        $reason = MembershipPurchasePolicy::ineligibleReason(
            $plan,
            $approved,
            $this->memberTier($member),
            $now
        );
        if ($reason === null) {
            return;
        }
        if ($reason === MembershipPurchasePolicy::VERIFICATION_REQUIRED) {
            throw new MemberTransactionException(
                403,
                $reason,
                'Approved graduate verification is required for membership purchase'
            );
        }
        if ($reason === MembershipPurchasePolicy::DOWNGRADE_NOT_ALLOWED) {
            throw new MemberTransactionException(409, $reason, 'Downgrading from L4 to L3 is not allowed');
        }
        throw new MemberTransactionException(409, $reason, 'Selected membership plan is unavailable');
    }

    private function assertRequestMatchesContext(
        MembershipCheckoutRequest $request,
        array $context,
        MembershipPlanSnapshot $plan
    ): void {
        try {
            MembershipPurchasePolicy::assertRequestMatchesPlan($request, $plan);
        } catch (InvalidArgumentException $exception) {
            throw new MemberTransactionException(
                503,
                'membership_order_inconsistent',
                'Reserved membership checkout snapshot is inconsistent'
            );
        }
        if ($request->expectedAmount() !== $this->amount($context['payable_amount'], 'payable_amount')
            || $request->currency() !== (string) $context['currency']) {
            throw new MemberTransactionException(
                503,
                'membership_order_inconsistent',
                'Reserved membership checkout amount is inconsistent'
            );
        }
    }

    private function assertCurrentPlanMatchesReservation(
        MembershipPlanSnapshot $reserved,
        MembershipPlanSnapshot $current
    ): void {
        $sameIdentity = $reserved->id() === $current->id()
            && $reserved->tenantId() === $current->tenantId()
            && $reserved->channelId() === $current->channelId()
            && $reserved->code() === $current->code()
            && $reserved->version() === $current->version()
            && $reserved->tier() === $current->tier()
            && $reserved->termMonths() === $current->termMonths()
            && $reserved->productId() === $current->productId()
            && $reserved->productAttrUnique() === $current->productAttrUnique();
        $sameSnapshots = BootstrapIdempotency::canonicalJson($reserved->priceSnapshot())
                === BootstrapIdempotency::canonicalJson($current->priceSnapshot())
            && BootstrapIdempotency::canonicalJson($reserved->entitlementSnapshot())
                === BootstrapIdempotency::canonicalJson($current->entitlementSnapshot())
            && BootstrapIdempotency::canonicalJson($reserved->refundPolicySnapshot())
                === BootstrapIdempotency::canonicalJson($current->refundPolicySnapshot());
        if (!$sameIdentity || !$sameSnapshots) {
            throw new MemberTransactionException(
                409,
                MembershipPurchasePolicy::PLAN_UNAVAILABLE,
                'Selected membership plan changed; refresh plans and retry'
            );
        }
    }

    private function assertContextOwnership(
        array $context,
        TenantContext $tenant,
        array $member,
        string $checkoutKey
    ): void {
        if ((int) $context['tenant_id'] !== $tenant->tenantId()
            || (int) $context['channel_id'] !== $tenant->channelId()
            || (int) $context['member_id'] !== (int) $member['id']
            || (int) $context['uid'] !== (int) $member['uid']
            || (string) $context['business_type'] !== 'membership'
            || !hash_equals((string) $context['context_no'], $checkoutKey)) {
            throw new MemberTransactionException(
                503,
                'membership_order_inconsistent',
                'Membership order context identity is inconsistent'
            );
        }
    }

    private function contextByRecord(int $recordId, bool $lock): ?array
    {
        $query = Db::table('ch_order_context')->where('idempotency_record_id', $recordId);
        if ($lock) {
            $query->lock(true);
        }
        $row = $query->find();

        return is_array($row) ? $row : null;
    }

    private function idempotencyRecordById(int $recordId, bool $lock): array
    {
        $query = Db::table('ch_idempotency_record')->where('id', $recordId);
        if ($lock) {
            $query->lock(true);
        }
        $row = $query->find();
        if (!is_array($row)) {
            throw new RuntimeException('Membership checkout idempotency record is unavailable');
        }

        return $row;
    }

    private function memberByUser(int $tenantId, int $uid, bool $lock): array
    {
        $query = Db::table('ch_tenant_member')
            ->where('tenant_id', $tenantId)
            ->where('uid', $uid);
        if ($lock) {
            $query->lock(true);
        }
        $row = $query->find();
        if (!is_array($row)) {
            throw new MemberTransactionException(404, 'member_not_found', 'Member account was not found');
        }

        return $row;
    }

    private function assertMemberCurrentChannel(TenantContext $tenant, array $member): void
    {
        if ((int) $member['current_channel_id'] !== $tenant->channelId()) {
            throw new MemberTransactionException(
                403,
                'tenant_scope_denied',
                'Member is not active in the requested channel'
            );
        }
    }

    private function assertActiveMember(array $member): void
    {
        if ((int) $member['status'] !== 1 || (int) $member['is_del'] !== 0) {
            throw new MemberTransactionException(403, 'member_disabled', 'Member account is not active');
        }
    }

    private function memberTier(array $member): string
    {
        try {
            return MemberTier::fromDatabaseRank((int) $member['tier']);
        } catch (InvalidArgumentException $exception) {
            throw new RuntimeException('Member tier projection is invalid');
        }
    }

    private function assertAuthenticatedUser(array $user, int $expectedUid): void
    {
        $uid = $user['uid'] ?? null;
        if (is_string($uid) && preg_match('/^[1-9][0-9]*$/D', $uid) === 1) {
            $parsed = (int) $uid;
            if ((string) $parsed === $uid) {
                $uid = $parsed;
            }
        }
        if (!is_int($uid) || $uid !== $expectedUid) {
            throw new RuntimeException('Authenticated CRMEB user identity is inconsistent');
        }
    }

    private function assertLeaseOwner(array $record, string $leaseToken): void
    {
        if ((string) $record['operation'] !== MembershipCheckoutIdempotency::OPERATION
            || (string) $record['status'] !== 'processing'
            || !hash_equals((string) $record['lease_token'], $leaseToken)) {
            throw new MemberTransactionException(
                409,
                'idempotency_conflict',
                'Membership checkout execution lease was lost'
            );
        }
    }

    private function markUnknown(int $recordId, string $leaseToken): void
    {
        try {
            $now = $this->now();
            Db::table('ch_idempotency_record')
                ->where('id', $recordId)
                ->where('status', 'processing')
                ->where('lease_token', $leaseToken)
                ->update([
                    'status' => 'unknown',
                    'lease_token' => '',
                    'lease_expire_time' => 0,
                    'result_http_status' => 0,
                    'result_code' => '',
                    'result_hash' => '',
                    'result_json' => null,
                    'completed_time' => 0,
                    'expire_time' => $now + self::RETENTION_SECONDS,
                    'update_time' => $now,
                ]);
        } catch (Throwable $ignored) {
            // Preserve the original checkout failure; the expired lease remains repairable.
        }
    }

    private function assertSameContext(array $expected, array $actual): void
    {
        foreach (['id', 'tenant_id', 'channel_id', 'member_id', 'uid', 'context_no', 'business_id'] as $field) {
            if ((string) $expected[$field] !== (string) $actual[$field]) {
                throw new RuntimeException('Membership order context changed during reconciliation');
            }
        }
    }

    private function checkoutKey(string $internalKey): string
    {
        return substr(hash('sha256', "membership-checkout-v1\0" . $internalKey), 0, 32);
    }

    private function amount($value, string $field): string
    {
        if (is_int($value)) {
            $value = $value . '.00';
        } elseif (is_float($value)) {
            $value = number_format($value, 2, '.', '');
        }
        if (is_string($value) && preg_match('/^[0-9]+(?:\.[0-9])?$/D', $value) === 1) {
            $value = number_format((float) $value, 2, '.', '');
        }
        try {
            return \app\chamber\commerce\Money::assertAmount($value, $field);
        } catch (InvalidArgumentException $exception) {
            throw new RuntimeException(sprintf('%s database amount is invalid', $field));
        }
    }

    private function jsonArray($value, string $field): array
    {
        $decoded = $this->decodeJson($value, $field);
        if (!$this->isList($decoded)) {
            throw new InvalidArgumentException(sprintf('%s must be a JSON array', $field));
        }

        return $decoded;
    }

    private function jsonObject($value, string $field): array
    {
        $decoded = $this->decodeJson($value, $field);
        if ($decoded !== [] && $this->isList($decoded)) {
            throw new InvalidArgumentException(sprintf('%s must be a JSON object', $field));
        }

        return $decoded;
    }

    private function decodeJson($value, string $field): array
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException(sprintf('%s must be JSON text', $field));
        }
        $decoded = json_decode($value, true);
        if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidArgumentException(sprintf('%s is invalid JSON', $field));
        }

        return $decoded;
    }

    private function isList(array $value): bool
    {
        $expected = 0;
        foreach ($value as $key => $unused) {
            if ($key !== $expected) {
                return false;
            }
            $expected++;
        }

        return true;
    }

    private function now(): int
    {
        $now = call_user_func($this->clock);
        if (!is_int($now) || $now <= 0) {
            throw new RuntimeException('Membership checkout clock is invalid');
        }

        return $now;
    }

    private function leaseToken(): string
    {
        $token = call_user_func($this->leaseTokenFactory);
        if (!is_string($token)
            || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $token)) {
            throw new RuntimeException('Membership checkout lease token is invalid');
        }

        return $token;
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return substr($hex, 0, 8) . '-'
            . substr($hex, 8, 4) . '-'
            . substr($hex, 12, 4) . '-'
            . substr($hex, 16, 4) . '-'
            . substr($hex, 20, 12);
    }
}
