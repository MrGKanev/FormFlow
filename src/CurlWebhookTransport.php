<?php

declare(strict_types=1);

namespace formflow;

final class CurlWebhookTransport implements DetailedWebhookTransportInterface
{
    /** @param callable(string): list<string>|null $resolver */
    public function __construct(private $resolver = null)
    {
    }

    public function postJson(string $url, array $payload): ?string
    {
        return $this->send($url, $payload, null)->error;
    }

    public function deliver(string $url, array $payload, string $deliveryId): WebhookTransportResult
    {
        if (preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $deliveryId) !== 1) {
            return new WebhookTransportResult('Invalid webhook delivery ID.', false);
        }

        return $this->send($url, $payload, $deliveryId);
    }

    /** @param array<string, mixed> $payload */
    private function send(string $url, array $payload, ?string $deliveryId): WebhookTransportResult
    {
        $policy = WebhookUrlPolicy::resolve($url, $this->resolver);

        if ($policy['error'] !== null) {
            return new WebhookTransportResult($policy['error'], false);
        }

        $retryAfter = null;
        $headers = ['Content-Type: application/json', 'Accept: application/json, text/plain, */*'];
        if ($deliveryId !== null) {
            $headers[] = 'X-FormFlow-Delivery-ID: ' . $deliveryId;
        }
        $result = CurlHttpClient::post(
            $url,
            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            $headers,
            3,
            2,
            [
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS => 0,
                CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$retryAfter): int {
                    if (str_starts_with($line, 'HTTP/')) {
                        $retryAfter = null;
                    } elseif (str_starts_with(strtolower($line), 'retry-after:')) {
                        $retryAfter = self::parseRetryAfter(trim(substr($line, 12)));
                    }
                    return strlen($line);
                },
            ],
            $policy['ips']
        );

        if ($result['body'] === false) {
            return new WebhookTransportResult($result['error'] !== '' ? $result['error'] : 'Webhook request failed.');
        }

        if ($result['statusCode'] < 200 || $result['statusCode'] >= 300) {
            return new WebhookTransportResult(
                'Webhook returned HTTP ' . $result['statusCode'] . '.',
                $result['statusCode'] === 0 || $result['statusCode'] >= 500
                    || in_array($result['statusCode'], [408, 429], true),
                $retryAfter
            );
        }

        return new WebhookTransportResult();
    }

    /** RFC 9110 section 10.2.3; bound receiver-controlled delays to one day. */
    public static function parseRetryAfter(string $value, ?int $now = null): ?int
    {
        if (preg_match('/^[0-9]+$/D', $value) === 1) {
            return (int) min(86400, (float) $value);
        }

        // Accept the three HTTP-date formats, never relative strtotime expressions.
        if (preg_match('/^(?:[A-Z][a-z]{2}, [0-9]{2} [A-Z][a-z]{2} [0-9]{4} [0-9]{2}:[0-9]{2}:[0-9]{2} GMT|[A-Z][a-z]+, [0-9]{2}-[A-Z][a-z]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2} GMT|[A-Z][a-z]{2} [A-Z][a-z]{2} [ 0-9][0-9] [0-9]{2}:[0-9]{2}:[0-9]{2} [0-9]{4})$/D', $value) !== 1) {
            return null;
        }
        $timestamp = strtotime($value . (str_ends_with($value, 'GMT') ? '' : ' GMT'));

        return $timestamp === false ? null : min(86400, max(0, $timestamp - ($now ?? Clock::nowTimestamp())));
    }
}
