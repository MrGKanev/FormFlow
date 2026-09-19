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
        ?string $errorMessage = null,
        ?string $url = null,
        ?array $payload = null
    ): void;

    /** @param array<string, mixed> $payload */
    public function enqueue(string $formId, string $channel, string $url, array $payload): void;

    /** @return list<array<string, mixed>> */
    public function due(int $limit = 100): array;

    /**
     * Atomically claims a pending delivery so two concurrently-running workers
     * can't both dispatch the same webhook. Returns false if another worker
     * already holds a fresh claim on it (a claim older than $staleAfterSeconds
     * is treated as abandoned, e.g. by a crashed worker, and can be re-claimed).
     */
    public function claim(int $id, int $staleAfterSeconds = 900): bool;

    public function markQueuedSent(int $id, int $attempts): void;

    public function markQueuedFailed(int $id, int $attempts, string $errorMessage, ?int $retryAfterSeconds = null): void;

    public function countByStatus(string $status): int;

    public function oldestCreatedAtByStatus(string $status): ?string;

    public function replay(int $id): ?int;

    /** @return list<array<string, mixed>> */
    public function deliveryLog(int $limit = 100): array;
}
