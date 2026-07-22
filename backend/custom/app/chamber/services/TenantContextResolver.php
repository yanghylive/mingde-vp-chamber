<?php

namespace app\chamber\services;

use app\chamber\contracts\SignedTenantRequestVerifierInterface;
use app\chamber\contracts\TenantDirectoryInterface;
use app\chamber\exceptions\TenantResolutionException;
use app\chamber\tenancy\TenantContext;
use app\chamber\tenancy\TenantRecord;
use app\chamber\tenancy\TenantResolutionInput;

final class TenantContextResolver
{
    /** @var TenantDirectoryInterface */
    private $directory;

    /** @var SignedTenantRequestVerifierInterface */
    private $signedRequestVerifier;

    public function __construct(
        TenantDirectoryInterface $directory,
        SignedTenantRequestVerifierInterface $signedRequestVerifier
    ) {
        $this->directory = $directory;
        $this->signedRequestVerifier = $signedRequestVerifier;
    }

    /**
     * Raw tenant_id/channel_id parameters are intentionally not read here.
     *
     * @return TenantContext|null
     */
    public function resolve(TenantResolutionInput $input, bool $required = true)
    {
        $hostRecord = $input->host() === '' ? null : $this->directory->findByHost($input->host());
        $signedRecord = null;

        if ($input->hasSignedCandidate()) {
            $this->signedRequestVerifier->assertValid($input);
            $signedRecord = $this->directory->findBySlugs($input->tenantSlug(), $input->channelSlug());
            if (!$signedRecord) {
                throw new TenantResolutionException(
                    TenantResolutionException::UNKNOWN,
                    'Signed tenant context does not identify an enabled tenant channel',
                    403
                );
            }
        }

        if ($hostRecord && $signedRecord && !$hostRecord->sameScope($signedRecord)) {
            throw new TenantResolutionException(
                TenantResolutionException::CONFLICT,
                'Host and signed tenant contexts conflict',
                403
            );
        }

        $record = $signedRecord ?: $hostRecord;
        if (!$record) {
            if (!$required) {
                return null;
            }

            throw new TenantResolutionException(
                TenantResolutionException::MISSING,
                'Tenant context is required',
                400
            );
        }

        $this->assertActive($record);

        return new TenantContext($record, $signedRecord ? 'signed_channel' : 'host');
    }

    private function assertActive(TenantRecord $record): void
    {
        if (!$record->isActive()) {
            throw new TenantResolutionException(
                TenantResolutionException::INACTIVE,
                'Tenant channel is inactive',
                403
            );
        }
    }
}
