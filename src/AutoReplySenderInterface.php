<?php

declare(strict_types=1);

namespace formflow;

interface AutoReplySenderInterface
{
    public function sendAutoReply(string $recipient, string $subject, string $body): void;
}
