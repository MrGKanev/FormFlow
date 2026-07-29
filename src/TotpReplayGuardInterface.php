<?php

declare(strict_types=1);

namespace formflow;

interface TotpReplayGuardInterface
{
    /**
     * Records that $identity used time-step $step. Returns false if that step was already consumed
     * (replay), true if this is the first time it's been seen.
     */
    public function consume(string $identity, int $step): bool;
}
