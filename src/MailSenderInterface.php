<?php

declare(strict_types=1);

namespace formflow;

interface MailSenderInterface
{
    /** @param array<string, mixed> $fields */
    public function send(string $recipient, string $subject, array $fields): void;
}
