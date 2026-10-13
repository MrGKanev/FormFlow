<?php

declare(strict_types=1);
namespace formflow\Tests;

use formflow\DeliveryMonitor;
use formflow\SqliteSubmissionRepository;
use formflow\SqliteWebhookDeliveryRepository;
use PHPUnit\Framework\TestCase;
use PDO;

final class DeliveryMonitorTest extends TestCase
{
    use MonitoringFixture;
    public function testEmptyQueuesNeedNoWorker(): void
    {
        $status = (new DeliveryMonitor(new SqliteSubmissionRepository(':memory:'), null, $this->root))->snapshot();
        self::assertTrue($status['healthy']);
        self::assertSame([], $status['alerts']);
        self::assertSame('never', $status['mail']['worker_status']);
    }
    public function testOldQueueWithoutHeartbeatRaisesBothAlerts(): void
    {
        $path = $this->root . '/data.sqlite';
        $mail = new SqliteSubmissionRepository($path);
        $mail->create('contact', ['secret' => 'hidden'], null, 'pending_mail');
        (new PDO('sqlite:' . $path))->exec("UPDATE submissions SET created_at = '2026-01-01T00:00:00+00:00'");
        $status = (new DeliveryMonitor($mail, null, $this->root, 60, 60))->snapshot(strtotime('2026-01-01T00:02:00Z'));
        self::assertFalse($status['healthy']);
        self::assertSame(120, $status['mail']['oldest_pending_age_seconds']);
        self::assertCount(2, $status['alerts']);
    }
    public function testRecentSuccessHealthyThenStaleAndFailureAreUnhealthy(): void
    {
        $mail = new SqliteSubmissionRepository(':memory:');
        $mail->create('contact', [], null, 'pending_mail');
        $now = time();
        $this->heartbeat('mail', ['last_run' => gmdate('c', $now), 'last_success' => gmdate('c', $now), 'status' => 'completed', 'summary' => ['sent' => 1, 'unsafe' => 'discard']]);
        $monitor = new DeliveryMonitor($mail, null, $this->root, 3600, 60);
        self::assertTrue($monitor->snapshot($now)['healthy']);
        self::assertSame(['sent' => 1], $monitor->snapshot($now)['mail']['last_summary']);
        self::assertFalse($monitor->snapshot($now + 60)['healthy']);
        $this->heartbeat('mail', ['last_success' => gmdate('c', $now), 'status' => 'failed']);
        self::assertStringContainsString('last worker run failed', implode(' ', $monitor->snapshot($now)['alerts']));
    }
    public function testFailedDeliveryThresholdAppliesToBothQueues(): void
    {
        $mail = new SqliteSubmissionRepository(':memory:');
        $webhooks = new SqliteWebhookDeliveryRepository(':memory:');
        $mail->create('contact', [], null, 'failed');
        $webhooks->record('contact', 'generic', 'failed', 3);
        self::assertTrue((new DeliveryMonitor($mail, $webhooks, $this->root, 60, 60, 2))->snapshot()['healthy']);
        $status = (new DeliveryMonitor($mail, $webhooks, $this->root, 60, 60, 1))->snapshot();
        self::assertFalse($status['healthy']);
        self::assertCount(2, $status['alerts']);
    }
    public function testInvalidAndFutureTimestampsCannotIndicateHealthyPendingQueue(): void
    {
        $path = $this->root . '/data.sqlite';
        $mail = new SqliteSubmissionRepository($path);
        $mail->create('contact', [], null, 'pending_mail');
        $monitor = new DeliveryMonitor($mail, null, $this->root);
        foreach (['broken', '2099-01-01T00:00:00+00:00'] as $timestamp) {
            (new PDO('sqlite:' . $path))->prepare('UPDATE submissions SET created_at = ?')->execute([$timestamp]);
            $this->heartbeat('mail', ['last_success' => $timestamp, 'status' => 'completed']);
            $status = $monitor->snapshot();
            self::assertNull($status['mail']['oldest_pending_age_seconds']);
            self::assertNull($status['mail']['worker_age_seconds']);
            self::assertFalse($status['healthy']);
        }
    }
    public function testAbandonedRunningWorkersAlertEvenWhenQueuesAreEmpty(): void
    {
        $monitor = new DeliveryMonitor(new SqliteSubmissionRepository(':memory:'), null, $this->root, 60, 60);
        $now = strtotime('2026-10-13T12:00:00Z');
        foreach (['mail', 'webhooks'] as $worker) {
            foreach ([gmdate('c', $now - 60), 'broken', gmdate('c', $now + 60)] as $startedAt) {
                $this->heartbeat($worker, ['status' => 'running', 'started_at' => $startedAt]);
                $status = $monitor->snapshot($now);
                self::assertFalse($status['healthy']);
                self::assertStringContainsString($worker . ': the worker is still running beyond its allowed age.', implode(' ', $status['alerts']));
            }
            unlink($this->root . '/storage/runtime/' . $worker . '-status.json');
        }
    }

    public function testRecentRunningWorkerWithNoPendingWorkIsHealthy(): void
    {
        $now = strtotime('2026-10-13T12:00:00Z');
        $this->heartbeat('mail', ['status' => 'running', 'started_at' => gmdate('c', $now - 59)]);
        $status = (new DeliveryMonitor(new SqliteSubmissionRepository(':memory:'), null, $this->root, 60, 60))->snapshot($now);
        self::assertTrue($status['healthy']);
        self::assertSame([], $status['alerts']);
    }

}
