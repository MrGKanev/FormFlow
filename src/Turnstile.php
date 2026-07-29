<?php

declare(strict_types=1);

namespace formflow;

use RuntimeException;

final class Turnstile implements TurnstileVerifierInterface
{
    public function __construct(
        private readonly string $secret
    ) {
    }

    public function verify(string $token, ?string $remoteIp = null): bool
    {
        if ($token === '' || $this->secret === '') {
            return false;
        }

        $payload = [
            'secret' => $this->secret,
            'response' => $token,
        ];

        if ($remoteIp !== null && $remoteIp !== '') {
            $payload['remoteip'] = $remoteIp;
        }

        $result = CurlHttpClient::post(
            'https://challenges.cloudflare.com/turnstile/v0/siteverify',
            http_build_query($payload),
            ['Content-Type: application/x-www-form-urlencoded'],
            10,
            5
        );

        if ($result['body'] === false) {
            throw new RuntimeException('Turnstile request failed: ' . $result['error']);
        }

        if ($result['statusCode'] < 200 || $result['statusCode'] >= 300) {
            return false;
        }

        $data = json_decode($result['body'], true, 512, JSON_THROW_ON_ERROR);

        return ($data['success'] ?? false) === true;
    }
}
