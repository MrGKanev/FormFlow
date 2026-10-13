<?php

declare(strict_types=1);

namespace formflow\Tests;

use formflow\MailDeliveryWorker;
use formflow\MailRetryPolicy;
use formflow\SqliteSubmissionRepository;
use formflow\Tests\Fakes\FakeMailSender;
use PDO;
use PHPUnit\Framework\TestCase;

final class MailRetryIntegrationTest extends TestCase
{
    use MonitoringFixture;

    private function repository(): SqliteSubmissionRepository
    {
        return new SqliteSubmissionRepository($this->root . '/storage/submissions.sqlite');
    }

    private function dueNow(int $id): void
    {
        (new PDO('sqlite:' . $this->root . '/storage/submissions.sqlite'))
            ->prepare('UPDATE submissions SET mail_next_attempt_at = ? WHERE id = ?')
            ->execute([gmdate('c', time() - 10), $id]);
    }

    private function worker(SqliteSubmissionRepository $repository, FakeMailSender $sender, int $maximum = 3): MailDeliveryWorker
    {
        return new MailDeliveryWorker(['contact' => ['recipient' => 'owner@example.com']], $sender, $repository, new MailRetryPolicy($maximum, 60, 3600));
    }

    public function testFailuresAreScheduledAndBecomeTerminalAtPersistedAttemptBudget(): void
    {
        $repository = $this->repository();
        $id = $repository->create('contact', ['name' => 'Ada'], null, 'pending_mail');
        $sender = new FakeMailSender();
        $sender->shouldThrow = true;
        $worker = $this->worker($repository, $sender);
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $before = time();
            self::assertSame(1, $worker->process()['attempted']);
            $row = $repository->find($id);
            self::assertSame($attempt, $row['mail_attempts']);
            self::assertSame('failed', $row['status']);
            self::assertSame(0, $worker->process()['attempted']);
            if ($attempt < 3) {
                self::assertSame(1, $row['mail_retryable']);
                self::assertGreaterThanOrEqual($before + (30 * (2 ** ($attempt - 1))), strtotime($row['mail_next_attempt_at']));
                $this->dueNow($id);
            } else {
                self::assertSame(0, $row['mail_retryable']);
                self::assertNull($row['mail_next_attempt_at']);
            }
        }
        $sender->shouldThrow = false;
        self::assertSame(0, $worker->process()['attempted']);
        self::assertSame([], $sender->sentMessages);
        self::assertSame(1, $worker->retryFailed()['sent']);
        self::assertSame(1, $repository->find($id)['mail_attempts']);
        self::assertSame(0, $worker->retryFailed()['attempted']);
        self::assertSame(0, $worker->process()['attempted']);
        self::assertCount(1, $sender->sentMessages);
    }

    public function testScheduledRetryThatSucceedsIsNeverDeliveredAgain(): void
    {
        $repository = $this->repository();
        $id = $repository->create('contact', ['name' => 'Ada'], null, 'pending_mail');
        $sender = new FakeMailSender();
        $sender->shouldThrow = true;
        self::assertSame(1, $this->worker($repository, $sender)->process()['attempted']);
        $sender->shouldThrow = false;
        // A fresh worker still sees the persistent future schedule.
        self::assertSame(0, $this->worker($repository, $sender)->process()['attempted']);
        $this->dueNow($id);
        self::assertSame(1, $this->worker($repository, $sender)->process()['sent']);
        $row = $repository->find($id);
        self::assertSame(2, $row['mail_attempts']);
        self::assertSame(0, $row['mail_retryable']);
        self::assertNull($row['mail_next_attempt_at']);
        self::assertSame(0, $this->worker($repository, $sender)->process()['attempted']);
        self::assertCount(1, $sender->sentMessages);
    }

    public function testExplicitRetryBypassesFutureScheduleButNeverAnActiveWorkerLock(): void
    {
        $repository = $this->repository();
        $id = $repository->create('contact', [], null, 'pending_mail');
        $sender = new FakeMailSender();
        $sender->shouldThrow = true;
        $worker = $this->worker($repository, $sender);
        self::assertSame(1, $worker->process()['pending']);
        self::assertSame(0, $worker->process()['attempted']);
        $sender->shouldThrow = false;
        self::assertSame(1, $worker->retryFailed()['sent']);
        self::assertSame(1, $repository->find($id)['mail_attempts']);

        $locked = $repository->create('contact', [], null, 'failed');
        self::assertNotNull($repository->claimMail($locked, 'failed', 3, true));
        self::assertSame(0, $worker->retryFailed()['attempted']);
        self::assertSame(1, $repository->find($locked)['mail_attempts']);
        self::assertCount(1, $sender->sentMessages);
    }

    public function testMalformedPayloadAndMissingFormAreTerminalWithoutSmtp(): void
    {
        $repository = $this->repository();
        $badPayload = $repository->create('contact', [], null, 'pending_mail');
        $missingForm = $repository->create('missing', [], null, 'pending_mail');
        (new PDO('sqlite:' . $this->root . '/storage/submissions.sqlite'))
            ->prepare('UPDATE submissions SET payload = ? WHERE id = ?')->execute(['{invalid', $badPayload]);
        $sender = new FakeMailSender();
        $worker = $this->worker($repository, $sender);
        self::assertSame(2, $worker->process()['failed']);
        foreach ([$badPayload, $missingForm] as $id) {
            $row = $repository->find($id);
            self::assertSame('failed', $row['status']);
            self::assertSame(1, $row['mail_attempts']);
            self::assertSame(0, $row['mail_retryable']);
            self::assertNull($row['mail_next_attempt_at']);
        }
        self::assertSame(0, $worker->process()['attempted']);
        self::assertSame([], $sender->sentMessages);
    }

    public function testInvalidRecipientsFailPermanentlyWithoutCallingSmtp(): void
    {
        $repository = $this->repository();
        $sender = new FakeMailSender();
        foreach (['', 'bad-email', ['owner@example.com'], null] as $recipient) {
            $id = $repository->create('contact', [], null, 'pending_mail');
            $worker = new MailDeliveryWorker(['contact' => ['recipient' => $recipient]], $sender, $repository);
            self::assertSame(1, $worker->process()['failed']);
            $row = $repository->find($id);
            self::assertSame(0, $row['mail_retryable']);
            self::assertNull($row['mail_next_attempt_at']);
            self::assertSame('Submission form is missing or has no recipient configured.', $row['error_message']);
            self::assertSame(0, $worker->process()['attempted']);
        }
        self::assertSame([], $sender->sentMessages);
    }

    public function testCrashOnFinalAttemptIsReapedWithoutAnotherSend(): void
    {
        $repository = $this->repository();
        $id = $repository->create('contact', [], null, 'pending_mail');
        self::assertNotNull($repository->claimMail($id, 'pending_mail', 1));
        $sender = new FakeMailSender();
        $worker = $this->worker($repository, $sender, 1);
        self::assertSame(0, $worker->process()['attempted']);
        self::assertSame('pending_mail', $repository->find($id)['status']);
        (new PDO('sqlite:' . $this->root . '/storage/submissions.sqlite'))
            ->prepare('UPDATE submissions SET locked_at = ? WHERE id = ?')->execute([gmdate('c', time() - 3600), $id]);
        self::assertSame(0, $worker->process()['attempted']);
        $row = $repository->find($id);
        self::assertSame('failed', $row['status']);
        self::assertSame(0, $row['mail_retryable']);
        self::assertSame(1, $row['mail_attempts']);
        self::assertSame([], $sender->sentMessages);
    }

    public function testParallelFailingWorkersConsumeOneAttemptEachAndRespectScheduleAndBudget(): void
    {
        $repository = $this->repository();
        $ids = [];
        for ($index = 0; $index < 8; $index++) {
            $ids[] = $repository->create('contact', ['name' => 'Ada'], null, 'pending_mail');
        }
        self::assertSame(8, $this->runFailingWorkers());
        self::assertSame(0, $this->runFailingWorkers());
        foreach ($ids as $id) {
            self::assertSame(1, $repository->find($id)['mail_attempts']);
            $this->dueNow($id);
        }
        self::assertSame(8, $this->runFailingWorkers());
        self::assertSame(0, $this->runFailingWorkers());
        foreach ($ids as $id) {
            $row = $repository->find($id);
            self::assertSame(2, $row['mail_attempts']);
            self::assertSame(0, $row['mail_retryable']);
        }
        self::assertCount(16, file($this->root . '/attempts.txt'));
    }

    private function runFailingWorkers(): int
    {
        $script = <<<'PHP'
require $argv[1];
$repository = new \formflow\SqliteSubmissionRepository($argv[2]);
$sender = new class($argv[3]) implements \formflow\MailSenderInterface {
    public function __construct(private string $path) {}
    public function send(string $recipient, string $subject, array $fields): void {
        file_put_contents($this->path, "attempt\n", FILE_APPEND | LOCK_EX);
        throw new \RuntimeException('SMTP unavailable.');
    }
};
echo "ready\n"; fflush(STDOUT);
if (trim((string) fgets(STDIN)) !== 'go') { exit(2); }
$worker = new \formflow\MailDeliveryWorker(['contact' => ['recipient' => 'owner@example.com']], $sender, $repository, new \formflow\MailRetryPolicy(2, 60, 3600));
echo json_encode($worker->process(), JSON_THROW_ON_ERROR) . "\n";
PHP;
        $children = [];
        $attempted = 0;
        try {
            for ($index = 0; $index < 4; $index++) {
                $process = proc_open([PHP_BINARY, '-r', $script, dirname(__DIR__) . '/vendor/autoload.php', $this->root . '/storage/submissions.sqlite', $this->root . '/attempts.txt'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                self::assertIsResource($process);
                $children[] = [$process, $pipes];
                stream_set_timeout($pipes[1], 10);
            }
            foreach ($children as [$process, $pipes]) { self::assertSame("ready\n", fgets($pipes[1])); }
            foreach ($children as [$process, $pipes]) { fwrite($pipes[0], "go\n"); fflush($pipes[0]); }
            foreach ($children as [$process, $pipes]) {
                $result = fgets($pipes[1]);
                self::assertNotFalse($result);
                $attempted += json_decode($result, true, 512, JSON_THROW_ON_ERROR)['attempted'];
            }
        } finally {
            foreach ($children as [$process, $pipes]) {
                fclose($pipes[0]);
                $error = stream_get_contents($pipes[2]);
                fclose($pipes[1]); fclose($pipes[2]);
                self::assertSame(0, proc_close($process), $error);
            }
        }
        return $attempted;
    }
}
