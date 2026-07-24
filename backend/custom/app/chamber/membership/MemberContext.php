<?php

namespace app\chamber\membership;

use InvalidArgumentException;
use LogicException;

/**
 * Immutable, tenant-scoped projection loaded from ch_tenant_member and its profile.
 */
final class MemberContext
{
    public const CONTAINER_KEY = 'chamber.member_context';

    public const STATUS_DISABLED = 'disabled';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_WITHDRAWN = 'withdrawn';

    private const DATABASE_STATUSES = [
        0 => self::STATUS_DISABLED,
        1 => self::STATUS_ACTIVE,
        2 => self::STATUS_WITHDRAWN,
    ];

    /** @var int */
    private $memberId;

    /** @var int */
    private $tenantId;

    /** @var int */
    private $uid;

    /** @var int */
    private $firstChannelId;

    /** @var int */
    private $currentChannelId;

    /** @var int */
    private $referrerUid;

    /** @var string|null */
    private $inviteCode;

    /** @var int */
    private $attributionLockedAt;

    /** @var string */
    private $tier;

    /** @var string */
    private $status;

    /** @var string */
    private $verificationStatus;

    /** @var int */
    private $currentVerificationId;

    /** @var int */
    private $joinedAt;

    /** @var int */
    private $tierExpiresAt;

    /** @var int */
    private $currentMembershipTermId;

    /** @var int */
    private $membershipVersion;

    /** @var bool */
    private $deleted;

    /** @var array|null */
    private $profile;

    private function __construct()
    {
    }

    public static function fromRow(array $row): self
    {
        $context = new self();
        $context->memberId = self::positiveInteger($row, 'id');
        $context->tenantId = self::positiveInteger($row, 'tenant_id');
        $context->uid = self::positiveInteger($row, 'uid');
        $context->firstChannelId = self::nonNegativeInteger($row, 'first_channel_id');
        $context->currentChannelId = self::nonNegativeInteger($row, 'current_channel_id');
        $context->referrerUid = self::nonNegativeInteger($row, 'referrer_uid');
        $context->inviteCode = self::parseInviteCode($row);
        $context->attributionLockedAt = self::nonNegativeInteger($row, 'attribution_locked_time');
        $context->tier = MemberTier::fromDatabaseRank(self::integer($row, 'tier'));

        $status = self::integer($row, 'status');
        if (!array_key_exists($status, self::DATABASE_STATUSES)) {
            throw new InvalidArgumentException('Unknown member database status');
        }
        $context->status = self::DATABASE_STATUSES[$status];
        $context->verificationStatus = GraduateVerificationState::fromDatabase(
            self::integer($row, 'verification_status')
        );
        $context->currentVerificationId = self::nonNegativeInteger($row, 'current_verification_id');
        $context->joinedAt = self::nonNegativeInteger($row, 'join_time');
        $context->tierExpiresAt = self::nonNegativeInteger($row, 'tier_expire_time');
        $context->currentMembershipTermId = self::nonNegativeInteger($row, 'current_membership_term_id');
        $context->membershipVersion = self::nonNegativeInteger($row, 'membership_version');

        $isDeleted = self::integer($row, 'is_del');
        if (!in_array($isDeleted, [0, 1], true)) {
            throw new InvalidArgumentException('Unknown member deletion flag');
        }
        $context->deleted = $isDeleted === 1;
        $context->profile = null;
        if (array_key_exists('profile', $row) && $row['profile'] !== null) {
            $context->profile = self::validatedProfile(
                $row['profile'],
                $context->tenantId,
                $context->memberId,
                $context->uid
            );
        }

        return $context;
    }

    public function withProfile(array $profile): self
    {
        $copy = clone $this;
        $copy->profile = self::validatedProfile($profile, $this->tenantId, $this->memberId, $this->uid);

        return $copy;
    }

    public function memberId(): int
    {
        return $this->memberId;
    }

    public function tenantId(): int
    {
        return $this->tenantId;
    }

    public function uid(): int
    {
        return $this->uid;
    }

    public function firstChannelId(): int
    {
        return $this->firstChannelId;
    }

    public function currentChannelId(): int
    {
        return $this->currentChannelId;
    }

    public function referrerUid(): int
    {
        return $this->referrerUid;
    }

    public function inviteCode(): ?string
    {
        return $this->inviteCode;
    }

    public function attributionLockedAt(): int
    {
        return $this->attributionLockedAt;
    }

    public function tier(): string
    {
        return $this->tier;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function verificationStatus(): string
    {
        return $this->verificationStatus;
    }

    public function currentVerificationId(): int
    {
        return $this->currentVerificationId;
    }

    public function joinedAt(): int
    {
        return $this->joinedAt;
    }

    public function tierExpiresAt(): int
    {
        return $this->tierExpiresAt;
    }

    public function currentMembershipTermId(): int
    {
        return $this->currentMembershipTermId;
    }

    public function membershipVersion(): int
    {
        return $this->membershipVersion;
    }

    public function isActive(): bool
    {
        return !$this->deleted && $this->status === self::STATUS_ACTIVE;
    }

    public function profile(): ?array
    {
        return $this->profile;
    }

    public function hasProfile(): bool
    {
        return $this->profile !== null && $this->profile['is_del'] === 0;
    }

    public function profileComplete(): bool
    {
        return $this->hasProfile() && in_array($this->profile['profile_status'], [0, 1], true);
    }

    public function capabilities(): array
    {
        if (!$this->isActive()) {
            return [
                'can_edit_profile' => false,
                'can_submit_verification' => false,
                'can_purchase_membership' => false,
                'can_view_member_directory' => false,
            ];
        }

        $approved = $this->verificationStatus === GraduateVerificationState::APPROVED;

        return [
            'can_edit_profile' => true,
            'can_submit_verification' => in_array($this->verificationStatus, [
                GraduateVerificationState::DRAFT,
                GraduateVerificationState::RETURNED,
                GraduateVerificationState::REJECTED,
                GraduateVerificationState::REVOKED,
            ], true),
            'can_purchase_membership' => $approved,
            'can_view_member_directory' => $approved,
        ];
    }

    public function toMemberSummary(): array
    {
        return [
            'id' => $this->memberId,
            'tier' => $this->tier,
            'status' => $this->status,
            'verification_status' => $this->verificationStatus,
            'joined_at' => $this->joinedAt,
            'tier_expires_at' => $this->tierExpiresAt,
        ];
    }

    public function toAttributionSummary(): array
    {
        if ($this->inviteCode === null) {
            throw new LogicException('Member invite code has not been initialized');
        }

        return [
            'own_invite_code' => $this->inviteCode,
            'referrer_bound' => $this->referrerUid > 0,
            'locked_at' => $this->attributionLockedAt,
        ];
    }

    private static function validatedProfile($profile, int $tenantId, int $memberId, int $uid): array
    {
        if (!is_array($profile)) {
            throw new InvalidArgumentException('Member profile must be an array');
        }
        if (self::positiveInteger($profile, 'tenant_id') !== $tenantId
            || self::positiveInteger($profile, 'member_id') !== $memberId
            || self::positiveInteger($profile, 'uid') !== $uid) {
            throw new InvalidArgumentException('Member profile identity does not match member context');
        }

        $profileStatus = self::integer($profile, 'profile_status');
        if (!in_array($profileStatus, [0, 1, 2], true)) {
            throw new InvalidArgumentException('Unknown member profile status');
        }
        $isDeleted = self::integer($profile, 'is_del');
        if (!in_array($isDeleted, [0, 1], true)) {
            throw new InvalidArgumentException('Unknown member profile deletion flag');
        }

        return $profile;
    }

    private static function parseInviteCode(array $row): ?string
    {
        if (!array_key_exists('invite_code', $row)) {
            throw new InvalidArgumentException('Missing member row field: invite_code');
        }
        if ($row['invite_code'] === null) {
            return null;
        }
        if (!is_string($row['invite_code']) || !preg_match('/^[A-Za-z0-9]{8,16}$/D', $row['invite_code'])) {
            throw new InvalidArgumentException('Member invite code is invalid');
        }

        return $row['invite_code'];
    }

    private static function positiveInteger(array $row, string $field): int
    {
        $value = self::integer($row, $field);
        if ($value <= 0) {
            throw new InvalidArgumentException(sprintf('Member row field %s must be positive', $field));
        }

        return $value;
    }

    private static function nonNegativeInteger(array $row, string $field): int
    {
        $value = self::integer($row, $field);
        if ($value < 0) {
            throw new InvalidArgumentException(sprintf('Member row field %s must be non-negative', $field));
        }

        return $value;
    }

    private static function integer(array $row, string $field): int
    {
        if (!array_key_exists($field, $row) || !is_int($row[$field])) {
            throw new InvalidArgumentException(sprintf('Member row field %s must be an integer', $field));
        }

        return $row[$field];
    }
}
