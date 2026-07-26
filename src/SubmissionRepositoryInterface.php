<?php

declare(strict_types=1);

namespace formflow;

interface SubmissionRepositoryInterface
{
    /** @param array<string, string> $payload */
    public function create(
        string $formId,
        array $payload,
        ?string $ipHash,
        string $status = 'received'
    ): int;

    public function markSent(int $submissionId): void;

    public function markFailed(int $submissionId, string $errorMessage): void;

    public function markReviewed(int $submissionId): void;

    public function delete(int $submissionId): void;

    public function deleteOlderThan(int $days): int;

    /** @return array<string, mixed>|null */
    public function find(int $submissionId): ?array;

    /** @return list<array<string, mixed>> */
    public function findPaginated(?string $formId, ?string $status, int $page, int $perPage): array;

    /** @return list<array<string, mixed>> */
    public function findForExport(?string $formId, ?string $status): array;

    /** @return list<array<string, mixed>> */
    public function deliveryLog(int $limit = 100): array;

    public function count(?string $formId, ?string $status): int;
}
