<?php

namespace app\chamber\contracts;

interface ReplayGuardInterface
{
    /**
     * Atomically reserves a nonce until the supplied Unix timestamp.
     */
    public function claim(string $nonce, int $expiresAt): bool;
}
