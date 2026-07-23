<?php

declare(strict_types=1);

namespace formflow;

interface MailSenderInterface
{
    /** @param array<string, string> $fields */
    public function send(string $recipient, string $subject, array $fields): void;
}
