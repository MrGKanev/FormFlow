<?php

declare(strict_types=1);

namespace formflow;

interface WebhookNotifierInterface
{
    /** @param array<string, string> $fields */
    public function notify(string $formId, array $fields): void;
}
