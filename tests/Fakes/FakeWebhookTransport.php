<?php

declare(strict_types=1);

namespace formflow\Tests\Fakes;

use formflow\WebhookTransportInterface;

final class FakeWebhookTransport implements WebhookTransportInterface
{
    /** @var list<array{url: string, payload: array<string, string|array<string, string>}> */
    public array $requests = [];

    /** @param list<string|null> $responses */
    public function __construct(private array $responses = [])
    {
    }

    public function postJson(string $url, array $payload): ?string
    {
        $this->requests[] = ['url' => $url, 'payload' => $payload];

        return array_shift($this->responses);
    }
}
