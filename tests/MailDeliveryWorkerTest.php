<?php

declare(strict_types=1);

namespace formflow\Tests;

use formflow\MailDeliveryWorker;
use formflow\SqliteSubmissionRepository;
use formflow\Tests\Fakes\FakeMailSender;
use PHPUnit\Framework\TestCase;

final class MailDeliveryWorkerTest extends TestCase
{
    public function testSendsPendingMailSubmission(): void
    {
        $repository = new SqliteSubmissionRepository(':memory:');
        $id = $repository->create('contact', ['email' => 'ada@example.com'], null, 'pending_mail');
        $mail = new FakeMailSender();

        $summary = (new MailDeliveryWorker([
            'contact' => [
                'recipient' => 'owner@example.com',
                'subject' => 'Contact',
            ],
        ], $mail, $repository))->process();

        $this->assertSame(['attempted' => 1, 'sent' => 1, 'failed' => 0, 'skipped' => 0], $summary);
        $this->assertSame('sent', $repository->find($id)['status']);
        $this->assertSame('owner@example.com', $mail->sentMessages[0]['recipient']);
    }

    public function testRetryFailedRetriesFailedSubmissionEmail(): void
    {
        $repository = new SqliteSubmissionRepository(':memory:');
        $id = $repository->create('contact', ['email' => 'ada@example.com'], null, 'failed');
        $mail = new FakeMailSender();

        $summary = (new MailDeliveryWorker([
            'contact' => [
                'recipient' => 'owner@example.com',
                'subject' => 'Contact',
            ],
        ], $mail, $repository))->retryFailed();

        $this->assertSame(['attempted' => 1, 'sent' => 1, 'failed' => 0, 'skipped' => 0], $summary);
        $this->assertSame('sent', $repository->find($id)['status']);
        $this->assertSame('owner@example.com', $mail->sentMessages[0]['recipient']);
    }

    public function testRetryFailedLeavesSubmissionFailedWhenRetryFails(): void
    {
        $repository = new SqliteSubmissionRepository(':memory:');
        $id = $repository->create('contact', ['email' => 'ada@example.com'], null, 'failed');
        $mail = new FakeMailSender();
        $mail->shouldThrow = true;

        $summary = (new MailDeliveryWorker([
            'contact' => [
                'recipient' => 'owner@example.com',
            ],
        ], $mail, $repository))->retryFailed();

        $this->assertSame(['attempted' => 1, 'sent' => 0, 'failed' => 1, 'skipped' => 0], $summary);
        $this->assertSame('failed', $repository->find($id)['status']);
    }

    public function testRetryFailedDoesNotPickUpFreshPendingMail(): void
    {
        $repository = new SqliteSubmissionRepository(':memory:');
        $repository->create('contact', ['email' => 'ada@example.com'], null, 'pending_mail');
        $mail = new FakeMailSender();

        $summary = (new MailDeliveryWorker([
            'contact' => ['recipient' => 'owner@example.com'],
        ], $mail, $repository))->retryFailed();

        $this->assertSame(['attempted' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0], $summary);
    }

    public function testSkipsPendingSubmissionWhenItsFormNoLongerExists(): void
    {
        $repository = new SqliteSubmissionRepository(':memory:');
        $id = $repository->create('retired-form', ['email' => 'ada@example.com'], null, 'pending_mail');
        $mail = new FakeMailSender();

        $summary = (new MailDeliveryWorker([], $mail, $repository))->process();

        $this->assertSame(['attempted' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 1], $summary);
        $this->assertSame('pending_mail', $repository->find($id)['status']);
        $this->assertSame([], $mail->sentMessages);
    }

    public function testQueuedDeliveryAlsoSendsConfiguredAutoReply(): void
    {
        $repository = new SqliteSubmissionRepository(':memory:');
        $repository->create('contact', ['email' => 'ada@example.com', 'name' => 'Ada'], null, 'pending_mail');
        $mail = new FakeMailSender();

        (new MailDeliveryWorker([
            'contact' => [
                'recipient' => 'owner@example.com',
                'auto_reply' => [
                    'enabled' => true,
                    'subject' => 'Hi {{name}}',
                    'body' => 'Message received.',
                ],
            ],
        ], $mail, $repository))->process();

        $this->assertCount(1, $mail->autoReplies);
        $this->assertSame('Hi Ada', $mail->autoReplies[0]['subject']);
    }
}
