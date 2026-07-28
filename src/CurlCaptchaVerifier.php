<?php

declare(strict_types=1);

namespace formflow;

use RuntimeException;

final class CurlCaptchaVerifier implements CaptchaVerifierInterface
{
    /** @param array<string, string|null> $settings */
    public function __construct(
        private readonly array $settings
    ) {
    }

    public function verify(string $provider, string $token, ?string $remoteIp = null): bool
    {
        if ($token === '') {
            return false;
        }

        return match ($provider) {
            'hcaptcha' => $this->verifyFormEncoded(
                'https://api.hcaptcha.com/siteverify',
                [
                    'secret' => $this->setting('hcaptcha_secret'),
                    'response' => $token,
                    'remoteip' => $remoteIp ?? '',
                ]
            ),
            'recaptcha' => $this->verifyFormEncoded(
                'https://www.google.com/recaptcha/api/siteverify',
                [
                    'secret' => $this->setting('recaptcha_secret'),
                    'response' => $token,
                    'remoteip' => $remoteIp ?? '',
                ]
            ),
            'friendlycaptcha' => $this->verifyFriendlyCaptcha($token),
            default => false,
        };
    }

    private function verifyFriendlyCaptcha(string $token): bool
    {
        $apiKey = $this->setting('friendly_captcha_api_key');

        if ($apiKey === '') {
            return false;
        }

        $payload = [
            'response' => $token,
        ];
        $siteKey = $this->setting('friendly_captcha_site_key');

        if ($siteKey !== '') {
            $payload['sitekey'] = $siteKey;
        }

        return $this->postJson(
            'https://global.frcapi.com/api/v2/captcha/siteverify',
            $payload,
            ['X-API-Key: ' . $apiKey]
        );
    }

    /** @param array<string, string> $payload */
    private function verifyFormEncoded(string $url, array $payload): bool
    {
        if (($payload['secret'] ?? '') === '') {
            return false;
        }

        $payload = array_filter($payload, static fn (string $value): bool => $value !== '');

        return $this->post(
            $url,
            http_build_query($payload),
            ['Content-Type: application/x-www-form-urlencoded']
        );
    }

    /** @param array<string, string> $payload @param list<string> $headers */
    private function postJson(string $url, array $payload, array $headers): bool
    {
        $headers[] = 'Content-Type: application/json';

        return $this->post(
            $url,
            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            $headers
        );
    }

    /** @param list<string> $headers */
    private function post(string $url, string $body, array $headers): bool
    {
        $handle = curl_init($url);

        if ($handle === false) {
            throw new RuntimeException('Unable to initialize cURL.');
        }

        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER => $headers,
        ]);

        $response = curl_exec($handle);

        if ($response === false) {
            $error = curl_error($handle);
            curl_close($handle);

            throw new RuntimeException('CAPTCHA verification request failed: ' . $error);
        }

        $statusCode = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        if ($statusCode < 200 || $statusCode >= 300) {
            return false;
        }

        $data = json_decode((string) $response, true, 512, JSON_THROW_ON_ERROR);

        return ($data['success'] ?? false) === true;
    }

    private function setting(string $key): string
    {
        return trim((string) ($this->settings[$key] ?? ''));
    }
}
