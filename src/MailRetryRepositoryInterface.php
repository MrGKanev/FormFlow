<?php

declare(strict_types=1);

namespace formflow;

interface MailRetryRepositoryInterface extends SubmissionRepositoryInterface
{
    /** @return list<array<string, mixed>> */
    public function findDueMail(int $limit = 100, bool $includeFailed = true, int $maxAttempts = 5): array;

    /** Atomically consumes an attempt and returns the claimed row, or null.
     * @return array<string, mixed>|null
     */
    public function claimMail(int $submissionId, string $expectedStatus, int $maxAttempts = 5, bool $forceRetry = false): ?array;

    /** A null retry delay makes the failure terminal. */
    public function markMailFailed(int $submissionId, string $errorMessage, ?int $retryAfterSeconds): void;
}
