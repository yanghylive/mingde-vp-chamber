<?php

declare(strict_types=1);

namespace app\chamber\services;

use app\chamber\exceptions\MemberTransactionException;
use app\chamber\identity\AuthenticatedAdminContext;
use app\chamber\identity\AuthenticatedUserContext;
use app\chamber\membership\BootstrapIdempotency;
use app\chamber\membership\GraduateVerificationState;
use app\chamber\membership\MemberTier;
use app\chamber\membership\MembershipTermState;
use app\chamber\tenancy\TenantContext;
use app\chamber\verification\GraduateVerificationAdminQuery;
use app\chamber\verification\GraduateVerificationApplication;
use app\chamber\verification\GraduateVerificationReviewRequest;
use app\chamber\verification\GraduateVerificationSubmission;
use RuntimeException;
use think\facade\Db;

final class GraduateVerificationService
{
    public const REVIEW_PERMISSION = 'chamber.graduate_verification.review';

    private const SUBMIT_OPERATION = 'submitGraduateVerification';
    private const REVIEW_OPERATION = 'reviewGraduateVerification';
    private const USER_PRINCIPAL = 'crmeb_user';
    private const ADMIN_PRINCIPAL = 'crmeb_admin';

    /** @var GraduateVerificationIdempotency */
    private $idempotency;

    /** @var MemberAssetService */
    private $assets;

    public function __construct(
        GraduateVerificationIdempotency $idempotency,
        MemberAssetService $assets
    ) {
        $this->idempotency = $idempotency;
        $this->assets = $assets;
    }

    public function query(TenantContext $tenant, AuthenticatedUserContext $auth): array
    {
        return Db::transaction(function () use ($tenant, $auth): array {
            $member = $this->memberByUser($tenant->tenantId(), $auth->uid(), false);
            $this->assertActiveMember($member);
            $latest = $this->latestApplication(
                $tenant->tenantId(),
                (int) $member['id'],
                $auth->uid(),
                false
            );
            $state = GraduateVerificationState::fromDatabase((int) $member['verification_status']);

            return [
                'current_status' => $state,
                'latest_application' => $latest === null
                    ? null
                    : $this->application($tenant, $latest),
                'can_submit' => in_array($state, [
                    GraduateVerificationState::DRAFT,
                    GraduateVerificationState::RETURNED,
                    GraduateVerificationState::REJECTED,
                    GraduateVerificationState::REVOKED,
                ], true),
            ];
        });
    }

    public function submit(
        TenantContext $tenant,
        AuthenticatedUserContext $auth,
        GraduateVerificationSubmission $submission,
        string $callerIdempotencyKey,
        array $requestMetadata = []
    ): array {
        $this->assertActiveMember($this->memberByUser($tenant->tenantId(), $auth->uid(), false));

        return $this->idempotency->execute(
            $tenant,
            self::SUBMIT_OPERATION,
            self::USER_PRINCIPAL,
            $auth->uid(),
            $callerIdempotencyKey,
            $submission->toCanonicalArray(),
            201,
            function (int $now) use ($tenant, $auth, $submission, $requestMetadata): array {
                $member = $this->memberByUser($tenant->tenantId(), $auth->uid(), true);
                $this->assertActiveMember($member);
                $latest = $this->latestApplication(
                    $tenant->tenantId(),
                    (int) $member['id'],
                    $auth->uid(),
                    true
                );
                $current = $this->currentApplication(
                    $tenant->tenantId(),
                    (int) $member['id'],
                    $auth->uid(),
                    true
                );
                $fromState = GraduateVerificationState::fromDatabase(
                    (int) $member['verification_status']
                );
                $this->assertSubmissionAllowed($member, $latest, $current, $submission);
                $this->assets->assertOwnedProofKeys(
                    $tenant,
                    $auth,
                    $submission->proofObjectKeys()
                );

                $proofJson = BootstrapIdempotency::canonicalJson($submission->proofObjectKeys());
                $applicationId = (int) Db::table('ch_graduate_verification')->insertGetId([
                    'tenant_id' => $tenant->tenantId(),
                    'member_id' => (int) $member['id'],
                    'uid' => $auth->uid(),
                    'channel_id' => $tenant->channelId(),
                    'apply_no' => bin2hex(random_bytes(16)),
                    'previous_application_id' => $submission->supersedesId(),
                    'class_name' => $submission->className(),
                    'graduation_year' => $submission->graduationYear(),
                    'graduation_time' => $submission->graduationAt(),
                    'proof_json' => $proofJson,
                    'status' => GraduateVerificationState::toDatabase(GraduateVerificationState::PENDING),
                    'current_slot' => 1,
                    'reviewer_admin_id' => 0,
                    'review_note' => '',
                    'submit_time' => $now,
                    'review_time' => 0,
                    'add_time' => $now,
                    'update_time' => $now,
                ]);
                if ($applicationId <= 0) {
                    throw new RuntimeException('Graduate verification application was not persisted');
                }

                $this->assets->consume(
                    $tenant,
                    $auth,
                    $submission->proofObjectKeys(),
                    $applicationId,
                    $now
                );

                $updated = Db::table('ch_tenant_member')
                    ->where('id', (int) $member['id'])
                    ->where('tenant_id', $tenant->tenantId())
                    ->where('uid', $auth->uid())
                    ->update([
                        'verification_status' => GraduateVerificationState::toDatabase(
                            GraduateVerificationState::PENDING
                        ),
                        'current_verification_id' => $applicationId,
                        'tier' => MemberTier::rank(MemberTier::L1),
                        'certified_time' => 0,
                        'tier_expire_time' => 0,
                        'current_membership_term_id' => 0,
                        'membership_version' => (int) $member['membership_version'] + 1,
                        'update_time' => $now,
                    ]);
                if ($updated !== 1) {
                    throw new RuntimeException('Member graduate verification projection was not updated');
                }

                $this->appendAudit(
                    $tenant,
                    $applicationId,
                    'submit',
                    $fromState,
                    GraduateVerificationState::PENDING,
                    1,
                    $auth->uid(),
                    '',
                    $requestMetadata,
                    $submission->supersedesId(),
                    $now
                );

                $row = $this->applicationById($tenant, $applicationId, false, false);
                if ($row === null) {
                    throw new RuntimeException('Submitted graduate verification application is unavailable');
                }

                return $this->application($tenant, $row);
            },
            function () use ($tenant, $auth): void {
                $member = $this->memberByUser($tenant->tenantId(), $auth->uid(), true);
                $this->assertActiveMember($member);
            }
        );
    }

    public function review(
        TenantContext $tenant,
        AuthenticatedAdminContext $admin,
        int $applicationId,
        GraduateVerificationReviewRequest $review,
        string $callerIdempotencyKey,
        array $requestMetadata = []
    ): array {
        $admin->assertPermission(self::REVIEW_PERMISSION);
        if ($applicationId <= 0) {
            throw new MemberTransactionException(
                422,
                'request_validation_failed',
                'application_id must be a positive integer',
                [['field' => 'application_id', 'code' => 'invalid_value']]
            );
        }
        $reviewTarget = $this->assertReviewTargetActive($tenant, $applicationId, false);
        $approvalProofContext = null;
        if ($review->targetState() === GraduateVerificationState::APPROVED) {
            $applicationSnapshot = GraduateVerificationApplication::fromDatabaseRow($reviewTarget);
            $approvalProofContext = [
                'keys' => $applicationSnapshot['proof_object_keys'],
                'member_id' => (int) $reviewTarget['member_id'],
                'uid' => (int) $reviewTarget['uid'],
                'channel_id' => (int) $reviewTarget['channel_id'],
            ];
        }

        try {
            return $this->idempotency->execute(
                $tenant,
                self::REVIEW_OPERATION,
                self::ADMIN_PRINCIPAL,
                $admin->adminId(),
                $callerIdempotencyKey,
                [
                    'application_id' => $applicationId,
                    'review' => $review->toCanonicalArray(),
                ],
                200,
                function (int $now) use (
                    $tenant,
                    $admin,
                    $applicationId,
                    $review,
                    $requestMetadata
                ): array {
                    $candidate = $this->applicationById($tenant, $applicationId, false, true);
                    if ($candidate === null) {
                        throw new MemberTransactionException(
                            404,
                            'verification_application_not_found',
                            'Graduate verification application was not found'
                        );
                    }

                    $member = $this->memberByIdentity(
                        $tenant->tenantId(),
                        (int) $candidate['member_id'],
                        (int) $candidate['uid'],
                        true
                    );
                    $this->assertActiveMember($member);
                    $application = $this->applicationById($tenant, $applicationId, true, true);
                    if ($application === null
                        || (int) $application['member_id'] !== (int) $member['id']
                        || (int) $application['uid'] !== (int) $member['uid']) {
                        throw new RuntimeException('Graduate verification application identity changed while locking');
                    }

                    $from = GraduateVerificationState::fromDatabase((int) $application['status']);
                    $to = $review->targetState();
                    if (!GraduateVerificationState::canTransition($from, $to)) {
                        throw new MemberTransactionException(
                            409,
                            'verification_transition_invalid',
                            sprintf('Graduate verification cannot transition from %s to %s', $from, $to)
                        );
                    }
                    if ($to === GraduateVerificationState::APPROVED) {
                        $applicationSnapshot = GraduateVerificationApplication::fromDatabaseRow($application);
                        $this->assets->assertAvailableProofKeysForReview(
                            $tenant,
                            $applicationSnapshot['proof_object_keys'],
                            (int) $application['member_id'],
                            (int) $application['uid'],
                            (int) $application['channel_id']
                        );
                    }

                    $updated = Db::table('ch_graduate_verification')
                        ->where('id', $applicationId)
                        ->where('tenant_id', $tenant->tenantId())
                        ->where('channel_id', $tenant->channelId())
                        ->where('status', GraduateVerificationState::toDatabase($from))
                        ->update([
                            'status' => GraduateVerificationState::toDatabase($to),
                            'current_slot' => $to === GraduateVerificationState::APPROVED ? 1 : null,
                            'reviewer_admin_id' => $admin->adminId(),
                            'review_note' => $review->note(),
                            'review_time' => $now,
                            'update_time' => $now,
                        ]);
                    if ($updated !== 1) {
                        throw new MemberTransactionException(
                            409,
                            'verification_transition_invalid',
                            'Graduate verification state changed before the review could be committed'
                        );
                    }

                    $projection = $this->membershipProjection(
                        $tenant->tenantId(),
                        (int) $member['id'],
                        (int) $member['uid'],
                        $to === GraduateVerificationState::APPROVED,
                        $now
                    );
                    $memberUpdated = Db::table('ch_tenant_member')
                        ->where('id', (int) $member['id'])
                        ->where('tenant_id', $tenant->tenantId())
                        ->where('uid', (int) $member['uid'])
                        ->update([
                            'verification_status' => GraduateVerificationState::toDatabase($to),
                            'current_verification_id' => $to === GraduateVerificationState::APPROVED
                                ? $applicationId
                                : 0,
                            'certified_time' => $to === GraduateVerificationState::APPROVED ? $now : 0,
                            'tier' => $projection['tier'],
                            'tier_expire_time' => $projection['tier_expire_time'],
                            'current_membership_term_id' => $projection['current_membership_term_id'],
                            'membership_version' => (int) $member['membership_version'] + 1,
                            'update_time' => $now,
                        ]);
                    if ($memberUpdated !== 1) {
                        throw new RuntimeException('Member verification review projection was not updated');
                    }

                    $this->appendAudit(
                        $tenant,
                        $applicationId,
                        $review->action(),
                        $from,
                        $to,
                        2,
                        $admin->adminId(),
                        $review->note(),
                        $requestMetadata,
                        (int) $application['previous_application_id'],
                        $now
                    );

                    $row = $this->applicationById($tenant, $applicationId, false, true);
                    if ($row === null) {
                        throw new RuntimeException('Reviewed graduate verification application is unavailable');
                    }

                    return $this->adminApplicationById($tenant, $applicationId);
                },
                function () use ($tenant, $applicationId): void {
                    $this->assertReviewTargetActive($tenant, $applicationId, true);
                }
            );
        } catch (MemberTransactionException $exception) {
            if ($exception->reason() === 'proof_asset_invalid' && is_array($approvalProofContext)) {
                $this->assets->markUnavailableProofKeysForReview(
                    $tenant,
                    $approvalProofContext['keys'],
                    $approvalProofContext['member_id'],
                    $approvalProofContext['uid'],
                    $approvalProofContext['channel_id']
                );
            }
            throw $exception;
        }
    }

    public function listForAdmin(
        TenantContext $tenant,
        AuthenticatedAdminContext $admin,
        GraduateVerificationAdminQuery $query
    ): array {
        $admin->assertPermission(self::REVIEW_PERMISSION);
        $totalQuery = $this->adminListQuery($tenant, $query);
        $total = (int) $totalQuery->count();
        $rows = $this->adminListQuery($tenant, $query)
            ->field('verification.*,profile.real_name AS member_name')
            ->order('verification.id', 'desc')
            ->page($query->page(), $query->perPage())
            ->select()
            ->toArray();

        $items = [];
        foreach ($rows as $row) {
            $items[] = $this->adminApplication($tenant, $row);
        }

        return [
            'items' => $items,
            'total' => $total,
            'page' => $query->page(),
            'per_page' => $query->perPage(),
        ];
    }

    public function detailForAdmin(
        TenantContext $tenant,
        AuthenticatedAdminContext $admin,
        int $applicationId
    ): array {
        $admin->assertPermission(self::REVIEW_PERMISSION);
        if ($applicationId <= 0) {
            throw new MemberTransactionException(
                422,
                'request_validation_failed',
                'application_id must be a positive integer',
                [['field' => 'application_id', 'code' => 'invalid_value']]
            );
        }

        return $this->adminApplicationById($tenant, $applicationId);
    }

    private function assertSubmissionAllowed(
        array $member,
        ?array $latest,
        ?array $current,
        GraduateVerificationSubmission $submission
    ): void {
        $state = GraduateVerificationState::fromDatabase((int) $member['verification_status']);
        if ($state === GraduateVerificationState::PENDING
            || ($current !== null && (int) $current['status'] === GraduateVerificationState::toDatabase(
                GraduateVerificationState::PENDING
            ))) {
            throw new MemberTransactionException(
                409,
                'verification_already_pending',
                'A graduate verification application is already pending'
            );
        }
        if ($state === GraduateVerificationState::APPROVED || $current !== null) {
            throw new MemberTransactionException(
                409,
                'verification_transition_invalid',
                'Approved graduate verification must be revoked before another application can be submitted'
            );
        }

        if ($latest === null) {
            if ($submission->supersedesId() !== 0 || $state !== GraduateVerificationState::DRAFT) {
                throw new MemberTransactionException(
                    409,
                    'verification_supersedes_mismatch',
                    'Graduate verification supersedes_id does not match the latest application'
                );
            }

            return;
        }

        $latestState = GraduateVerificationState::fromDatabase((int) $latest['status']);
        if (!in_array($latestState, [
            GraduateVerificationState::RETURNED,
            GraduateVerificationState::REJECTED,
            GraduateVerificationState::REVOKED,
        ], true) || $latestState !== $state || $submission->supersedesId() !== (int) $latest['id']) {
            throw new MemberTransactionException(
                409,
                'verification_supersedes_mismatch',
                'Graduate verification supersedes_id does not match the latest resubmittable application'
            );
        }
    }

    private function membershipProjection(
        int $tenantId,
        int $memberId,
        int $uid,
        bool $graduateApproved,
        int $now
    ): array {
        if (!$graduateApproved) {
            return [
                'tier' => MemberTier::rank(MemberTier::L1),
                'tier_expire_time' => 0,
                'current_membership_term_id' => 0,
            ];
        }

        $term = Db::table('ch_membership_term')
            ->where('tenant_id', $tenantId)
            ->where('member_id', $memberId)
            ->where('uid', $uid)
            ->where('state', MembershipTermState::GRANTED)
            ->where('effective_start_time', '<=', $now)
            ->where('effective_end_time', '>', $now)
            ->whereIn('tier', [MemberTier::rank(MemberTier::L3), MemberTier::rank(MemberTier::L4)])
            ->order('tier', 'desc')
            ->order('effective_end_time', 'desc')
            ->order('id', 'desc')
            ->find();
        if (!is_array($term)) {
            return [
                'tier' => MemberTier::rank(MemberTier::L2),
                'tier_expire_time' => 0,
                'current_membership_term_id' => 0,
            ];
        }

        $tier = (int) $term['tier'];
        if (!in_array($tier, [MemberTier::rank(MemberTier::L3), MemberTier::rank(MemberTier::L4)], true)) {
            throw new RuntimeException('Active membership term tier is invalid');
        }

        return [
            'tier' => $tier,
            'tier_expire_time' => (int) $term['effective_end_time'],
            'current_membership_term_id' => (int) $term['id'],
        ];
    }

    private function memberByUser(int $tenantId, int $uid, bool $lock): array
    {
        $query = Db::table('ch_tenant_member')
            ->where('tenant_id', $tenantId)
            ->where('uid', $uid);
        if ($lock) {
            $query->lock(true);
        }
        $member = $query->find();
        if (!is_array($member)) {
            throw new MemberTransactionException(404, 'member_not_found', 'Tenant member was not found');
        }

        return $member;
    }

    private function memberByIdentity(int $tenantId, int $memberId, int $uid, bool $lock): array
    {
        $query = Db::table('ch_tenant_member')
            ->where('tenant_id', $tenantId)
            ->where('id', $memberId)
            ->where('uid', $uid);
        if ($lock) {
            $query->lock(true);
        }
        $member = $query->find();
        if (!is_array($member)) {
            throw new RuntimeException('Graduate verification member identity is inconsistent');
        }

        return $member;
    }

    private function assertActiveMember(array $member): void
    {
        if ((int) $member['status'] !== 1 || (int) $member['is_del'] !== 0) {
            throw new MemberTransactionException(403, 'member_disabled', 'Member account is not active');
        }
    }

    private function assertReviewTargetActive(
        TenantContext $tenant,
        int $applicationId,
        bool $lock
    ): array {
        $candidate = $this->applicationById($tenant, $applicationId, false, true);
        if ($candidate === null) {
            throw new MemberTransactionException(
                404,
                'verification_application_not_found',
                'Graduate verification application was not found'
            );
        }
        $member = $this->memberByIdentity(
            $tenant->tenantId(),
            (int) $candidate['member_id'],
            (int) $candidate['uid'],
            $lock
        );
        $this->assertActiveMember($member);

        if ($lock) {
            $application = $this->applicationById($tenant, $applicationId, true, true);
            if ($application === null
                || (int) $application['member_id'] !== (int) $member['id']
                || (int) $application['uid'] !== (int) $member['uid']) {
                throw new RuntimeException('Graduate verification application identity changed while authorizing');
            }
        }

        return $candidate;
    }

    private function latestApplication(int $tenantId, int $memberId, int $uid, bool $lock): ?array
    {
        $query = Db::table('ch_graduate_verification')
            ->where('tenant_id', $tenantId)
            ->where('member_id', $memberId)
            ->where('uid', $uid)
            ->order('id', 'desc');
        if ($lock) {
            $query->lock(true);
        }
        $row = $query->find();

        return is_array($row) ? $row : null;
    }

    private function currentApplication(int $tenantId, int $memberId, int $uid, bool $lock): ?array
    {
        $query = Db::table('ch_graduate_verification')
            ->where('tenant_id', $tenantId)
            ->where('member_id', $memberId)
            ->where('uid', $uid)
            ->where('current_slot', 1);
        if ($lock) {
            $query->lock(true);
        }
        $row = $query->find();

        return is_array($row) ? $row : null;
    }

    private function applicationById(
        TenantContext $tenant,
        int $applicationId,
        bool $lock,
        bool $requireCurrentChannel
    ): ?array {
        $query = Db::table('ch_graduate_verification')
            ->where('tenant_id', $tenant->tenantId())
            ->where('id', $applicationId);
        if ($requireCurrentChannel) {
            $query->where('channel_id', $tenant->channelId());
        }
        if ($lock) {
            $query->lock(true);
        }
        $row = $query->find();

        return is_array($row) ? $row : null;
    }

    private function appendAudit(
        TenantContext $tenant,
        int $applicationId,
        string $action,
        string $from,
        string $to,
        int $operatorType,
        int $operatorId,
        string $opinion,
        array $metadata,
        int $previousApplicationId,
        int $now
    ): void {
        $extra = BootstrapIdempotency::canonicalJson([
            'channel_id' => $tenant->channelId(),
            'correlation_id' => (string) ($metadata['correlation_id'] ?? ''),
            'previous_application_id' => $previousApplicationId,
        ]);
        $inserted = Db::table('ch_audit_record')->insert([
            'tenant_id' => $tenant->tenantId(),
            'business_type' => 'graduate_verification',
            'business_id' => $applicationId,
            'action' => $action,
            'from_status' => GraduateVerificationState::toDatabase($from),
            'to_status' => GraduateVerificationState::toDatabase($to),
            'operator_type' => $operatorType,
            'operator_id' => $operatorId,
            'opinion' => $opinion,
            'extra_json' => $extra,
            'add_time' => $now,
        ]);
        if ($inserted !== 1) {
            throw new RuntimeException('Graduate verification audit record was not appended');
        }
    }

    private function adminListQuery(
        TenantContext $tenant,
        GraduateVerificationAdminQuery $filter
    ) {
        $query = Db::table('ch_graduate_verification')
            ->alias('verification')
            ->leftJoin(
                ['ch_member_profile' => 'profile'],
                'profile.tenant_id = verification.tenant_id '
                . 'AND profile.member_id = verification.member_id '
                . 'AND profile.uid = verification.uid AND profile.is_del = 0'
            )
            ->where('verification.tenant_id', $tenant->tenantId())
            ->where('verification.channel_id', $tenant->channelId());
        if ($filter->status() !== null) {
            $query->where('verification.status', GraduateVerificationState::toDatabase($filter->status()));
        }
        if ($filter->keyword() !== '') {
            $query->whereLike(
                'verification.apply_no|verification.class_name|profile.real_name',
                '%' . $filter->keyword() . '%'
            );
        }

        return $query;
    }

    private function application(TenantContext $tenant, array $row): array
    {
        $application = GraduateVerificationApplication::fromDatabaseRow($row);
        $application['proof_assets'] = $this->assets->metadataForObjectKeys(
            $tenant,
            $application['proof_object_keys'],
            (int) ($row['member_id'] ?? 0),
            (int) ($row['uid'] ?? 0),
            (int) ($row['channel_id'] ?? 0)
        );

        return $application;
    }

    private function adminApplication(TenantContext $tenant, array $row): array
    {
        $previousApplicationId = (int) $row['previous_application_id'];
        $reviewerAdminId = (int) $row['reviewer_admin_id'];

        return array_merge($this->application($tenant, $row), [
            'member_id' => (int) $row['member_id'],
            'uid' => (int) $row['uid'],
            'channel_id' => (int) $row['channel_id'],
            'previous_application_id' => $previousApplicationId > 0 ? $previousApplicationId : null,
            'reviewer_admin_id' => $reviewerAdminId > 0 ? $reviewerAdminId : null,
            'is_current' => $row['current_slot'] !== null && (int) $row['current_slot'] === 1,
            'member_name' => (string) ($row['member_name'] ?? ''),
        ]);
    }

    private function adminApplicationById(TenantContext $tenant, int $applicationId): array
    {
        $row = Db::table('ch_graduate_verification')
            ->alias('verification')
            ->leftJoin(
                ['ch_member_profile' => 'profile'],
                'profile.tenant_id = verification.tenant_id '
                . 'AND profile.member_id = verification.member_id '
                . 'AND profile.uid = verification.uid AND profile.is_del = 0'
            )
            ->where('verification.tenant_id', $tenant->tenantId())
            ->where('verification.channel_id', $tenant->channelId())
            ->where('verification.id', $applicationId)
            ->field('verification.*,profile.real_name AS member_name')
            ->find();
        if (!is_array($row)) {
            throw new MemberTransactionException(
                404,
                'verification_application_not_found',
                'Graduate verification application was not found'
            );
        }

        return $this->adminApplication($tenant, $row);
    }
}
