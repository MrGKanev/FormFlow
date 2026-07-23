<?php

declare(strict_types=1);

namespace formflow\Tests\Fakes;

use formflow\TurnstileVerifierInterface;

final class FakeTurnstileVerifier implements TurnstileVerifierInterface
{
    public function __construct(
        private readonly bool $result = true
    ) {
    }

    public function verify(string $token, ?string $remoteIp = null): bool
    {
        return $this->result;
    }
}
