<?php

declare(strict_types=1);

namespace formflow\Tests\Admin;

use formflow\Admin\AdminSubmissionService;
use formflow\SqliteSubmissionRepository;
use formflow\SubmissionRepositoryInterface;
use RuntimeException;
use formflow\Tests\Fakes\FakeMailSender;
use PHPUnit\Framework\TestCase;

final class AdminSubmissionServiceTest extends TestCase
{
    private function service(SqliteSubmissionRepository $repository, FakeMailSender $sender): AdminSubmissionService
    {
        return new AdminSubmissionService($repository, $sender, ['contact' => ['recipient' => 'hello@example.com']]);
    }

    public function testManualResendCannotStealLiveWorkerClaim(): void
    {
        $repository = new SqliteSubmissionRepository(':memory:');
        $id = $repository->create('contact', ['name' => 'Ada'], null, 'pending_mail');
        $staleSnapshot = $repository->find($id);
        $this->assertNotNull($repository->claimMail($id, 'pending_mail'));
        $sender = new FakeMailSender();

        $this->assertNotNull($this->service($repository, $sender)->resend($staleSnapshot));
        $this->assertSame([], $sender->sentMessages);
        $this->assertNotNull($repository->find($id)['locked_at']);
        $this->assertSame(1, $repository->find($id)['mail_attempts']);
    }

    public function testManualResendRestartsExhaustedBudgetAndSchedulesFailure(): void
    {
        $repository = new SqliteSubmissionRepository(':memory:');
        $id = $repository->create('contact', ['name' => 'Ada'], null, 'pending_mail');
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->assertNotNull($repository->claimMail($id, $attempt === 0 ? 'pending_mail' : 'failed'));
            $repository->markMailFailed($id, 'SMTP unavailable', $attempt === 4 ? null : 0);
        }
        $sender = new FakeMailSender();
        $sender->shouldThrow = true;

        $this->assertStringContainsString('SMTP failure', $this->service($repository, $sender)->resend($repository->find($id)));
        $row = $repository->find($id);
        $this->assertSame(1, $row['mail_attempts']);
        $this->assertSame(1, $row['mail_retryable']);
        $this->assertNotNull($row['mail_next_attempt_at']);
        $this->assertNull($row['locked_at']);
        $this->assertSame([], $repository->findDueMail());
    }

    public function testManualFailureIsTerminalWhenRetryPolicyAllowsOnlyOneAttempt(): void
    {
        $previous = getenv('MAIL_MAX_ATTEMPTS');
        putenv('MAIL_MAX_ATTEMPTS=1');
        try {
            $repository = new SqliteSubmissionRepository(':memory:');
            $id = $repository->create('contact', ['name' => 'Ada'], null, 'failed');
            $sender = new FakeMailSender();
            $sender->shouldThrow = true;

            $this->assertNotNull($this->service($repository, $sender)->resend($repository->find($id)));
            $row = $repository->find($id);
            $this->assertSame(1, $row['mail_attempts']);
            $this->assertSame(0, $row['mail_retryable']);
            $this->assertNull($row['mail_next_attempt_at']);
            $this->assertSame([], $repository->findDueMail());
        } finally {
            putenv($previous === false ? 'MAIL_MAX_ATTEMPTS' : 'MAIL_MAX_ATTEMPTS=' . $previous);
        }
    }

    public function testSentSubmissionCanBeDeliberatelyResent(): void
    {
        $repository = new SqliteSubmissionRepository(':memory:');
        $id = $repository->create('contact', ['name' => 'Ada'], null, 'sent');
        $sender = new FakeMailSender();

        $this->assertNull($this->service($repository, $sender)->resend($repository->find($id)));
        $this->assertCount(1, $sender->sentMessages);
        $this->assertSame('sent', $repository->find($id)['status']);
        $this->assertSame(1, $repository->find($id)['mail_attempts']);
        $this->assertNull($repository->find($id)['locked_at']);
    }

    public function testStaleFailedSnapshotDoesNotResendAlreadyDeliveredSubmission(): void
    {
        $repository = new SqliteSubmissionRepository(':memory:');
        $id = $repository->create('contact', ['name' => 'Ada'], null, 'failed');
        $staleSnapshot = $repository->find($id);
        $repository->markSent($id);
        $sender = new FakeMailSender();

        $this->assertNotNull($this->service($repository, $sender)->resend($staleSnapshot));
        $this->assertSame([], $sender->sentMessages);
        $this->assertSame('sent', $repository->find($id)['status']);
    }

    public function testManualResendUsesClaimedPayloadInsteadOfStaleSnapshot(): void
    {
        $repository = new SqliteSubmissionRepository(':memory:');
        $id = $repository->create('contact', ['name' => 'Ada'], null, 'failed');
        $staleSnapshot = $repository->find($id);
        $staleSnapshot['payload'] = '{"name":"Wrong"}';
        $sender = new FakeMailSender();

        $this->assertNull($this->service($repository, $sender)->resend($staleSnapshot));
        $this->assertSame('Ada', $sender->sentMessages[0]['fields']['name']);
    }

    public function testSuccessfulSendIsNotReclassifiedAsFailureWhenBookkeepingFails(): void
    {
        $row = ['id' => 1, 'form_id' => 'contact', 'status' => 'sent', 'payload' => '{"name":"Ada"}'];
        $repository = $this->createMock(SubmissionRepositoryInterface::class);
        $repository->expects($this->once())->method('claim')->with(1, 'sent')->willReturn(true);
        $repository->method('find')->with(1)->willReturn($row);
        $repository->expects($this->once())->method('markSent')->willThrowException(new RuntimeException('Database unavailable'));
        $repository->expects($this->never())->method('markFailed');
        $sender = new FakeMailSender();
        $service = new AdminSubmissionService($repository, $sender, ['contact' => ['recipient' => 'hello@example.com']]);

        try {
            $service->resend($row);
            $this->fail('Bookkeeping failure should be surfaced.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Database unavailable', $exception->getMessage());
            $this->assertCount(1, $sender->sentMessages);
        }
    }

    public function testSelectedIdsRejectsValuesThatCouldTargetUnselectedSubmissions(): void
    {
        $service = new AdminSubmissionService(new SqliteSubmissionRepository(':memory:'), null, []);

        $this->assertSame([1, 2], $service->selectedIds([
            '1', 2, '2', '3junk', [4], true, 5.5, '1e2', 0, -1,
            '999999999999999999999999999999999999',
        ]));
    }
}
