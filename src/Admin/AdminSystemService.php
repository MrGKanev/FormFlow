<?php

declare(strict_types=1);

namespace formflow\Admin;

use formflow\Clock;
use formflow\SubmissionRepositoryInterface;
use formflow\WebhookDeliveryRepositoryInterface;

final class AdminSystemService
{
    public function __construct(
        private readonly AdminSettingsService $settingsService,
        private readonly SubmissionRepositoryInterface $submissions,
        private readonly ?WebhookDeliveryRepositoryInterface $webhookDeliveries,
        private readonly int $formCount,
        private readonly string $uploadDirectory,
        private readonly string $root,
        private readonly bool $databaseWasRecreatedDuringAdminSession = false
    ) {
    }

    /** @return array<string, string|int> */
    public function status(): array
    {
        $settings = $this->settingsService->snapshot();
        $databasePath = $settings->databasePath();
        $absoluteDatabasePath = str_starts_with($databasePath, '/')
            ? $databasePath
            : $this->root . '/' . ltrim($databasePath, '/');
        $storagePath = dirname($absoluteDatabasePath);
        $failedMail = $this->submissions->count(null, 'failed');
        $pendingMail = $this->submissions->count(null, 'pending_mail');
        $pendingWebhooks = $this->webhookDeliveries?->countByStatus('pending') ?? 0;

        return [
            'php_version' => PHP_VERSION,
            'app_env' => $settings->appEnv(),
            'database_path' => $absoluteDatabasePath,
            'database_status' => is_file($absoluteDatabasePath) ? 'Present' : 'Missing',
            'database_boot_status' => $this->databaseWasRecreatedDuringAdminSession ? 'Recreated during session' : 'Present at request start',
            'database_size' => is_file($absoluteDatabasePath) ? self::humanBytes(filesize($absoluteDatabasePath) ?: 0) : '0 B',
            'storage_writable' => is_dir($storagePath) && is_writable($storagePath) ? 'Writable' : 'Check storage',
            'uploads_writable' => is_dir($this->uploadDirectory) && is_writable($this->uploadDirectory) ? 'Writable' : 'Check uploads',
            'upload_serving_policy' => $this->uploadServingPolicy(),
            'missing_vendor_packages' => count($this->missingComposerPackages()),
            'mail_delivery_mode' => $settings->mailDeliveryMode(),
            'webhook_delivery_mode' => $settings->webhookDeliveryMode(),
            'pending_mail' => $pendingMail,
            'mail_queue_lag' => $this->queueLag($this->submissions->oldestCreatedAtByStatus('pending_mail')),
            'mail_worker_last_run' => $this->workerLastRun('mail'),
            'failed_mail' => $failedMail,
            'pending_webhooks' => $pendingWebhooks,
            'webhook_queue_lag' => $this->queueLag($this->webhookDeliveries?->oldestCreatedAtByStatus('pending')),
            'webhook_worker_last_run' => $this->workerLastRun('webhooks'),
            'forms' => $this->formCount,
            'trusted_proxies' => count($settings->lines('trusted_proxies')),
        ];
    }

    /** @return list<string> */
    public function warnings(): array
    {
        $settings = $this->settingsService->snapshot();
        $warnings = [];
        $appEnv = $settings->appEnv();

        if ($appEnv !== 'production') {
            $warnings[] = 'APP_ENV is "' . $appEnv . '"; use "production" for deployed instances.';
        }

        $missingPackages = $this->missingComposerPackages();

        if ($missingPackages !== []) {
            $warnings[] = 'Missing Composer packages in vendor/: ' . implode(', ', array_slice($missingPackages, 0, 8)) . (count($missingPackages) > 8 ? ', ...' : '') . '. Run composer install.';
        }

        if ($this->databaseWasRecreatedDuringAdminSession) {
            $warnings[] = 'Database file was missing during this admin session and has been recreated empty. Restore a backup if this was not intentional.';
        }

        if ($this->uploadServingPolicy() === 'Missing deny rule') {
            $warnings[] = 'Uploads are writable and appear to be under the public web root without a local deny rule.';
        }

        if ($settings->mailDeliveryMode() === 'queue' && $this->workerLastRun('mail') === 'Never') {
            $warnings[] = 'Mail queue mode is enabled, but no mail worker heartbeat has been recorded.';
        }

        if ($settings->webhookDeliveryMode() === 'queue' && $this->workerLastRun('webhooks') === 'Never') {
            $warnings[] = 'Webhook queue mode is enabled, but no webhook worker heartbeat has been recorded.';
        }

        return $warnings;
    }

    /** @return list<string> */
    private function missingComposerPackages(): array
    {
        $lockPath = $this->root . '/composer.lock';

        if (!is_file($lockPath)) {
            return [];
        }

        $lock = json_decode((string) file_get_contents($lockPath), true);

        if (!is_array($lock)) {
            return [];
        }

        $packages = array_merge(
            is_array($lock['packages'] ?? null) ? $lock['packages'] : [],
            is_array($lock['packages-dev'] ?? null) ? $lock['packages-dev'] : []
        );
        $missing = [];

        foreach ($packages as $package) {
            $name = is_array($package) ? (string) ($package['name'] ?? '') : '';

            if ($name === '' || !str_contains($name, '/')) {
                continue;
            }

            [$vendor, $packageName] = explode('/', $name, 2);

            if (!is_dir($this->root . '/vendor/' . $vendor . '/' . $packageName)) {
                $missing[] = $name;
            }
        }

        sort($missing);

        return $missing;
    }

    private function uploadServingPolicy(): string
    {
        $publicRoot = realpath($this->root . '/public');
        $uploadRoot = realpath($this->uploadDirectory) ?: $this->uploadDirectory;

        if (!is_dir($this->uploadDirectory)) {
            return 'Check uploads';
        }

        if ($publicRoot === false || !$this->pathIsWithin($uploadRoot, $publicRoot)) {
            return 'Outside public root';
        }

        return $this->uploadDenyRulePresent() ? 'Deny rule present' : 'Missing deny rule';
    }

    private function uploadDenyRulePresent(): bool
    {
        $htaccess = rtrim($this->uploadDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.htaccess';

        if (!is_file($htaccess)) {
            return false;
        }

        $content = strtolower((string) file_get_contents($htaccess));

        return str_contains($content, 'deny from all') || str_contains($content, 'require all denied');
    }

    private function queueLag(?string $oldestCreatedAt): string
    {
        if ($oldestCreatedAt === null || $oldestCreatedAt === '') {
            return 'No pending work';
        }

        $timestamp = strtotime($oldestCreatedAt);

        if ($timestamp === false) {
            return 'Unknown';
        }

        return self::humanDuration(max(0, Clock::nowTimestamp() - $timestamp));
    }

    private function workerLastRun(string $worker): string
    {
        $path = $this->root . '/storage/runtime/' . $worker . '-last-run.txt';

        if (!is_file($path)) {
            return 'Never';
        }

        $timestamp = trim((string) file_get_contents($path));

        return $timestamp !== '' ? $timestamp : 'Never';
    }

    private function pathIsWithin(string $path, string $root): bool
    {
        $realRoot = realpath($root) ?: $root;
        $path = rtrim($path, DIRECTORY_SEPARATOR);
        $root = rtrim($realRoot, DIRECTORY_SEPARATOR);

        return $path === $root || str_starts_with($path, $root . DIRECTORY_SEPARATOR);
    }

    private static function humanDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . 's';
        }

        if ($seconds < 3600) {
            return (int) floor($seconds / 60) . 'm';
        }

        if ($seconds < 86400) {
            return (int) floor($seconds / 3600) . 'h';
        }

        return (int) floor($seconds / 86400) . 'd';
    }

    private static function humanBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $value = (float) $bytes;
        $unit = 'B';

        foreach ($units as $unit) {
            if ($value < 1024 || $unit === 'GB') {
                break;
            }

            $value /= 1024;
        }

        return $unit === 'B'
            ? (string) $bytes . ' B'
            : number_format($value, 1) . ' ' . $unit;
    }
}
