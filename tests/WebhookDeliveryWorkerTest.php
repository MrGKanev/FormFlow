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

    public function testMarksWebhookAsFailedOnThirdUnsuccessfulAttempt(): void
    {
        $deliveries = new SqliteWebhookDeliveryRepository(':memory:');
        $deliveries->enqueue('contact', 'slack', 'https://hooks.slack.test/incoming', ['text' => 'hello']);
        $id = $deliveries->due()[0]['id'];
        $deliveries->markQueuedFailed($id, 2, 'Previous error.', 0);
        $transport = new FakeWebhookTransport(['HTTP 500.']);

        $summary = (new WebhookDeliveryWorker($deliveries, $transport))->process();
        $entry = $deliveries->deliveryLog()[0];

        $this->assertSame(['attempted' => 1, 'sent' => 0, 'failed' => 1, 'pending' => 0], $summary);
        $this->assertSame('failed', $entry['status']);
        $this->assertSame(3, $entry['attempts']);
        $this->assertSame('HTTP 500.', $entry['error_message']);
    }

    public function testInvalidStoredPayloadFailsWithoutCallingTransport(): void
    {
        $deliveries = new SqliteWebhookDeliveryRepository(':memory:');
        $deliveries->enqueue('contact', 'slack', 'https://hooks.slack.test/incoming', ['text' => 'hello']);
        $id = $deliveries->due()[0]['id'];
        $pdo = $this->pdo($deliveries);
        $pdo->prepare('UPDATE webhook_deliveries SET payload_json = :payload WHERE id = :id')
            ->execute(['payload' => '{invalid json', 'id' => $id]);
        $transport = new FakeWebhookTransport();

        $summary = (new WebhookDeliveryWorker($deliveries, $transport))->process();
        $entry = $deliveries->deliveryLog()[0];

        $this->assertSame(['attempted' => 1, 'sent' => 0, 'failed' => 1, 'pending' => 0], $summary);
        $this->assertSame('failed', $entry['status']);
        $this->assertSame('Stored webhook payload is invalid.', $entry['error_message']);
        $this->assertSame([], $transport->requests);
    }

    private function pdo(SqliteWebhookDeliveryRepository $repository): \PDO
    {
        return (new \ReflectionProperty($repository, 'pdo'))->getValue($repository);
    }
}
