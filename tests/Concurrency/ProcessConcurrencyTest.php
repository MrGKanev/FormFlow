<?php

declare(strict_types=1);

namespace formflow\Tests\Concurrency;

use formflow\SqliteRateLimiter;
use formflow\SqliteSubmissionRepository;
use formflow\SqliteWebhookDeliveryRepository;
use PDO;
use PHPUnit\Framework\TestCase;

final class ProcessConcurrencyTest extends TestCase
{
    private string $directory;
    private array $children = [];

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/formflow-process-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
    }

    protected function tearDown(): void
    {
        foreach ($this->children as [$process, $pipes]) {
            if (proc_get_status($process)['running']) {
                proc_terminate($process, 9);
            }
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) { fclose($pipe); }
            }
            proc_close($process);
        }
        foreach (glob($this->directory . '/*') ?: [] as $path) { unlink($path); }
        rmdir($this->directory);
    }

    public function testParallelWorkersDispatchEachSubmissionOnce(): void
    {
        $database = $this->directory . '/data.sqlite';
        $repository = new SqliteSubmissionRepository($database);
        $ids = [];
        for ($i = 0; $i < 20; $i++) { $ids[] = $repository->create('contact', ['name' => 'Ada'], null, 'pending_mail'); }
        $results = $this->runChildren('worker', $database, $this->directory . '/sent', 6);
        self::assertSame(20, array_sum(array_column($results, 'sent')));
        self::assertCount(20, file($this->directory . '/sent'));
        foreach ($ids as $id) { self::assertSame('sent', $repository->find($id)['status']); }
    }

    public function testCrashedWorkerClaimIsProtectedThenRecoveredAfterLeaseExpiry(): void
    {
        $database = $this->directory . '/data.sqlite';
        $repository = new SqliteSubmissionRepository($database);
        $id = $repository->create('contact', ['name' => 'Ada'], null, 'pending_mail');
        self::assertSame([true], $this->runChildren('crash', $database, (string) $id, 1));
        $results = $this->runChildren('worker', $database, $this->directory . '/sent', 3);
        self::assertSame(0, array_sum(array_column($results, 'sent')));
        (new PDO('sqlite:' . $database))->prepare('UPDATE submissions SET locked_at = ? WHERE id = ?')
            ->execute([gmdate('c', time() - 3600), $id]);
        $results = $this->runChildren('worker', $database, $this->directory . '/sent', 3);
        self::assertSame(1, array_sum(array_column($results, 'sent')));
        self::assertCount(1, file($this->directory . '/sent'));
    }

    public function testAtomicLimiterPreservesBlockedHitsAndDailyLimitAcrossIps(): void
    {
        $limiter = new SqliteRateLimiter($this->directory . '/data.sqlite');
        self::assertNull($limiter->consume('contact', null, 10, 1, 3));
        self::assertSame('ip', $limiter->consume('contact', null, 10, 1, 3));
        self::assertNull($limiter->consume('contact', 'different-ip', 10, 1, 3));
        self::assertSame('daily', $limiter->consume('contact', 'third-ip', 10, 1, 3));
        self::assertSame(4, $limiter->countRecentHitsForForm('contact', 1440));
    }

    public function testParallelRateLimitWritesDoNotLoseHits(): void
    {
        $database = $this->directory . '/data.sqlite';
        $limiter = new SqliteRateLimiter($database);
        $results = $this->runChildren('rate', $database, '', 8);
        self::assertCount(3, array_filter($results, static fn ($result) => $result === null));
        self::assertCount(5, array_filter($results, static fn ($result) => $result === 'ip'));
        self::assertSame(8, $limiter->countRecentHitsByIp('contact', 'ip', 10));
        self::assertSame(8, $limiter->countRecentHitsForForm('contact', 1440));
    }

    public function testParallelWebhookWorkersDispatchEachDeliveryOnce(): void
    {
        $database = $this->directory . '/data.sqlite';
        $repository = new SqliteWebhookDeliveryRepository($database);
        for ($i = 0; $i < 20; $i++) {
            $repository->enqueue('contact', 'webhook', 'https://example.com/hook', ['name' => 'Ada']);
        }
        $results = $this->runChildren('webhook', $database, $this->directory . '/sent', 6);
        self::assertSame(20, array_sum(array_column($results, 'sent')));
        self::assertCount(20, file($this->directory . '/sent'));
        self::assertSame([], $repository->due());
    }

    public function testCrashedWebhookWorkerRecoversAfterLeaseExpiry(): void
    {
        $database = $this->directory . '/data.sqlite';
        $repository = new SqliteWebhookDeliveryRepository($database);
        $repository->enqueue('contact', 'webhook', 'https://example.com/hook', ['name' => 'Ada']);
        $id = $repository->due()[0]['id'];
        self::assertSame([true], $this->runChildren('webhook-crash', $database, (string) $id, 1));
        $results = $this->runChildren('webhook', $database, $this->directory . '/sent', 3);
        self::assertSame(0, array_sum(array_column($results, 'sent')));
        (new PDO('sqlite:' . $database))->prepare('UPDATE webhook_deliveries SET locked_at = ? WHERE id = ?')
            ->execute([gmdate('c', time() - 3600), $id]);
        $results = $this->runChildren('webhook', $database, $this->directory . '/sent', 3);
        self::assertSame(1, array_sum(array_column($results, 'sent')));
        self::assertCount(1, file($this->directory . '/sent'));
    }

    public function testRateLimiterWaitsForExistingWriterLock(): void
    {
        $database = $this->directory . '/data.sqlite';
        $limiter = new SqliteRateLimiter($database);
        $this->runChildren('locked-rate', $database, '', 1);
        self::assertSame(1, $limiter->countRecentHitsForForm('contact', 1440));
    }

    private function runChildren(string $mode, string $database, string $output, int $count): array
    {
        $batch = [];
        for ($i = 0; $i < $count; $i++) {
            $process = proc_open([PHP_BINARY, __DIR__ . '/child.php', $mode, $database, $output],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            stream_set_timeout($pipes[1], 15);
            $this->children[] = [$process, $pipes];
            $batch[] = $pipes;
        }
        // Every process opens its own SQLite connection before the parent releases the barrier.
        foreach ($batch as $pipes) { self::assertSame("ready\n", fgets($pipes[1])); }
        $lock = null;
        if ($mode === 'locked-rate') {
            $lock = new PDO('sqlite:' . $database);
            $lock->exec('BEGIN IMMEDIATE');
        }
        foreach ($batch as $pipes) { fwrite($pipes[0], "go\n"); fflush($pipes[0]); }
        if ($lock !== null) {
            foreach ($batch as $pipes) { self::assertSame("attempting\n", fgets($pipes[1])); }
            $lock->exec('COMMIT');
        }
        $results = [];
        foreach ($batch as $pipes) {
            $line = fgets($pipes[1]);
            self::assertNotFalse($line, 'Child timed out or exited before reporting.');
            $results[] = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        }
        return $results;
    }
}
