<?php

declare(strict_types=1);

namespace formflow;

interface WebhookDeliveryRepositoryInterface
{
    public function record(
        string $formId,
        string $channel,
        string $status,
        int $attempts,
        ?string $errorMessage = null
    ): void;

    /** @param array<string, string|array<string, string>> $payload */
    public function enqueue(string $formId, string $channel, string $url, array $payload): void;

    /** @return list<array<string, mixed>> */
    public function due(int $limit = 100): array;

    public function markQueuedSent(int $id, int $attempts): void;

    public function markQueuedFailed(int $id, int $attempts, string $errorMessage, ?int $retryAfterSeconds = null): void;

    public function countByStatus(string $status): int;

    /** @return list<array<string, mixed>> */
    public function deliveryLog(int $limit = 100): array;
}
