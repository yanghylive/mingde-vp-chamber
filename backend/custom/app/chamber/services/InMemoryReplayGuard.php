<?php

namespace app\chamber\services;

use app\chamber\contracts\ReplayGuardInterface;

/**
 * Test-only guard. Production must bind ReplayGuardInterface to an atomic Redis implementation.
 */
final class InMemoryReplayGuard implements ReplayGuardInterface
{
    /** @var int[] */
    private $claims = [];

    /** @var callable */
    private $clock;

    public function __construct(callable $clock = null)
    {
        $this->clock = $clock ?: 'time';
    }

    public function claim(string $nonce, int $expiresAt): bool
    {
        $now = (int) call_user_func($this->clock);

        foreach ($this->claims as $claimedNonce => $expiry) {
            if ($expiry < $now) {
                unset($this->claims[$claimedNonce]);
            }
        }

        if (isset($this->claims[$nonce])) {
            return false;
        }

        $this->claims[$nonce] = $expiresAt;

        return true;
    }
}
