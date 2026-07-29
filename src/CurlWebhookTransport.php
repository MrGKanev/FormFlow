<?php

declare(strict_types=1);

namespace formflow;

final class CurlWebhookTransport implements WebhookTransportInterface
{
    public function postJson(string $url, array $payload): ?string
    {
        $result = CurlHttpClient::post(
            $url,
            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ['Content-Type: application/json'],
            3,
            2
        );

        if ($result['body'] === false) {
            return $result['error'] !== '' ? $result['error'] : 'Webhook request failed.';
        }

        if ($result['statusCode'] < 200 || $result['statusCode'] >= 300) {
            return 'Webhook returned HTTP ' . $result['statusCode'] . '.';
        }

        return null;
    }
}
