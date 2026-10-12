<?php

declare(strict_types=1);

namespace formflow\Tests;

use formflow\CurlWebhookNotifier;
use formflow\SqliteWebhookDeliveryRepository;
use formflow\Tests\Fakes\FakeWebhookTransport;
use PHPUnit\Framework\TestCase;

final class CurlWebhookNotifierTest extends TestCase
{
    public function testSynchronousDetailedTransportRetainsIdAndStopsOnReceiverDelay(): void
    {
        $repository = new \formflow\SqliteWebhookDeliveryRepository(':memory:');
        $transport = new class implements \formflow\DetailedWebhookTransportInterface {
            /** @var list<string> */
            public array $ids = [];
            public bool $retryable = true;
            public ?int $delay = null;
            public function postJson(string $url, array $payload): ?string { return 'error'; }
            public function deliver(string $url, array $payload, string $deliveryId): \formflow\WebhookTransportResult
            {
                $this->ids[] = $deliveryId;
                return new \formflow\WebhookTransportResult('error', $this->retryable, $this->delay);
            }
        };
        $notifier = new \formflow\CurlWebhookNotifier(null, 'https://hooks.example.test', null, null, null, $repository, $transport);
        $notifier->notify('contact', [], ['slack']);
        $this->assertCount(2, $transport->ids);
        $this->assertSame($transport->ids[0], $transport->ids[1]);
        $transport->delay = 600;
        $notifier->notify('contact', [], ['slack']);
        $this->assertCount(3, $transport->ids);
        $this->assertSame(1, $repository->deliveryLog()[0]['attempts']);
        $transport->delay = null;
        $transport->retryable = false;
        $notifier->notify('contact', [], ['slack']);
        $this->assertCount(4, $transport->ids);
    }

    public function testSendsOnlyChannelsSelectedByTheForm(): void
    {
        $transport = new FakeWebhookTransport();
        $deliveries = new SqliteWebhookDeliveryRepository(':memory:');
        $notifier = $this->notifier($deliveries, $transport);

        $notifier->notify('contact', ['email' => 'ada@example.com'], ['slack', 'generic']);

        $this->assertCount(2, $transport->requests);
        $this->assertSame('https://hooks.slack.test/incoming', $transport->requests[0]['url']);
        $this->assertStringContainsString('Email: ada@example.com', $transport->requests[0]['payload']['text']);
        $this->assertSame('https://hooks.example.test/formflow', $transport->requests[1]['url']);
        $this->assertSame([
            'form_id' => 'contact',
            'fields' => ['email' => 'ada@example.com'],
        ], $transport->requests[1]['payload']);
        $this->assertSame(['generic', 'slack'], array_column($deliveries->deliveryLog(), 'channel'));
    }

    public function testNoSelectedDeliveryChannelsSendsNothing(): void
    {
        $transport = new FakeWebhookTransport();
        $notifier = $this->notifier(new SqliteWebhookDeliveryRepository(':memory:'), $transport);

        $notifier->notify('contact', ['name' => 'Ada']);

        $this->assertSame([], $transport->requests);
    }

    public function testPerFormOverridesReplaceGlobalEndpoints(): void
    {
        $transport = new FakeWebhookTransport();
        $notifier = $this->notifier(new SqliteWebhookDeliveryRepository(':memory:'), $transport);

        $notifier->notify('contact', ['email' => 'ada@example.com'], ['slack'], [
            'slack_webhook_url' => 'https://hooks.slack.test/form-specific',
        ]);

        $this->assertCount(1, $transport->requests);
        $this->assertSame('https://hooks.slack.test/form-specific', $transport->requests[0]['url']);
    }

    public function testRetriesAndRecordsTheAttemptThatSucceeds(): void
    {
        $transport = new FakeWebhookTransport(['Temporary failure.', null]);
        $deliveries = new SqliteWebhookDeliveryRepository(':memory:');
        $notifier = new CurlWebhookNotifier(
            null,
            'https://hooks.slack.test/incoming',
            null,
            null,
            null,
            $deliveries,
            $transport
        );

        $notifier->notify('contact', ['name' => 'Ada'], ['slack']);

        $this->assertCount(2, $transport->requests);
        $entry = $deliveries->deliveryLog()[0];
        $this->assertSame('sent', $entry['status']);
        $this->assertSame(2, $entry['attempts']);
        $this->assertNull($entry['error_message']);
    }

    public function testRecordsFailedDeliveryAfterMaxAttempts(): void
    {
        $transport = new FakeWebhookTransport(['HTTP 500.', 'HTTP 500.']);
        $deliveries = new SqliteWebhookDeliveryRepository(':memory:');
        $notifier = new CurlWebhookNotifier(
            'https://discord.test/webhook',
            null,
            null,
            null,
            null,
            $deliveries,
            $transport
        );

        $notifier->notify('contact', ['name' => 'Ada'], ['discord']);

        $this->assertCount(2, $transport->requests);
        $entry = $deliveries->deliveryLog()[0];
        $this->assertSame('failed', $entry['status']);
        $this->assertSame(2, $entry['attempts']);
        $this->assertSame('HTTP 500.', $entry['error_message']);
    }

    public function testQueueModeStoresDeliveryWithoutCallingTransport(): void
    {
        $transport = new FakeWebhookTransport();
        $deliveries = new SqliteWebhookDeliveryRepository(':memory:');
        $notifier = new CurlWebhookNotifier(
            null,
            'https://hooks.slack.test/incoming',
            null,
            null,
            null,
            $deliveries,
            $transport,
            true
        );

        $notifier->notify('contact', ['name' => 'Ada'], ['slack']);

        $this->assertCount(0, $transport->requests);
        $this->assertCount(1, $deliveries->due());
        $this->assertSame('pending', $deliveries->deliveryLog()[0]['status']);
    }

    private function notifier(
        SqliteWebhookDeliveryRepository $deliveries,
        FakeWebhookTransport $transport
    ): CurlWebhookNotifier {
        return new CurlWebhookNotifier(
            'https://discord.test/webhook',
            'https://hooks.slack.test/incoming',
            'https://hooks.example.test/formflow',
            'bot-token',
            'chat-id',
            $deliveries,
            $transport
        );
    }
}
