<?php

declare(strict_types=1);

namespace formflow;

interface CaptchaVerifierInterface
{
    public function verify(string $provider, string $token, ?string $remoteIp = null): bool;
}
