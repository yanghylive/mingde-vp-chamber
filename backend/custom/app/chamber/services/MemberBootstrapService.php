<?php

declare(strict_types=1);

namespace app\chamber\services;

use app\chamber\exceptions\MemberTransactionException;
use app\chamber\identity\AuthenticatedUserContext;
use app\chamber\membership\BootstrapIdempotency;
use app\chamber\membership\GraduateVerificationState;
use app\chamber\membership\MemberBootstrapRequest;
use app\chamber\membership\MemberContext;
use app\chamber\membership\MembershipTermState;
use app\chamber\tenancy\TenantContext;
use RuntimeException;
use Throwable;
use think\facade\Db;

final class MemberBootstrapService
{
    private const OPERATION = 'bootstrapChamberMember';
    private const PRINCIPAL_TYPE = 'crmeb_user';
    private const IDEMPOTENCY_LEASE_SECONDS = 30;
    private const IDEMPOTENCY_RETENTION_SECONDS = 604800;

    /** @var ConsentDocumentRegistry */
    private $consentRegistry;

    public function __construct(ConsentDocumentRegistry $consentRegistry)
    {
        $this->consentRegistry = $consentRegistry;
    }

    public function bootstrap(
        TenantContext $tenant,
        AuthenticatedUserContext $auth,
        MemberBootstrapRequest $request,
        string $callerIdempotencyKey,
        array $requestMetadata = []
    ): array {
        try {
            BootstrapIdempotency::assertCallerKey($callerIdempotencyKey);
        } catch (\InvalidArgumentException $exception) {
            throw new MemberTransactionException(
                400,
                'request_validation_failed',
                'Idempotency-Key is invalid',
                [['field' => 'Idempotency-Key', 'code' => 'invalid_format']]
            );
        }

        $internalKey = BootstrapIdempotency::deriveInternalKey(
            $tenant->tenantId(),
            self::OPERATION,
            self::PRINCIPAL_TYPE,
            $auth->uid(),
            $callerIdempotencyKey
        );
        $requestHash = BootstrapIdempotency::requestHash(
            $tenant->channelId(),
            $request->toCanonicalArray()
        );
        $leaseToken = $this->uuid();
        $now = time();

        return Db::transaction(function () use (
            $tenant,
            $auth,
            $request,
            $requestMetadata,
            $internalKey,
            $requestHash,
            $leaseToken,
            $now
        ): array {
            $idempotency = $this->lockIdempotencyRecord(
                $tenant->tenantId(),
                $auth->uid(),
                $internalKey,
                $requestHash,
                $leaseToken,
                $now
            );

            if ($idempotency['replay'] !== null) {
                $this->assertActiveMember($tenant->tenantId(), $auth->uid());

                return $idempotency['replay'];
            }

            $member = $this->lockOrCreateMember($tenant, $auth->uid(), $request->inviteCode(), $now);
            $profile = $this->lockOrCreateProfile($member, $now);
            $this->recordConsents(
                $tenant,
                $member,
                $request,
                $internalKey,
                $requestMetadata,
                $now
            );

            $data = $this->responseData($tenant, $auth, $member, $profile, $now);
            $this->completeIdempotencyRecord(
                (int) $idempotency['id'],
                $leaseToken,
                $auth->uid(),
                $data,
                $now
            );

            return $data;
        });
    }

    private function lockIdempotencyRecord(
        int $tenantId,
        int $principalId,
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
                self::OPERATION,
                $requestHash,
                $leaseToken,
                $now + self::IDEMPOTENCY_LEASE_SECONDS,
                $now + self::IDEMPOTENCY_RETENTION_SECONDS,
                $now,
                $now,
            ]
        );

        $row = Db::table('ch_idempotency_record')
            ->where('tenant_id', $tenantId)
            ->where('idempotency_key', $internalKey)
            ->lock(true)
            ->find();
        if (!is_array($row)) {
            throw new RuntimeException('Idempotency record was not persisted');
        }
        if ((string) $row['operation'] !== self::OPERATION) {
            throw new RuntimeException('Idempotency operation identity is inconsistent');
        }
        if (!is_string($row['request_hash']) || !hash_equals($row['request_hash'], $requestHash)) {
            throw new MemberTransactionException(
                409,
                'idempotency_conflict',
                'Idempotency-Key was already used with a different request'
            );
        }

        $status = (string) $row['status'];
        if ($status === 'succeeded') {
            return [
                'id' => (int) $row['id'],
                'replay' => $this->decodeIdempotencyResult($row, $internalKey, $principalId),
            ];
        }
        if (!in_array($status, ['processing', 'failed', 'unknown'], true)) {
            throw new RuntimeException('Stored idempotency status is invalid');
        }

        if ($status === 'processing' && hash_equals((string) $row['lease_token'], $leaseToken)) {
            return ['id' => (int) $row['id'], 'replay' => null];
        }
        if ($status === 'processing' && (int) $row['lease_expire_time'] >= $now) {
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
                'lease_expire_time' => $now + self::IDEMPOTENCY_LEASE_SECONDS,
                'attempt_count' => (int) $row['attempt_count'] + 1,
                'result_http_status' => 0,
                'result_code' => '',
                'result_hash' => '',
                'result_json' => null,
                'completed_time' => 0,
                'expire_time' => $now + self::IDEMPOTENCY_RETENTION_SECONDS,
                'update_time' => $now,
            ]);
        if ($updated !== 1) {
            throw new RuntimeException('Idempotency execution lease could not be acquired');
        }

        return ['id' => (int) $row['id'], 'replay' => null];
    }

    private function decodeIdempotencyResult(
        array $row,
        string $expectedInternalKey,
        int $expectedPrincipalId
    ): array
    {
        if ((int) $row['result_http_status'] !== 200 || (string) $row['result_code'] !== 'ok') {
            throw new RuntimeException('Stored idempotency result metadata is inconsistent');
        }
        if (!is_string($row['result_json']) || !is_string($row['result_hash'])) {
            throw new RuntimeException('Stored idempotency result is incomplete');
        }
        $decoded = json_decode($row['result_json'], true);
        if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Stored idempotency result is invalid JSON');
        }
        $computedHash = hash('sha256', BootstrapIdempotency::canonicalJson($decoded));
        if (!hash_equals($row['result_hash'], $computedHash)) {
            throw new RuntimeException('Stored idempotency result hash is invalid');
        }
        if (!isset($decoded['principal_id']) || !is_int($decoded['principal_id']) || !isset($decoded['data'])
            || !is_array($decoded['data'])) {
            throw new RuntimeException('Stored idempotency result identity is invalid');
        }
        if (!hash_equals((string) $row['idempotency_key'], $expectedInternalKey)
            || $decoded['principal_id'] !== $expectedPrincipalId) {
            throw new RuntimeException('Stored idempotency principal is inconsistent');
        }

        return $decoded['data'];
    }

    private function lockOrCreateMember(
        TenantContext $tenant,
        int $uid,
        ?string $requestedInviteCode,
        int $now
    ): array {
        $inserted = Db::execute(
            'INSERT INTO `ch_tenant_member` '
            . '(`tenant_id`,`uid`,`first_channel_id`,`current_channel_id`,`referrer_uid`,`invite_code`,'
            . '`attribution_locked_time`,`tier`,`verification_status`,`current_verification_id`,'
            . '`primary_role_id`,`status`,`join_time`,`certified_time`,`tier_expire_time`,'
            . '`current_membership_term_id`,`membership_version`,`add_time`,`update_time`,`is_del`) '
            . 'VALUES (?,?,0,?,0,NULL,0,1,0,0,0,1,?,0,0,0,0,?,?,0) '
            . 'ON DUPLICATE KEY UPDATE `id`=`id`',
            [$tenant->tenantId(), $uid, $tenant->channelId(), $now, $now, $now]
        );

        $row = $this->memberRow($tenant->tenantId(), $uid, true);
        $this->assertMemberIdentity($row, $tenant->tenantId(), $uid);
        $this->assertMemberActive($row);

        if ($inserted === 1) {
            $referrerUid = $requestedInviteCode === null
                ? 0
                : $this->resolveNewReferrer($tenant->tenantId(), $uid, $requestedInviteCode);
            Db::table('ch_tenant_member')
                ->where('id', (int) $row['id'])
                ->update([
                    'first_channel_id' => $tenant->channelId(),
                    'current_channel_id' => $tenant->channelId(),
                    'referrer_uid' => $referrerUid,
                    'attribution_locked_time' => $now,
                    'update_time' => $now,
                ]);

            // 新人基础积分：注册即送 1000 积分（幂等：按 member 维度只送一次）
            $this->grantWelcomePoints($tenant->tenantId(), $row, $now);
        } elseif ((int) $row['attribution_locked_time'] === 0) {
            $this->assertRequestedAttributionUnchanged($row, $tenant->tenantId(), $requestedInviteCode);
            Db::table('ch_tenant_member')
                ->where('id', (int) $row['id'])
                ->update([
                    'current_channel_id' => $tenant->channelId(),
                    'attribution_locked_time' => max((int) $row['join_time'], 1),
                    'update_time' => $now,
                ]);
        } else {
            $this->assertRequestedAttributionUnchanged($row, $tenant->tenantId(), $requestedInviteCode);
            if ((int) $row['current_channel_id'] !== $tenant->channelId()) {
                Db::table('ch_tenant_member')
                    ->where('id', (int) $row['id'])
                    ->update([
                        'current_channel_id' => $tenant->channelId(),
                        'update_time' => $now,
                    ]);
            }
        }

        if ($row['invite_code'] === null || (string) $row['invite_code'] === '') {
            $this->assignInviteCode((int) $row['id'], $tenant->tenantId(), $now);
        }

        $row = $this->memberRow($tenant->tenantId(), $uid, true);
        $this->assertMemberIdentity($row, $tenant->tenantId(), $uid);
        $this->assertMemberActive($row);

        return $this->normalizeMemberRow($row);
    }

    private function resolveNewReferrer(int $tenantId, int $uid, string $inviteCode): int
    {
        $referrer = Db::table('ch_tenant_member')
            ->where('tenant_id', $tenantId)
            ->where('invite_code', $inviteCode)
            ->where('status', 1)
            ->where('is_del', 0)
            ->lock(true)
            ->find();
        if (!is_array($referrer) || (int) $referrer['uid'] === $uid) {
            throw new MemberTransactionException(
                422,
                'invite_code_invalid',
                'Invite code is invalid',
                [['field' => 'invite_code', 'code' => 'invalid_value']]
            );
        }

        return (int) $referrer['uid'];
    }

    private function assertRequestedAttributionUnchanged(
        array $member,
        int $tenantId,
        ?string $requestedInviteCode
    ): void {
        if ($requestedInviteCode === null) {
            return;
        }

        $referrer = Db::table('ch_tenant_member')
            ->where('tenant_id', $tenantId)
            ->where('invite_code', $requestedInviteCode)
            ->find();
        if (is_array($referrer) && (int) $member['referrer_uid'] > 0
            && (int) $referrer['uid'] === (int) $member['referrer_uid']) {
            return;
        }

        throw new MemberTransactionException(
            409,
            'member_attribution_locked',
            'Member attribution is already locked'
        );
    }

    private function assignInviteCode(int $memberId, int $tenantId, int $now): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $inviteCode = strtoupper(bin2hex(random_bytes(8)));
            try {
                $updated = Db::table('ch_tenant_member')
                    ->where('id', $memberId)
                    ->where('tenant_id', $tenantId)
                    ->whereNull('invite_code')
                    ->update(['invite_code' => $inviteCode, 'update_time' => $now]);
                if ($updated === 1) {
                    return;
                }
            } catch (Throwable $exception) {
                if (stripos($exception->getMessage(), 'Duplicate entry') === false) {
                    throw $exception;
                }
                continue;
            }

            $existing = Db::table('ch_tenant_member')->where('id', $memberId)->value('invite_code');
            if (is_string($existing) && $existing !== '') {
                return;
            }
        }

        throw new RuntimeException('Unable to allocate a unique member invite code');
    }

    private function lockOrCreateProfile(array $member, int $now): array
    {
        Db::execute(
            'INSERT INTO `ch_member_profile` '
            . '(`tenant_id`,`member_id`,`uid`,`real_name`,`avatar_object_key`,`class_name`,`graduation_year`,'
            . '`industry`,`company_name`,`job_title`,`main_business`,`province`,`city`,`bio`,`resources_json`,'
            . '`needs_json`,`interests_json`,`expertise_json`,`privacy_json`,`profile_status`,`add_time`,'
            . '`update_time`,`is_del`) '
            . 'VALUES (?,?,?,\'\',\'\',\'\',0,\'\',\'\',\'\',\'\',\'\',\'\',\'\',\'[]\',\'[]\',\'[]\',\'[]\',\'{}\',2,?,?,0) '
            . 'ON DUPLICATE KEY UPDATE `id`=`id`',
            [(int) $member['tenant_id'], (int) $member['id'], (int) $member['uid'], $now, $now]
        );

        $profile = Db::table('ch_member_profile')
            ->where('tenant_id', (int) $member['tenant_id'])
            ->where('member_id', (int) $member['id'])
            ->lock(true)
            ->find();
        if (!is_array($profile)
            || (int) $profile['uid'] !== (int) $member['uid']
            || (int) $profile['is_del'] !== 0) {
            throw new MemberTransactionException(409, 'profile_invalid', 'Member profile is unavailable');
        }

        return $this->normalizeProfileRow($profile);
    }

    private function recordConsents(
        TenantContext $tenant,
        array $member,
        MemberBootstrapRequest $request,
        string $internalKey,
        array $metadata,
        int $now
    ): void {
        foreach ($request->consents() as $accepted) {
            $document = $this->consentRegistry->resolve(
                $tenant->tenantSlug(),
                $accepted['document_code'],
                $accepted['document_version']
            );
            $latest = Db::table('ch_member_consent')
                ->where('tenant_id', $tenant->tenantId())
                ->where('member_id', (int) $member['id'])
                ->where('document_code', $document->code())
                ->order('id', 'desc')
                ->lock(true)
                ->find();
            if (is_array($latest)
                && (string) $latest['decision'] === 'accepted'
                && hash_equals((string) $latest['document_version'], $document->version())
                && hash_equals((string) $latest['content_sha256'], $document->contentHash())) {
                continue;
            }

            $eventId = hash('sha256', BootstrapIdempotency::canonicalJson([
                'document_code' => $document->code(),
                'document_version' => $document->version(),
                'idempotency_key' => $internalKey,
                'member_id' => (int) $member['id'],
                'scheme' => 'member-consent-v1',
            ]));
            Db::table('ch_member_consent')->insert([
                'tenant_id' => $tenant->tenantId(),
                'channel_id' => $tenant->channelId(),
                'member_id' => (int) $member['id'],
                'uid' => (int) $member['uid'],
                'consent_event_id' => $eventId,
                'document_code' => $document->code(),
                'document_version' => $document->version(),
                'content_sha256' => $document->contentHash(),
                'decision' => 'accepted',
                'source' => 'chamber_api',
                'ip_hash' => $this->consentRegistry->privacyDigest((string) ($metadata['ip'] ?? '')),
                'user_agent_hash' => $this->consentRegistry->privacyDigest(
                    (string) ($metadata['user_agent'] ?? '')
                ),
                'correlation_id' => (string) ($metadata['correlation_id'] ?? ''),
                'occurred_time' => $now,
                'add_time' => $now,
            ]);
        }
    }

    private function responseData(
        TenantContext $tenant,
        AuthenticatedUserContext $auth,
        array $member,
        array $profile,
        int $now
    ): array {
        $context = MemberContext::fromRow($member)->withProfile($profile);
        $membership = $this->membershipSummary($context, $now);

        return [
            'tenant' => ['id' => $tenant->tenantId(), 'slug' => $tenant->tenantSlug()],
            'channel' => ['id' => $tenant->channelId(), 'code' => $tenant->channelSlug()],
            'api_version' => 'v1',
            'phone_bound' => $auth->phoneBound(),
            'member' => $context->toMemberSummary(),
            'profile_complete' => $context->profileComplete(),
            'attribution' => $context->toAttributionSummary(),
            'membership' => $membership,
            'capabilities' => $context->capabilities(),
        ];
    }

    private function membershipSummary(MemberContext $member, int $now): array
    {
        $rows = Db::table('ch_membership_term')
            ->where('tenant_id', $member->tenantId())
            ->where('member_id', $member->memberId())
            ->where('state', MembershipTermState::GRANTED)
            ->where('effective_start_time', '<=', $now)
            ->where('effective_end_time', '>', $now)
            ->order('tier', 'desc')
            ->order('effective_end_time', 'desc')
            ->select()
            ->toArray();

        $terms = [];
        $current = null;
        foreach ($rows as $row) {
            $term = $this->termSummary($row, $now);
            $terms[] = $term;
            if ((int) $row['id'] === $member->currentMembershipTermId()) {
                $current = $term;
            }
        }
        if ($current === null && $terms !== []) {
            $current = $terms[0];
        }

        $canPurchase = $member->isActive()
            && $member->verificationStatus() === GraduateVerificationState::APPROVED;

        return [
            'effective_tier' => $member->tier(),
            'verification_status' => $member->verificationStatus(),
            'tier_expires_at' => $member->tierExpiresAt(),
            'current_term' => $current,
            'active_terms' => $terms,
            'can_purchase' => $canPurchase,
        ];
    }

    private function termSummary(array $row, int $now): array
    {
        $snapshot = json_decode((string) $row['plan_snapshot_json'], true);
        $planVersion = is_array($snapshot)
            ? ($snapshot['config_version'] ?? ($snapshot['plan_version'] ?? 1))
            : 1;
        $planVersion = is_int($planVersion) && $planVersion > 0 ? $planVersion : 1;

        return [
            'term_no' => (string) $row['term_no'],
            'tier' => 'L' . (int) $row['tier'],
            'plan_code' => (string) $row['plan_code'],
            'plan_version' => $planVersion,
            'status' => MembershipTermState::effectiveStatus(
                (int) $row['state'],
                (int) $row['effective_start_time'],
                (int) $row['effective_end_time'],
                $now
            ),
            'starts_at' => (int) $row['effective_start_time'],
            'ends_at' => (int) $row['effective_end_time'],
            'source_order_no' => (string) $row['order_no'],
        ];
    }

    private function completeIdempotencyRecord(
        int $recordId,
        string $leaseToken,
        int $principalId,
        array $data,
        int $now
    ): void {
        $result = ['principal_id' => $principalId, 'data' => $data];
        $resultJson = BootstrapIdempotency::canonicalJson($result);
        $updated = Db::table('ch_idempotency_record')
            ->where('id', $recordId)
            ->where('status', 'processing')
            ->where('lease_token', $leaseToken)
            ->update([
                'status' => 'succeeded',
                'lease_token' => '',
                'lease_expire_time' => 0,
                'result_http_status' => 200,
                'result_code' => 'ok',
                'result_hash' => hash('sha256', $resultJson),
                'result_json' => $resultJson,
                'completed_time' => $now,
                'expire_time' => $now + self::IDEMPOTENCY_RETENTION_SECONDS,
                'update_time' => $now,
            ]);
        if ($updated !== 1) {
            throw new RuntimeException('Idempotency result could not be completed');
        }
    }

    private function assertActiveMember(int $tenantId, int $uid): void
    {
        $row = $this->memberRow($tenantId, $uid, true);
        $this->assertMemberIdentity($row, $tenantId, $uid);
        $this->assertMemberActive($row);
    }

    private function memberRow(int $tenantId, int $uid, bool $lock): array
    {
        $query = Db::table('ch_tenant_member')
            ->where('tenant_id', $tenantId)
            ->where('uid', $uid);
        if ($lock) {
            $query->lock(true);
        }
        $row = $query->find();
        if (!is_array($row)) {
            throw new RuntimeException('Member row was not persisted');
        }

        return $row;
    }

    private function assertMemberIdentity(array $row, int $tenantId, int $uid): void
    {
        if ((int) $row['tenant_id'] !== $tenantId || (int) $row['uid'] !== $uid) {
            throw new RuntimeException('Member row identity is inconsistent');
        }
    }

    private function assertMemberActive(array $row): void
    {
        if ((int) $row['status'] !== 1 || (int) $row['is_del'] !== 0) {
            throw new MemberTransactionException(403, 'member_disabled', 'Member account is not active');
        }
    }

    private function normalizeMemberRow(array $row): array
    {
        foreach ([
            'id', 'tenant_id', 'uid', 'first_channel_id', 'current_channel_id', 'referrer_uid',
            'attribution_locked_time', 'tier', 'verification_status', 'current_verification_id',
            'primary_role_id', 'status', 'join_time', 'certified_time', 'tier_expire_time',
            'current_membership_term_id', 'membership_version', 'add_time', 'update_time', 'is_del',
        ] as $field) {
            $row[$field] = (int) $row[$field];
        }

        return $row;
    }

    private function normalizeProfileRow(array $row): array
    {
        foreach ([
            'id', 'tenant_id', 'member_id', 'uid', 'graduation_year', 'profile_status',
            'add_time', 'update_time', 'is_del',
        ] as $field) {
            $row[$field] = (int) $row[$field];
        }

        return $row;
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

    /**
     * 新人基础积分：会员首次创建时赠送 1000 积分。
     * 幂等保证：以 member_id 维度检查，已有积分账户则跳过（同一会员只会创建一次账户）。
     */
    private function grantWelcomePoints(int $tenantId, array $member, int $now): void
    {
        $memberId = (int) $member['id'];
        $uid = (int) $member['uid'];

        // 已存在积分账户（理论上新会员不会有，双保险防重复赠送）
        $exists = Db::table('ch_point_account')
            ->where('tenant_id', $tenantId)
            ->where('member_id', $memberId)
            ->find();
        if (is_array($exists)) {
            return;
        }

        $welcomePoints = (int) ($this->welcomePoints ?? 1000);
        if ($welcomePoints <= 0) {
            return;
        }

        $accountId = (int) Db::table('ch_point_account')->insertGetId([
            'tenant_id' => $tenantId,
            'member_id' => $memberId,
            'uid' => $uid,
            'balance' => $welcomePoints,
            'frozen_balance' => 0,
            'version' => 1,
            'add_time' => $now,
            'update_time' => $now,
        ]);

        Db::table('ch_point_ledger')->insert([
            'tenant_id' => $tenantId,
            'account_id' => $accountId,
            'member_id' => $memberId,
            'uid' => $uid,
            'delta' => $welcomePoints,
            'balance_after' => $welcomePoints,
            'source_type' => 'welcome_bonus',
            'source_id' => (string) $memberId,
            'remark' => '新人注册基础积分',
            'idempotency_key' => 'welcome_' . $tenantId . '_' . $memberId,
            'status' => 1,
            'reversal_id' => 0,
            'add_time' => $now,
        ]);
    }

}
