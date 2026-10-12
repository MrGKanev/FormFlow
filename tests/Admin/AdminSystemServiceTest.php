<?php

declare(strict_types=1);
namespace formflow\Tests\Admin;

use formflow\Admin\AdminSettingsService;
use formflow\Admin\AdminSystemService;
use formflow\SqliteSubmissionRepository;
use formflow\SqliteWebhookDeliveryRepository;
use formflow\WorkerHeartbeat;
use formflow\Tests\MonitoringFixture;
use PHPUnit\Framework\TestCase;
use PDO;

final class AdminSystemServiceTest extends TestCase
{
    use MonitoringFixture;
    private function service(?SqliteSubmissionRepository $mail = null, ?SqliteWebhookDeliveryRepository $webhooks = null): AdminSystemService
    {
        file_put_contents($this->root . '/.env', "APP_ENV='production'\nMAIL_DELIVERY_MODE='sync'\nWEBHOOK_DELIVERY_MODE='sync'\n");
        return new AdminSystemService(new AdminSettingsService([], $this->root . '/.env'), $mail ?? new SqliteSubmissionRepository(':memory:'), $webhooks, 1, $this->root . '/uploads', $this->root);
    }
    public function testNoDevInstallDoesNotWarnAboutOmittedDeveloperPackages(): void
    {
        file_put_contents($this->root . '/composer.lock', json_encode(['packages' => [['name' => 'symfony/mailer']], 'packages-dev' => [['name' => 'phpunit/phpunit'], ['name' => 'phpstan/phpstan']]], JSON_THROW_ON_ERROR));
        mkdir($this->root . '/vendor/symfony/mailer', 0700, true);
        $service = $this->service();
        self::assertSame(0, $service->status()['missing_vendor_packages']);
        self::assertStringNotContainsString('Missing Composer packages', implode(' ', $service->warnings()));
        self::assertStringNotContainsString('phpunit', implode(' ', $service->warnings()));
    }
    public function testMissingRuntimePackagesStillWarn(): void
    {
        file_put_contents($this->root . '/composer.lock', json_encode(['packages' => [['name' => 'symfony/mailer']], 'packages-dev' => [['name' => 'phpunit/phpunit']]], JSON_THROW_ON_ERROR));
        $service = $this->service();
        self::assertSame(1, $service->status()['missing_vendor_packages']);
        self::assertStringContainsString('Missing Composer packages in vendor/: symfony/mailer.', implode(' ', $service->warnings()));
        self::assertStringNotContainsString('phpunit', implode(' ', $service->warnings()));
    }
    public function testMalformedLockDoesNotCrashStatus(): void
    {
        file_put_contents($this->root . '/composer.lock', '{broken');
        self::assertSame(0, $this->service()->status()['missing_vendor_packages']);
    }
    public function testStatusExposesSuccessfulWorkerTimestampAndFailedWebhookCount(): void
    {
        $webhooks = new SqliteWebhookDeliveryRepository(':memory:');
        $webhooks->record('contact', 'generic', 'failed', 3);
        $heartbeat = new WorkerHeartbeat($this->root);
        $heartbeat->completed('webhooks', ['sent' => 2, 'failed' => 0]);
        $lastSuccess = $heartbeat->read('webhooks')['last_success'];
        $heartbeat->completed('webhooks', ['failed' => 1]);
        $service = $this->service(null, $webhooks);
        $status = $service->status();
        self::assertSame($lastSuccess, $status['webhook_worker_last_success']);
        self::assertSame('failed', $status['webhook_worker_status']);
        self::assertSame(1, $status['failed_webhooks']);
        self::assertSame('Needs attention', $status['delivery_health']);
        self::assertStringContainsString('webhooks: 1 failed deliveries.', implode(' ', $service->warnings()));
    }
    public function testStaleQueueAndMissingWorkerAppearInAdminWarnings(): void
    {
        $database = $this->root . '/data.sqlite';
        $mail = new SqliteSubmissionRepository($database);
        $mail->create('contact', [], null, 'pending_mail');
        (new PDO('sqlite:' . $database))->prepare('UPDATE submissions SET created_at = ?')->execute([gmdate('c', time() - 86400)]);
        $service = $this->service($mail);
        $warnings = implode(' ', $service->warnings());
        self::assertStringContainsString('mail: pending deliveries have exceeded the queue age limit.', $warnings);
        self::assertStringContainsString('mail: no recent successful worker run.', $warnings);
        self::assertSame(1, $service->status()['pending_mail']);
    }
}
