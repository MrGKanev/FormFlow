<?php

declare(strict_types=1);

namespace formflow;

/**
 * @phpstan-type QueueStatus array{pending: int, failed: int, oldest_pending_at: string|null, oldest_pending_age_seconds: int|null, last_run: string|null, last_success: string|null, worker_age_seconds: int|null, worker_status: string, worker_started_at: string|null, worker_run_age_seconds: int|null, last_summary: array<string, int>}
 * @phpstan-type DeliveryStatus array{healthy: bool, checked_at: string, mail: QueueStatus, webhooks: QueueStatus, alerts: list<string>}
 */
final class DeliveryMonitor
{
    public function __construct(
        private readonly SubmissionRepositoryInterface $submissions,
        private readonly ?WebhookDeliveryRepositoryInterface $webhooks,
        private readonly string $root,
        private readonly int $queueMaxAgeSeconds = 900,
        private readonly int $workerMaxAgeSeconds = 900,
        private readonly int $failedThreshold = 1
    ) {
    }

    public static function fromEnvironment(SubmissionRepositoryInterface $submissions, ?WebhookDeliveryRepositoryInterface $webhooks, string $root): self
    {
        return new self(
            $submissions, $webhooks, $root,
            self::setting('DELIVERY_QUEUE_MAX_AGE_SECONDS', 900),
            self::setting('DELIVERY_WORKER_MAX_AGE_SECONDS', 900),
            self::setting('DELIVERY_FAILED_THRESHOLD', 1)
        );
    }

    /** @return DeliveryStatus */
    public function snapshot(?int $now = null): array
    {
        $now ??= Clock::nowTimestamp();
        $mail = $this->queue('mail', $this->submissions->count(null, 'pending_mail'), $this->submissions->count(null, 'failed'), $this->submissions->oldestCreatedAtByStatus('pending_mail'), $now);
        $webhooks = $this->queue('webhooks', $this->webhooks?->countByStatus('pending') ?? 0, $this->webhooks?->countByStatus('failed') ?? 0, $this->webhooks?->oldestCreatedAtByStatus('pending'), $now);
        $alerts = [];
        foreach (['mail' => $mail, 'webhooks' => $webhooks] as $name => $queue) {
            if ($queue['failed'] >= max(1, $this->failedThreshold)) {
                $alerts[] = $name . ': ' . $queue['failed'] . ' failed deliveries.';
            }
            if ($queue['pending'] > 0) {
                if ($queue['oldest_pending_age_seconds'] === null || $queue['oldest_pending_age_seconds'] >= max(1, $this->queueMaxAgeSeconds)) {
                    $alerts[] = $name . ': pending deliveries have exceeded the queue age limit.';
                }
                if ($queue['worker_age_seconds'] === null || $queue['worker_age_seconds'] >= max(1, $this->workerMaxAgeSeconds)) {
                    $alerts[] = $name . ': no recent successful worker run.';
                }
            }
            if ($queue['worker_status'] === 'running' && ($queue['worker_run_age_seconds'] === null || $queue['worker_run_age_seconds'] >= max(1, $this->workerMaxAgeSeconds))) {
                $alerts[] = $name . ': the worker is still running beyond its allowed age.';
            }
            if ($queue['worker_status'] === 'failed') {
                $alerts[] = $name . ': the last worker run failed.';
            }
        }
        return ['healthy' => $alerts === [], 'checked_at' => gmdate('c', $now), 'mail' => $mail, 'webhooks' => $webhooks, 'alerts' => $alerts];
    }

    /** @return QueueStatus */
    private function queue(string $worker, int $pending, int $failed, ?string $oldest, int $now): array
    {
        $state = (new WorkerHeartbeat($this->root))->read($worker);
        $lastRun = is_string($state['last_run'] ?? null) ? $state['last_run'] : null;
        $startedAt = is_string($state['started_at'] ?? null) ? $state['started_at'] : null;
        $lastSuccess = is_string($state['last_success'] ?? null) ? $state['last_success'] : null;
        $summary = [];
        if (is_array($state['summary'] ?? null)) {
            foreach ($state['summary'] as $key => $value) {
                if (is_string($key) && is_int($value)) {
                    $summary[$key] = $value;
                }
            }
        }
        return [
            'pending' => $pending, 'failed' => $failed,
            'oldest_pending_at' => $oldest,
            'oldest_pending_age_seconds' => self::age($oldest, $now),
            'last_run' => $lastRun, 'last_success' => $lastSuccess,
            'worker_age_seconds' => self::age($lastSuccess, $now),
            'worker_status' => is_string($state['status'] ?? null) ? $state['status'] : 'never',
            'worker_started_at' => $startedAt, 'worker_run_age_seconds' => self::age($startedAt, $now),
            'last_summary' => $summary,
        ];
    }

    private static function age(?string $time, int $now): ?int
    {
        $timestamp = $time === null ? false : strtotime($time);
        return $timestamp === false || $timestamp > $now ? null : $now - $timestamp;
    }

    private static function setting(string $name, int $default): int
    {
        $value = filter_var(getenv($name), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $value === false ? $default : $value;
    }
}
