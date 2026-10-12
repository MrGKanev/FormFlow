<?php

declare(strict_types=1);
namespace formflow\Tests;

use formflow\DeliveryAlertNotifier;
use formflow\DeliveryMonitor;
use formflow\SqliteSubmissionRepository;
use formflow\Tests\Fakes\FakeMailSender;
use PHPUnit\Framework\TestCase;

final class DeliveryAlertNotifierTest extends TestCase
{
    use MonitoringFixture;
    private function snapshot(bool $healthy): array
    {
        $repository = new SqliteSubmissionRepository(':memory:');
        if (!$healthy) { $repository->create('contact', ['email' => 'private@example.com'], null, 'failed'); }
        return (new DeliveryMonitor($repository, null, $this->root))->snapshot();
    }
    public function testAlertsDeduplicateRepeatThenSendOneRecovery(): void
    {
        $sender = new FakeMailSender();
        $notifier = new DeliveryAlertNotifier($sender, $this->root . '/state.json', 60);
        self::assertFalse($notifier->notify($this->snapshot(true), 'owner@example.com', 100));
        self::assertTrue($notifier->notify($this->snapshot(false), 'owner@example.com', 100));
        self::assertFalse($notifier->notify($this->snapshot(false), 'owner@example.com', 159));
        self::assertTrue($notifier->notify($this->snapshot(false), 'owner@example.com', 160));
        self::assertTrue($notifier->notify($this->snapshot(true), 'owner@example.com', 161));
        self::assertFalse($notifier->notify($this->snapshot(true), 'owner@example.com', 162));
        self::assertCount(3, $sender->sentMessages);
        self::assertSame('FormFlow deliveries recovered', $sender->sentMessages[2]['subject']);
        self::assertStringNotContainsString('private', json_encode($sender->sentMessages, JSON_THROW_ON_ERROR));
    }
    public function testFailedSenderDoesNotSuppressNextAttempt(): void
    {
        $sender = new FakeMailSender();
        $sender->shouldThrow = true;
        $notifier = new DeliveryAlertNotifier($sender, $this->root . '/state.json', 3600);
        try {
            $notifier->notify($this->snapshot(false), 'owner@example.com', 100);
            self::fail('Expected SMTP failure.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Simulated SMTP failure.', $exception->getMessage());
        }
        $sender->shouldThrow = false;
        self::assertTrue($notifier->notify($this->snapshot(false), 'owner@example.com', 101));
        self::assertCount(1, $sender->sentMessages);
    }
    public function testChangedAlertsNotifyImmediatelyAndMalformedStateCanRecover(): void
    {
        $sender = new FakeMailSender();
        file_put_contents($this->root . '/state.json', '{broken');
        $notifier = new DeliveryAlertNotifier($sender, $this->root . '/state.json', 3600);
        $status = $this->snapshot(false);
        self::assertTrue($notifier->notify($status, 'owner@example.com', 100));
        $status['alerts'][] = 'webhooks: 2 failed deliveries.';
        self::assertTrue($notifier->notify($status, 'owner@example.com', 101));
        self::assertCount(2, $sender->sentMessages);
    }
    public function testInvalidRecipientRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        (new DeliveryAlertNotifier(new FakeMailSender(), $this->root . '/state.json'))->notify($this->snapshot(false), 'bad-email');
    }

    public function testRecipientChangeAndClockRollbackDoNotSuppressAlerts(): void
    {
        $sender = new FakeMailSender();
        $notifier = new DeliveryAlertNotifier($sender, $this->root . '/state.json', 3600);
        $status = $this->snapshot(false);
        self::assertTrue($notifier->notify($status, 'first@example.com', 100));
        self::assertTrue($notifier->notify($status, 'second@example.com', 101));
        self::assertFalse($notifier->notify($status, 'second@example.com', 102));
        self::assertTrue($notifier->notify($status, 'second@example.com', 50));
        self::assertCount(3, $sender->sentMessages);
    }

    public function testConcurrentChecksSendOnlyOneAlert(): void
    {
        $statePath = $this->root . '/state.json';
        $sentPath = $this->root . '/sent.txt';
        $lock = fopen($statePath, 'c+');
        self::assertIsResource($lock);
        self::assertTrue(flock($lock, LOCK_EX));
        $script = <<<'PHP'
require $argv[1];
$sender = new class($argv[3]) implements \formflow\MailSenderInterface {
    public function __construct(private string $path) {}
    public function send(string $recipient, string $subject, array $fields): void {
        file_put_contents($this->path, $subject . "\n", FILE_APPEND | LOCK_EX);
    }
};
echo "ready\n";
(new \formflow\DeliveryAlertNotifier($sender, $argv[2], 3600))->notify(json_decode($argv[4], true, 512, JSON_THROW_ON_ERROR), 'owner@example.com', 100);
PHP;
        $children = [];
        try {
            for ($index = 0; $index < 2; $index++) {
                $process = proc_open([PHP_BINARY, '-r', $script, dirname(__DIR__) . '/vendor/autoload.php', $statePath, $sentPath, json_encode($this->snapshot(false), JSON_THROW_ON_ERROR)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                self::assertIsResource($process);
                $children[] = [$process, $pipes];
                stream_set_timeout($pipes[1], 5);
                self::assertSame("ready\n", fgets($pipes[1]));
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        foreach ($children as [$process, $pipes]) {
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $error);
        }
        self::assertSame("FormFlow delivery alert\n", file_get_contents($sentPath));
    }
}
