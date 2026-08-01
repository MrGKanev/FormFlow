<?php

declare(strict_types=1);

namespace formflow;

final class CurlWebhookTransport implements WebhookTransportInterface
{
    /** @param callable(string): list<string>|null $resolver */
    public function __construct(private $resolver = null)
    {
    }

    public function postJson(string $url, array $payload): ?string
    {
        $policyError = WebhookUrlPolicy::validate($url, $this->resolver);

        if ($policyError !== null) {
            return $policyError;
        }

        $result = CurlHttpClient::post(
            $url,
            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            [
                'Content-Type: application/json',
                'Accept: application/json, text/plain, */*',
            ],
            3,
            2,
            [
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS => 0,
            ]
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
