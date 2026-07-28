<?php

declare(strict_types=1);

namespace formflow;

interface WebhookNotifierInterface
{
    /** @param array<string, string> $fields */
    /** @param list<string>|null $channels Null sends to every configured integration. */
    /** @param array<string, string> $overrides Per-form endpoint settings that override global integration settings. */
    public function notify(string $formId, array $fields, ?array $channels = null, array $overrides = []): void;
}
