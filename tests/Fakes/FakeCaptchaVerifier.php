<?php

declare(strict_types=1);

namespace formflow\Tests\Fakes;

use formflow\CaptchaVerifierInterface;

final class FakeCaptchaVerifier implements CaptchaVerifierInterface
{
    /** @var list<array{provider: string, token: string, remote_ip: string|null}> */
    public array $verifications = [];

    public function __construct(
        private readonly bool $valid = true
    ) {
    }

    public function verify(string $provider, string $token, ?string $remoteIp = null): bool
    {
        $this->verifications[] = [
            'provider' => $provider,
            'token' => $token,
            'remote_ip' => $remoteIp,
        ];

        return $this->valid;
    }
}
