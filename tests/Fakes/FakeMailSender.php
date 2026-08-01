<?php

declare(strict_types=1);

namespace formflow\Tests\Fakes;

use formflow\MailSenderInterface;
use RuntimeException;

final class FakeMailSender implements MailSenderInterface
{
    /** @var list<array{recipient: string, subject: string, fields: array<string, mixed>}> */
    public array $sentMessages = [];

    public bool $shouldThrow = false;

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
}
