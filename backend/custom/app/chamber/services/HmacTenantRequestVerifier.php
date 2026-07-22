<?php

namespace app\chamber\services;

use app\chamber\contracts\ReplayGuardInterface;
use app\chamber\contracts\SignedTenantRequestVerifierInterface;
use app\chamber\exceptions\TenantResolutionException;
use app\chamber\tenancy\TenantResolutionInput;
use InvalidArgumentException;

final class HmacTenantRequestVerifier implements SignedTenantRequestVerifierInterface
{
    /** @var string */
    private $secret;

    /** @var ReplayGuardInterface */
    private $replayGuard;

    /** @var int */
    private $maxClockSkew;

    /** @var callable */
    private $clock;

    public function __construct(
        string $secret,
        ReplayGuardInterface $replayGuard,
        int $maxClockSkew = 300,
        callable $clock = null
    ) {
        if (strlen($secret) < 32) {
            throw new InvalidArgumentException('Tenant signing secret must contain at least 32 bytes');
        }
        if ($maxClockSkew < 30 || $maxClockSkew > 900) {
            throw new InvalidArgumentException('Tenant signature clock skew must be between 30 and 900 seconds');
        }

        $this->secret = $secret;
        $this->replayGuard = $replayGuard;
        $this->maxClockSkew = $maxClockSkew;
        $this->clock = $clock ?: 'time';
    }

    public function assertValid(TenantResolutionInput $input): void
    {
        if (!$input->hasCompleteSignedCandidate()) {
            throw new TenantResolutionException(
                TenantResolutionException::INCOMPLETE_SIGNATURE,
                'Signed tenant context is incomplete',
                401
            );
        }

        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/', $input->tenantSlug())
            || !preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $input->channelSlug())
            || !preg_match('/^[A-Za-z0-9._~-]{16,128}$/', $input->nonce())
            || !preg_match('/^[0-9]{10}$/', $input->timestamp())
            || !preg_match('/^[a-f0-9]{64}$/', $input->signature())) {
            throw new TenantResolutionException(
                TenantResolutionException::INVALID_INPUT,
                'Signed tenant context contains invalid values',
                401
            );
        }

        $timestamp = (int) $input->timestamp();
        $now = (int) call_user_func($this->clock);
        if (abs($now - $timestamp) > $this->maxClockSkew) {
            throw new TenantResolutionException(
                TenantResolutionException::STALE_SIGNATURE,
                'Signed tenant context has expired',
                401
            );
        }

        if (!hash_equals($this->signatureFor($input), $input->signature())) {
            throw new TenantResolutionException(
                TenantResolutionException::BAD_SIGNATURE,
                'Signed tenant context is invalid',
                401
            );
        }

        if (!$this->replayGuard->claim($input->nonce(), $timestamp + $this->maxClockSkew)) {
            throw new TenantResolutionException(
                TenantResolutionException::REPLAYED,
                'Signed tenant context has already been used',
                401
            );
        }
    }

    public function signatureFor(TenantResolutionInput $input): string
    {
        return hash_hmac('sha256', $input->canonicalPayload(), $this->secret);
    }
}
