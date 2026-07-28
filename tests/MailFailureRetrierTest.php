<?php

declare(strict_types=1);

namespace formflow\Tests;

use formflow\MailFailureRetrier;
use formflow\SqliteSubmissionRepository;
use formflow\Tests\Fakes\FakeMailSender;
use PHPUnit\Framework\TestCase;

final class MailFailureRetrierTest extends TestCase
{
    public function testRetriesFailedSubmissionEmail(): void
    {
        $repository = new SqliteSubmissionRepository(':memory:');
        $id = $repository->create('contact', ['email' => 'ada@example.com'], null, 'failed');
        $mail = new FakeMailSender();

        $summary = (new MailFailureRetrier([
            'contact' => [
                'recipient' => 'owner@example.com',
                'subject' => 'Contact',
            ],
        ], $mail, $repository))->process();

        $this->assertSame(['attempted' => 1, 'sent' => 1, 'failed' => 0, 'skipped' => 0], $summary);
        $this->assertSame('sent', $repository->find($id)['status']);
        $this->assertSame('owner@example.com', $mail->sentMessages[0]['recipient']);
    }

    public function testLeavesSubmissionFailedWhenRetryFails(): void
    {
        $repository = new SqliteSubmissionRepository(':memory:');
        $id = $repository->create('contact', ['email' => 'ada@example.com'], null, 'failed');
        $mail = new FakeMailSender();
        $mail->shouldThrow = true;

        $summary = (new MailFailureRetrier([
            'contact' => [
                'recipient' => 'owner@example.com',
            ],
        ], $mail, $repository))->process();

        $this->assertSame(['attempted' => 1, 'sent' => 0, 'failed' => 1, 'skipped' => 0], $summary);
        $this->assertSame('failed', $repository->find($id)['status']);
    }
}
