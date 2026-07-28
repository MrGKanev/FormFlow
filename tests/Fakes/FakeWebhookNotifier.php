<?php

declare(strict_types=1);

namespace formflow\Tests\Fakes;

use formflow\WebhookNotifierInterface;

final class FakeWebhookNotifier implements WebhookNotifierInterface
{
    /** @var list<array{form_id: string, fields: array<string, string>, channels: list<string>|null, overrides: array<string, string>}> */
    public array $notifications = [];

    public function notify(string $formId, array $fields, ?array $channels = null, array $overrides = []): void
    {
        $this->notifications[] = [
            'form_id' => $formId,
            'fields' => $fields,
            'channels' => $channels,
            'overrides' => $overrides,
        ];
    }
}
