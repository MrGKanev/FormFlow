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

        $handle = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');

        if ($handle === false) {
            throw new RuntimeException('Unable to initialize cURL.');
        }

        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        ]);

        $response = curl_exec($handle);

        if ($response === false) {
            $error = curl_error($handle);
            curl_close($handle);

            throw new RuntimeException('Turnstile request failed: ' . $error);
        }

        $statusCode = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        if ($statusCode < 200 || $statusCode >= 300) {
            return false;
        }

        $data = json_decode($response, true, 512, JSON_THROW_ON_ERROR);

        return ($data['success'] ?? false) === true;
    }
}
