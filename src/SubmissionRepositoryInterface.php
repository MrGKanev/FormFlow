<?php

declare(strict_types=1);

namespace formflow;

interface SubmissionRepositoryInterface
{
    /** @param array<string, mixed> $payload */
    public function create(
        string $formId,
        array $payload,
        ?string $ipHash,
        string $status = 'received'
    ): int;

    /**
     * Atomically claims a submission for delivery so two concurrently-running workers
     * can't both send the same email. Returns false if another worker already holds
     * a fresh claim on it (a claim older than $staleAfterSeconds is treated as
     * abandoned, e.g. by a crashed worker, and can be re-claimed).
     */
    public function claim(int $submissionId, string $expectedStatus, int $staleAfterSeconds = 900): bool;

    public function markSent(int $submissionId): void;

    public function markFailed(int $submissionId, string $errorMessage): void;

    public function markReviewed(int $submissionId): void;

    public function delete(int $submissionId): void;

    public function deleteOlderThan(int $days): int;

    /** @return array<string, mixed>|null */
    public function find(int $submissionId): ?array;

    /** @return list<array<string, mixed>> */
    public function findPaginated(
        ?string $formId,
        ?string $status,
        int $page,
        int $perPage,
        ?string $search = null,
        ?string $dateFrom = null,
        ?string $dateTo = null
    ): array;

    /** @return list<array<string, mixed>> */
    public function findForExport(
        ?string $formId,
        ?string $status,
        ?string $search = null,
        ?string $dateFrom = null,
        ?string $dateTo = null
    ): array;

    /** @param list<int> $ids @return list<array<string, mixed>> */
    public function findByIds(array $ids): array;

    /** @return list<array<string, mixed>> */
    public function findFailed(int $limit = 100): array;

    /** @return list<array<string, mixed>> */
    public function findPendingMail(int $limit = 100, bool $includeFailed = false): array;

    /** @return list<array<string, mixed>> */
    public function deliveryLog(int $limit = 100): array;

    /** @return list<array<string, mixed>> */
    public function analytics(): array;

    /** @return array{summary: array<string, int|float>, trend: list<array{date: string, total: int}>, statuses: list<array{status: string, total: int}>, forms: list<array{form_id: string, total: int, accepted: int, unique_emails: int, sent: int}>} */
    public function analyticsOverview(int $days = 30, ?string $formId = null): array;

    public function count(
        ?string $formId,
        ?string $status,
        ?string $search = null,
        ?string $dateFrom = null,
        ?string $dateTo = null
    ): int;

    public function oldestCreatedAtByStatus(string $status): ?string;
}
