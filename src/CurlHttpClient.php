<?php

declare(strict_types=1);

namespace formflow;

/** Shared low-level POST helper for the outbound cURL calls (captcha/Turnstile verification, webhooks). */
final class CurlHttpClient
{
    /**
     * @param list<string> $headers
     * @return array{statusCode: int, body: string|false, error: string}
     */
    public static function post(
        string $url,
        string $body,
        array $headers,
        int $timeoutSeconds,
        int $connectTimeoutSeconds
    ): array {
        if (!function_exists('curl_init')) {
            return ['statusCode' => 0, 'body' => false, 'error' => 'cURL is unavailable.'];
        }

        $handle = curl_init($url);

        if ($handle === false) {
            return ['statusCode' => 0, 'body' => false, 'error' => 'Unable to initialize cURL.'];
        }

        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => $connectTimeoutSeconds,
            CURLOPT_HTTPHEADER => $headers,
        ]);

        $response = curl_exec($handle);
        $error = curl_error($handle);
        $statusCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        return [
            'statusCode' => $statusCode,
            'body' => $response,
            'error' => $error,
        ];
    }
}
