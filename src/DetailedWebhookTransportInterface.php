<?php

declare(strict_types=1);

namespace formflow;

/** Optional extension preserving compatibility with existing transports. */
interface DetailedWebhookTransportInterface extends WebhookTransportInterface
{
    /** @param array<string, mixed> $payload */
    public function deliver(string $url, array $payload, string $deliveryId): WebhookTransportResult;
}
