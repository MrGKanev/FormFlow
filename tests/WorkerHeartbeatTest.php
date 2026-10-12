<?php

declare(strict_types=1);
namespace formflow\Tests;

use formflow\WorkerHeartbeat;
use PHPUnit\Framework\TestCase;

final class WorkerHeartbeatTest extends TestCase
{
    use MonitoringFixture;
    public function testLifecyclePreservesLastSuccessAcrossStartAndFailures(): void
    {
        $heartbeat = new WorkerHeartbeat($this->root);
        self::assertSame([], $heartbeat->read('mail'));
        $heartbeat->started('mail');
        self::assertSame('running', $heartbeat->read('mail')['status']);
        $heartbeat->completed('mail', ['sent' => 2, 'failed' => 0]);
        $success = $heartbeat->read('mail')['last_success'];
        self::assertNotNull($success);
        self::assertSame($success, trim(file_get_contents($this->root . '/storage/runtime/mail-last-run.txt')));
        $heartbeat->started('mail');
        self::assertSame($success, $heartbeat->read('mail')['last_success']);
        $heartbeat->completed('mail', ['failed' => 1]);
        self::assertSame('failed', $heartbeat->read('mail')['status']);
        self::assertSame($success, $heartbeat->read('mail')['last_success']);
        $heartbeat->failed('mail');
        self::assertSame($success, $heartbeat->read('mail')['last_success']);
    }
    public function testMalformedStatusIsTreatedAsMissingAndCanBeReplaced(): void
    {
        file_put_contents($this->root . '/storage/runtime/webhooks-status.json', '{broken');
        $heartbeat = new WorkerHeartbeat($this->root);
        self::assertSame([], $heartbeat->read('webhooks'));
        $heartbeat->completed('webhooks', ['sent' => 1]);
        self::assertSame('completed', $heartbeat->read('webhooks')['status']);
    }
    public function testUnknownWorkerCannotTraversePaths(): void
    {
        $this->expectException(\RuntimeException::class);
        (new WorkerHeartbeat($this->root))->started('../secrets');
    }

    public function testWaitingWriterMergesSuccessRecordedWhileItWaitsForLock(): void
    {
        $path = $this->root . '/storage/runtime/mail-status.json';
        $lock = fopen($path . '.lock', 'c');
        self::assertIsResource($lock);
        self::assertTrue(flock($lock, LOCK_EX));
        $script = 'require $argv[1]; echo "ready\\n"; (new \\formflow\\WorkerHeartbeat($argv[2]))->failed("mail");';
        $process = proc_open([PHP_BINARY, '-r', $script, dirname(__DIR__) . '/vendor/autoload.php', $this->root], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        try {
            stream_set_timeout($pipes[1], 5);
            self::assertSame("ready\n", fgets($pipes[1]));
            $success = '2026-10-12T12:00:00+00:00';
            // Another writer completes before this process acquires the lock.
            $this->heartbeat('mail', ['last_success' => $success, 'status' => 'completed', 'summary' => ['sent' => 2]]);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $error);
        $state = (new WorkerHeartbeat($this->root))->read('mail');
        self::assertSame('failed', $state['status']);
        self::assertSame($success, $state['last_success']);
        self::assertSame(['sent' => 2], $state['summary']);
    }
}
