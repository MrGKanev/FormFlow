<?php

declare(strict_types=1);

namespace formflow;

/** Shared low-level POST helper for the outbound cURL calls (captcha/Turnstile verification, webhooks). */
final class CurlHttpClient
{
    /**
     * @param list<string> $headers
     * @param array<int, mixed> $options
     * @return array{statusCode: int, body: string|false, error: string}
     */
    public static function post(
        string $url,
        string $body,
        array $headers,
        int $timeoutSeconds,
        int $connectTimeoutSeconds,
        array $options = []
    ): array {
        if (!function_exists('curl_init')) {
            return ['statusCode' => 0, 'body' => false, 'error' => 'cURL is unavailable.'];
        }

        $handle = curl_init($url);

        if ($handle === false) {
            return ['statusCode' => 0, 'body' => false, 'error' => 'Unable to initialize cURL.'];
        }

        $curlOptions = [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => $connectTimeoutSeconds,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'formflow/1.0',
        ];

        if (defined('CURLOPT_PROTOCOLS_STR') && defined('CURLOPT_REDIR_PROTOCOLS_STR')) {
            $curlOptions[CURLOPT_PROTOCOLS_STR] = 'http,https';
            $curlOptions[CURLOPT_REDIR_PROTOCOLS_STR] = 'http,https';
        } else {
            $protocols = defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')
                ? CURLPROTO_HTTP | CURLPROTO_HTTPS
                : 3;
            $curlOptions[CURLOPT_PROTOCOLS] = $protocols;
            $curlOptions[CURLOPT_REDIR_PROTOCOLS] = $protocols;
        }

        curl_setopt_array($handle, array_replace($curlOptions, $options));

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
