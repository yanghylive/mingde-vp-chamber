<?php

declare(strict_types=1);

namespace app\chamber\services;

use app\chamber\exceptions\MemberTransactionException;
use app\chamber\identity\AuthenticatedUserContext;
use app\chamber\membership\CoursePackageSnapshot;
use app\chamber\membership\OrderContextState;
use app\chamber\tenancy\TenantContext;
use think\facade\Db;
use think\facade\Env;

/**
 * 付费课时包「支付完成 → 授时」的本地开发模拟路径。
 *
 * 生产环境应由 CRMEB 订单支付成功钩子（GuardedStoreOrderSuccessServices）
 * 触发 MembershipPaymentCompletionService 完成授时；但当前课程包
 * （business_type=course_package）尚未接入该钩子（MembershipPaymentCompletionService
 * 仅处理 membership / event_registration）。为能在本地验证
 * 「购买 → 我的课时余额变化」整条机制，本服务以「模拟支付完成」方式补齐
 * course_package 的完成授时链路。
 *
 * 仅允许在开发环境（APP_ENV ∈ dev/local/test…）或显式开启
 * CHAMBER_DEV_MOCK_PAY=1 时使用，避免在生产暴露「标记已付并授时」入口。
 */
final class CoursePackagePaymentCompletionService
{
    private const PRINCIPAL_TYPE = 'crmeb_user';
    private const ALLOWED_PAY_TYPES = ['weixin', 'alipay', 'allinpay', 'yue', 'offline'];

    /** @var CoursePackageService */
    private $packages;

    /** @var CreditAccountService */
    private $credits;

    public function __construct(CoursePackageService $packages = null, CreditAccountService $credits = null)
    {
        $this->packages = $packages ?: new CoursePackageService();
        $this->credits = $credits ?: new CreditAccountService();
    }

    public function complete(
        TenantContext $tenant,
        AuthenticatedUserContext $auth,
        string $contextNo,
        string $payType
    ): array {
        $this->assertDevEnabled();

        if (!in_array($payType, self::ALLOWED_PAY_TYPES, true)) {
            throw new MemberTransactionException(
                422,
                'request_validation_failed',
                'pay_type 不合法',
                [['field' => 'pay_type', 'code' => 'invalid_value']]
            );
        }
        if (!preg_match('/^[a-f0-9]{32}$/D', $contextNo)) {
            throw new MemberTransactionException(
                422,
                'request_validation_failed',
                'context_no 格式不合法',
                [['field' => 'context_no', 'code' => 'invalid_value']]
            );
        }

        $now = time();

        return Db::transaction(function () use ($tenant, $auth, $contextNo, $payType, $now): array {
            $context = Db::table('ch_order_context')
                ->where('tenant_id', $tenant->tenantId())
                ->where('context_no', $contextNo)
                ->where('uid', $auth->uid())
                ->where('business_type', 'course_package')
                ->lock(true)
                ->find();
            if (!is_array($context)) {
                throw new MemberTransactionException(404, 'order_context_not_found', '订单上下文不存在');
            }

            $payStatus = (int) $context['pay_status'];
            OrderContextState::assertPayStatus($payStatus);
            if ($payStatus === OrderContextState::PAY_COMPLETED) {
                // 幂等重放：不重复授时，直接返回当前余额
                return $this->replay($tenant, $auth, $context, $now);
            }
            if ($payStatus !== OrderContextState::PAY_PENDING) {
                throw new MemberTransactionException(409, 'order_context_conflict', '订单状态不可支付');
            }

            $packageRow = $this->packages->packageRow($tenant, (int) $context['business_id'], true);
            if (!is_array($packageRow)) {
                throw new MemberTransactionException(404, 'course_package_not_found', '课时包不存在');
            }
            $package = CoursePackageSnapshot::fromArray($packageRow);

            $member = $this->member($tenant, $auth, true);

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
                $contextNo,
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

            Db::table('ch_order_context')->where('id', (int) $context['id'])->update([
                'paid_amount' => $context['payable_amount'],
                'pay_status' => OrderContextState::PAY_COMPLETED,
                'completion_kind' => OrderContextState::COMPLETION_PAID,
                'paid_time' => $now,
                'version' => (int) $context['version'] + 1,
                'update_time' => $now,
            ]);

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
        });
    }

    private function replay(TenantContext $tenant, AuthenticatedUserContext $auth, array $context, int $now): array
    {
        $packageRow = $this->packages->packageRow($tenant, (int) $context['business_id'], false);
        if (!is_array($packageRow)) {
            throw new MemberTransactionException(404, 'course_package_not_found', '课时包不存在');
        }
        $package = CoursePackageSnapshot::fromArray($packageRow);
        $member = $this->member($tenant, $auth, false);

        if ($package->courseType() === 'family') {
            $family = Db::table('ch_family')
                ->where('tenant_id', $tenant->tenantId())
                ->where('owner_member_id', (int) $member['id'])
                ->where('status', 1)
                ->find();
            if (!is_array($family)) {
                throw new MemberTransactionException(409, 'order_context_conflict', '家庭账户缺失');
            }
            $ownerType = 'family';
            $ownerId = (int) $family['id'];
            $ownerUid = (int) $family['owner_uid'];
        } else {
            $ownerType = 'member';
            $ownerId = (int) $member['id'];
            $ownerUid = $auth->uid();
        }

        $account = $this->credits->ensureAccount($tenant->tenantId(), $ownerType, $ownerId, $ownerUid, $now);

        return [
            'package_id' => $package->id(),
            'course_type' => $package->courseType(),
            'owner_type' => $ownerType,
            'owner_id' => $ownerId,
            'account_id' => (int) $account['id'],
            'granted_hours' => $package->totalHours(),
            'grant_id' => 0,
            'expire_time' => 0,
            'balance_hours' => $account['balance_hours'],
            'payment_required' => false,
            'replayed' => true,
        ];
    }

    private function assertDevEnabled(): void
    {
        // 优先读本代码库约定标识 CHAMBER_ENV；兼容 APP_ENV；显式开关兜底。
        $env = strtolower((string) Env::get('CHAMBER_ENV', (string) Env::get('APP_ENV')));
        $devEnv = in_array($env, ['dev', 'development', 'local', 'test', 'testing'], true);
        $explicit = Env::get('CHAMBER_DEV_MOCK_PAY') === '1';
        // 兼容本仓库已有的本地开发开关标识。
        $localhostFlag = in_array((string) Env::get('CHAMBER_DEV_LOCALHOST_ENABLED'), ['1', 'true', 'on', 'yes'], true);
        if (!$devEnv && !$explicit && !$localhostFlag) {
            throw new MemberTransactionException(
                403,
                'dev_feature_disabled',
                '模拟支付完成仅允许在开发环境使用'
            );
        }
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
            throw new MemberTransactionException(404, 'member_not_found', '会员不存在');
        }
        if ((int) $member['status'] !== 1 || (int) $member['is_del'] !== 0) {
            throw new MemberTransactionException(403, 'member_disabled', '会员状态不可用');
        }
        if ((int) $member['current_channel_id'] !== $tenant->channelId()) {
            throw new MemberTransactionException(403, 'tenant_scope_denied', '会员不在当前渠道');
        }

        return $member;
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
            throw new MemberTransactionException(503, 'family_create_failed', '家庭账户创建失败');
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
            throw new MemberTransactionException(503, 'family_create_failed', '家庭账户读取失败');
        }

        return $family;
    }
}
