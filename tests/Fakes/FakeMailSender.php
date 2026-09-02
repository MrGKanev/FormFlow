<?php

declare(strict_types=1);

namespace formflow\Tests\Fakes;

use formflow\MailSenderInterface;
use formflow\AutoReplySenderInterface;
use RuntimeException;

final class FakeMailSender implements MailSenderInterface, AutoReplySenderInterface
{
    /** @var list<array{recipient: string, subject: string, fields: array<string, mixed>}> */
    public array $sentMessages = [];

    public bool $shouldThrow = false;

    /** @var list<array{recipient: string, subject: string, body: string}> */
    public array $autoReplies = [];

    public function send(string $recipient, string $subject, array $fields): void
    {
        if ($this->shouldThrow) {
            throw new RuntimeException('Simulated SMTP failure.');
        }

        $this->sentMessages[] = [
            'recipient' => $recipient,
            'subject' => $subject,
            'fields' => $fields,
        ];
    }

    public function sendAutoReply(string $recipient, string $subject, string $body): void
    {
        $this->autoReplies[] = compact('recipient', 'subject', 'body');
    }
}
