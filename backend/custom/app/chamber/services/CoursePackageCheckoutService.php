<?php

declare(strict_types=1);

namespace app\chamber\services;

use app\chamber\identity\AuthenticatedUserContext;
use app\chamber\membership\BootstrapIdempotency;
use app\chamber\membership\CoursePackageCheckoutRequest;
use app\chamber\membership\CoursePackageSnapshot;
use app\chamber\tenancy\TenantContext;
use InvalidArgumentException;
use think\facade\Db;

/** 购买课时包：免费包直接充值课时；付费包创建订单上下文（待支付）。 */
final class CoursePackageCheckoutService
{
    private const PRINCIPAL_TYPE = 'crmeb_user';
    private const SUCCESS_HTTP_STATUS = 201;

    /** @var CoursePackageService */
    private $packages;

    /** @var CreditAccountService */
    private $credits;

    /** @var CourseIdempotency */
    private $idempotency;

    /** @var callable */
    private $clock;

    public function __construct(
        CoursePackageService $packages = null,
        CreditAccountService $credits = null,
        CourseIdempotency $idempotency = null,
        callable $clock = null
    ) {
        $this->packages = $packages ?: new CoursePackageService();
        $this->credits = $credits ?: new CreditAccountService();
        $this->idempotency = $idempotency ?: new CourseIdempotency();
        $this->clock = $clock ?: function (): int {
            return time();
        };
    }

    public function checkout(
        TenantContext $tenant,
        AuthenticatedUserContext $auth,
        CoursePackageCheckoutRequest $request,
        string $callerKey
    ): array {
        try {
            BootstrapIdempotency::assertCallerKey($callerKey);
        } catch (InvalidArgumentException $exception) {
            throw new \app\chamber\exceptions\MemberTransactionException(
                400,
                'request_validation_failed',
                'Idempotency-Key is invalid',
                [['field' => 'Idempotency-Key', 'code' => 'invalid_format']]
            );
        }

        return $this->idempotency->execute(
            $tenant,
            'createCoursePackageCheckout',
            self::PRINCIPAL_TYPE,
            $auth->uid(),
            $callerKey,
            $request->toIdempotencyArray(),
            self::SUCCESS_HTTP_STATUS,
            function (int $now, int $recordId) use ($tenant, $auth, $request, $callerKey): array {
                $member = $this->member($tenant, $auth, true);
                $packageRow = $this->packages->packageRow($tenant, $request->packageId(), true);
                if (!is_array($packageRow)) {
                    throw new \app\chamber\exceptions\MemberTransactionException(
                        404,
                        'course_package_not_found',
                        '课时包不存在'
                    );
                }
                $package = $this->snapshot($packageRow);
                $amount = $this->amount($package, $request);
                if ($request->expectedAmount() !== null && $request->expectedAmount() !== $amount) {
                    throw new \app\chamber\exceptions\MemberTransactionException(
                        409,
                        'snapshot_mismatch',
                        '课时包价格已变更'
                    );
                }

                if ((int) $package->productId() === 0) {
                    return $this->grantFree($tenant, $auth, $member, $package, $now, $callerKey);
                }

                return $this->reservePaid($tenant, $auth, $member, $package, $amount, $now, $callerKey, $recordId);
            },
            function () use ($tenant, $auth): void {
                $this->member($tenant, $auth, false);
            }
        );
    }

    private function grantFree(
        TenantContext $tenant,
        AuthenticatedUserContext $auth,
        array $member,
        CoursePackageSnapshot $package,
        int $now,
        string $callerKey
    ): array {
        if ($package->courseType() === 'family') {
            $family = $this->ensureFamily($tenant, $member, $now);
            $ownerType = 'family';
            $ownerId = (int) $family['id'];
            $ownerUid = (int) $family['owner_uid'];
        } else {
            $ownerType = 'member';
            $ownerId = (int) $member['id'];
            $ownerUid = $auth->uid();
        }

        $account = $this->credits->ensureAccount($tenant->tenantId(), $ownerType, $ownerId, $ownerUid, $now);
        $expireTime = (int) $package->validityMonths() > 0
            ? $now + (int) $package->validityMonths() * 30 * 86400
            : 0;
        $idempotencyKey = hash('sha256', implode(':', [
            'course_package_grant',
            $tenant->tenantId(),
            $package->id(),
            $ownerType,
            $ownerId,
            $callerKey,
        ]));
        $grant = $this->credits->grant(
            $tenant->tenantId(),
            $account,
            (int) $member['id'],
            $ownerUid,
            $package->totalHours(),
            $expireTime,
            $package->id(),
            $now,
            $idempotencyKey
        );
        $refreshed = $this->credits->getAccountById($tenant->tenantId(), (int) $account['id']);

        return [
            'package_id' => $package->id(),
            'course_type' => $package->courseType(),
            'owner_type' => $ownerType,
            'owner_id' => $ownerId,
            'account_id' => (int) $account['id'],
            'granted_hours' => $package->totalHours(),
            'grant_id' => $grant['grant_id'],
            'expire_time' => $expireTime,
            'balance_hours' => $refreshed['balance_hours'],
            'payment_required' => false,
            'replayed' => false,
        ];
    }

    private function reservePaid(
        TenantContext $tenant,
        AuthenticatedUserContext $auth,
        array $member,
        CoursePackageSnapshot $package,
        string $amount,
        int $now,
        string $callerKey,
        int $recordId
    ): array {
        $checkoutKey = substr(hash('sha256', 'course-checkout:' . $tenant->tenantId() . ':' . $package->id() . ':' . $callerKey), 0, 32);
        $id = (int) Db::table('ch_order_context')->insertGetId([
            'tenant_id' => $tenant->tenantId(),
            'channel_id' => $tenant->channelId(),
            'member_id' => (int) $member['id'],
            'uid' => $auth->uid(),
            'context_no' => $checkoutKey,
            'idempotency_record_id' => $recordId,
            'order_pk' => null,
            'order_no' => null,
            'business_type' => 'course_package',
            'business_id' => $package->id(),
            'currency' => $package->currency(),
            'list_amount' => $amount,
            'payable_amount' => $amount,
            'paid_amount' => '0.00',
            'refunded_amount' => '0.00',
            'integral_amount' => '0.00',
            'price_snapshot_json' => BootstrapIdempotency::canonicalJson([
                'package_id' => $package->id(),
                'code' => $package->code(),
                'price' => $package->price(),
            ]),
            'entitlement_snapshot_json' => '{}',
            'refund_policy_snapshot_json' => BootstrapIdempotency::canonicalJson($package->refundPolicy()),
            'settlement_snapshot_json' => '{}',
            'pay_status' => 0,
            'completion_kind' => 'pending',
            'refund_status' => 0,
            'paid_time' => 0,
            'version' => 1,
            'add_time' => $now,
            'update_time' => $now,
        ]);
        if ($id <= 0) {
            throw new \app\chamber\exceptions\MemberTransactionException(503, 'course_order_failed', '课时包订单上下文创建失败');
        }

        return [
            'package_id' => $package->id(),
            'context_no' => $checkoutKey,
            'payable_amount' => $amount,
            'currency' => $package->currency(),
            'payment_required' => true,
            'replayed' => false,
        ];
    }

    private function ensureFamily(TenantContext $tenant, array $member, int $now): array
    {
        $existing = Db::table('ch_family')
            ->where('tenant_id', $tenant->tenantId())
            ->where('owner_member_id', (int) $member['id'])
            ->where('status', 1)
            ->find();
        if (is_array($existing)) {
            return $existing;
        }
        $familyId = (int) Db::table('ch_family')->insertGetId([
            'tenant_id' => $tenant->tenantId(),
            'name' => ($member['real_name'] ?? '会员') . '的家庭',
            'owner_member_id' => (int) $member['id'],
            'owner_uid' => (int) $member['uid'],
            'status' => 1,
            'add_time' => $now,
            'update_time' => $now,
        ]);
        if ($familyId <= 0) {
            throw new \app\chamber\exceptions\MemberTransactionException(503, 'family_create_failed', '家庭账户创建失败');
        }
        Db::table('ch_family_member')->insertGetId([
            'tenant_id' => $tenant->tenantId(),
            'family_id' => $familyId,
            'member_id' => (int) $member['id'],
            'uid' => (int) $member['uid'],
            'relation' => 'owner',
            'status' => 1,
            'joined_time' => $now,
            'add_time' => $now,
        ]);
        $family = Db::table('ch_family')->where('id', $familyId)->find();
        if (!is_array($family)) {
            throw new \app\chamber\exceptions\MemberTransactionException(503, 'family_create_failed', '家庭账户读取失败');
        }

        return $family;
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
            throw new \app\chamber\exceptions\MemberTransactionException(404, 'member_not_found', '会员不存在');
        }
        if ((int) $member['status'] !== 1 || (int) $member['is_del'] !== 0) {
            throw new \app\chamber\exceptions\MemberTransactionException(403, 'member_disabled', '会员状态不可用');
        }
        if ((int) $member['current_channel_id'] !== $tenant->channelId()) {
            throw new \app\chamber\exceptions\MemberTransactionException(403, 'tenant_scope_denied', '会员不在当前渠道');
        }

        return $member;
    }

    private function amount(CoursePackageSnapshot $package, CoursePackageCheckoutRequest $request): string
    {
        if ($package->courseType() === 'private' && $request->coachTier() > 0) {
            return $package->tierTotalPrice($request->coachTier());
        }

        return $package->price();
    }

    private function snapshot(array $row): CoursePackageSnapshot
    {
        return CoursePackageSnapshot::fromArray($row);
    }
}
