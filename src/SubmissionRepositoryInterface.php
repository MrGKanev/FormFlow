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

    /** @return array<string, mixed>|null */
    public function find(int $submissionId): ?array;
}
