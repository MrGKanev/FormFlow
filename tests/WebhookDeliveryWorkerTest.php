<?php

declare(strict_types=1);

namespace formflow\Tests;

use formflow\SqliteWebhookDeliveryRepository;
use formflow\Tests\Fakes\FakeWebhookTransport;
use formflow\WebhookDeliveryWorker;
use PHPUnit\Framework\TestCase;

final class WebhookDeliveryWorkerTest extends TestCase
{
    public function testProcessesQueuedWebhookDelivery(): void
    {
        $deliveries = new SqliteWebhookDeliveryRepository(':memory:');
        $deliveries->enqueue('contact', 'slack', 'https://hooks.slack.test/incoming', ['text' => 'hello']);
        $transport = new FakeWebhookTransport([null]);

        $summary = (new WebhookDeliveryWorker($deliveries, $transport))->process();

        $this->assertSame(['attempted' => 1, 'sent' => 1, 'failed' => 0, 'pending' => 0], $summary);
        $this->assertSame('sent', $deliveries->deliveryLog()[0]['status']);
        $this->assertSame(1, $deliveries->deliveryLog()[0]['attempts']);
    }

    public function testKeepsFailedWebhookPendingUntilMaxAttempts(): void
    {
        $deliveries = new SqliteWebhookDeliveryRepository(':memory:');
        $deliveries->enqueue('contact', 'slack', 'https://hooks.slack.test/incoming', ['text' => 'hello']);
        $transport = new FakeWebhookTransport(['HTTP 500.']);

        $summary = (new WebhookDeliveryWorker($deliveries, $transport))->process();
        $entry = $deliveries->deliveryLog()[0];

        $this->assertSame(['attempted' => 1, 'sent' => 0, 'failed' => 0, 'pending' => 1], $summary);
        $this->assertSame('pending', $entry['status']);
        $this->assertSame(1, $entry['attempts']);
        $this->assertSame('HTTP 500.', $entry['error_message']);
    }
}
