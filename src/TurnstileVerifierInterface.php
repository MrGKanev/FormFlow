<?php

declare(strict_types=1);

namespace formflow;

interface TurnstileVerifierInterface
{
    public function verify(string $token, ?string $remoteIp = null): bool;
}
