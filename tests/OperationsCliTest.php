<?php

declare(strict_types=1);
namespace formflow\Tests;

use formflow\SqliteSubmissionRepository;
use formflow\WorkerHeartbeat;
use PHPUnit\Framework\TestCase;

final class OperationsCliTest extends TestCase
{
    use MonitoringFixture;
    private function runCommand(string $command): array
    {
        $environment = array_merge(getenv(), ['DATABASE_PATH' => 'storage/submissions.sqlite', 'MAILER_DSN' => 'null://null', 'MAIL_FROM' => 'forms@example.com']);
        $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/bin/formflow', $command, '--root=' . $this->root], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        return [proc_close($process), $output, $error];
    }
    public function testStatusCommandsReturnMachineReadableHealthAndExitCodes(): void
    {
        $repository = new SqliteSubmissionRepository($this->root . '/storage/submissions.sqlite');
        foreach (['delivery:status', 'delivery:check'] as $command) {
            [$exit, $output, $error] = $this->runCommand($command);
            self::assertSame(0, $exit, $error);
            self::assertTrue(json_decode($output, true, 512, JSON_THROW_ON_ERROR)['healthy']);
        }
        $repository->create('contact', [], null, 'pending_mail');
        foreach (['delivery:status', 'delivery:check'] as $command) {
            [$exit, $output, $error] = $this->runCommand($command);
            self::assertSame(1, $exit, $error);
            $status = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
            self::assertFalse($status['healthy']);
            self::assertSame(1, $status['mail']['pending']);
        }
    }
    public function testMissingDatabaseFailsWithoutCreatingDatabase(): void
    {
        foreach (['delivery:status', 'delivery:check'] as $command) {
            [$exit, $output, $error] = $this->runCommand($command);
            self::assertSame(1, $exit);
            self::assertStringContainsString('database is unavailable', $error);
            self::assertSame('', $output);
            self::assertFileDoesNotExist($this->root . '/storage/submissions.sqlite');
        }
    }
    public function testMailWorkerRecordsSuccessfulHeartbeatAfterNullTransportDelivery(): void
    {
        mkdir($this->root . '/config');
        file_put_contents($this->root . '/config/forms.php', "<?php return ['contact' => ['recipient' => 'owner@example.com']];");
        $repository = new SqliteSubmissionRepository($this->root . '/storage/submissions.sqlite');
        $id = $repository->create('contact', ['name' => 'Ada'], null, 'pending_mail');
        [$exit, $output, $error] = $this->runCommand('mail:work');
        self::assertSame(0, $exit, $error);
        self::assertStringContainsString('sent=1', $output);
        self::assertSame('sent', $repository->find($id)['status']);
        $state = (new WorkerHeartbeat($this->root))->read('mail');
        self::assertSame('completed', $state['status']);
        self::assertNotNull($state['last_success']);
        self::assertSame(1, $state['summary']['sent']);
    }
    public function testTopLevelWorkerFailureRecordsFailedHeartbeat(): void
    {
        mkdir($this->root . '/config');
        file_put_contents($this->root . '/config/forms.php', '<?php return false;');
        [$exit, $output, $error] = $this->runCommand('mail:work');
        self::assertSame(1, $exit);
        self::assertNotSame('', $error);
        $state = (new WorkerHeartbeat($this->root))->read('mail');
        self::assertSame('failed', $state['status']);
        self::assertNotNull($state['last_run']);
        self::assertNull($state['last_success']);
    }
}
