<?php

declare(strict_types=1);

namespace formflow;

interface WebhookTransportInterface
{
    /**
     * @param array<string, mixed> $payload
     * @return string|null Null when the receiver accepted the request.
     */
    public function postJson(string $url, array $payload): ?string;
}
