<?php

declare(strict_types=1);

namespace formflow;

final class CurlWebhookTransport implements WebhookTransportInterface
{
    public function postJson(string $url, array $payload): ?string
    {
        if (!function_exists('curl_init')) {
            return 'cURL is unavailable.';
        }

        $handle = curl_init($url);

        if ($handle === false) {
            return 'Unable to initialize the webhook request.';
        }

        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => 5,
        ]);

        $result = curl_exec($handle);
        $error = curl_error($handle);
        $statusCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        if ($result === false) {
            return $error !== '' ? $error : 'Webhook request failed.';
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            return 'Webhook returned HTTP ' . $statusCode . '.';
        }

        return null;
    }
}
