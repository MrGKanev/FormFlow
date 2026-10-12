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

        $this->assertSame(['attempted' => 1, 'sent' => 1, 'failed' => 0, 'pending' => 0, 'skipped' => 0], $summary);
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

        $this->assertSame(['attempted' => 1, 'sent' => 0, 'failed' => 0, 'pending' => 1, 'skipped' => 0], $summary);
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

        $this->assertSame(['attempted' => 1, 'sent' => 0, 'failed' => 1, 'pending' => 0, 'skipped' => 0], $summary);
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

        $this->assertSame(['attempted' => 1, 'sent' => 0, 'failed' => 1, 'pending' => 0, 'skipped' => 0], $summary);
        $this->assertSame('failed', $entry['status']);
        $this->assertSame('Stored webhook payload is invalid.', $entry['error_message']);
        $this->assertSame([], $transport->requests);
    }

    public function testConcurrentWorkerRunsDoNotDispatchTheSameDeliveryTwice(): void
    {
        $deliveries = new SqliteWebhookDeliveryRepository(':memory:');
        $deliveries->enqueue('contact', 'slack', 'https://hooks.slack.test/incoming', ['text' => 'hello']);

        // Simulate a second worker process claiming the row first (e.g. an
        // overlapping cron run), before the first worker gets to dispatch it.
        $id = (int) $deliveries->due()[0]['id'];
        $this->assertTrue($deliveries->claim($id));

        $transport = new FakeWebhookTransport([null]);
        $summary = (new WebhookDeliveryWorker($deliveries, $transport))->process();

        $this->assertSame(['attempted' => 0, 'sent' => 0, 'failed' => 0, 'pending' => 0, 'skipped' => 1], $summary);
        $this->assertSame([], $transport->requests);
        $this->assertSame('pending', $deliveries->deliveryLog()[0]['status']);
    }

    public function testAbandonedClaimCanBeReclaimedAfterItGoesStale(): void
    {
        $deliveries = new SqliteWebhookDeliveryRepository(':memory:');
        $deliveries->enqueue('contact', 'slack', 'https://hooks.slack.test/incoming', ['text' => 'hello']);
        $id = (int) $deliveries->due()[0]['id'];

        $this->assertTrue($deliveries->claim($id));
        $this->assertFalse($deliveries->claim($id));

        // Back-date the claim to simulate a worker that crashed mid-delivery.
        $this->pdo($deliveries)
            ->prepare('UPDATE webhook_deliveries SET locked_at = :locked_at WHERE id = :id')
            ->execute(['locked_at' => gmdate('c', time() - 3600), 'id' => $id]);

        $this->assertTrue($deliveries->claim($id));
    }

    public function testTransportExceptionSchedulesRetryAndContinuesWithOtherDeliveries(): void
    {
        $deliveries = new SqliteWebhookDeliveryRepository(':memory:');
        $deliveries->enqueue('contact', 'slack', 'https://hooks.example.test/first', ['text' => 'first']);
        $deliveries->enqueue('contact', 'slack', 'https://hooks.example.test/second', ['text' => 'second']);
        $transport = new class implements \formflow\WebhookTransportInterface {
            public int $calls = 0;

            public function postJson(string $url, array $payload): ?string
            {
                if (++$this->calls === 1) {
                    throw new \RuntimeException('Transport failed.');
                }

                return null;
            }
        };

        $summary = (new WebhookDeliveryWorker($deliveries, $transport))->process();

        $this->assertSame(['attempted' => 2, 'sent' => 1, 'failed' => 0, 'pending' => 1, 'skipped' => 0], $summary);
        $this->assertSame(2, $transport->calls);
        $entries = array_column($deliveries->deliveryLog(), null, 'url');
        $this->assertSame('pending', $entries['https://hooks.example.test/first']['status']);
        $this->assertSame('Transport failed.', $entries['https://hooks.example.test/first']['error_message']);
        $this->assertSame('sent', $entries['https://hooks.example.test/second']['status']);
    }

    public function testDetailedRetriesRetainIdentityRespectDelayAndReplayGetsNewIdentity(): void
    {
        $deliveries = new SqliteWebhookDeliveryRepository(':memory:');
        $deliveries->enqueue('contact', 'webhook', 'https://hooks.example.test', ['text' => 'hello']);
        $transport = new class implements \formflow\DetailedWebhookTransportInterface {
            /** @var list<string> */
            public array $ids = [];
            public bool $permanent = false;
            public function postJson(string $url, array $payload): ?string { return 'error'; }
            public function deliver(string $url, array $payload, string $deliveryId): \formflow\WebhookTransportResult
            {
                $this->ids[] = $deliveryId;
                return new \formflow\WebhookTransportResult('error', !$this->permanent, 600);
            }
        };
        $worker = new WebhookDeliveryWorker($deliveries, $transport);
        $before = time();
        $worker->process();
        $row = $this->pdo($deliveries)->query('SELECT * FROM webhook_deliveries')->fetch();
        $this->assertGreaterThanOrEqual($before + 600, strtotime($row['next_attempt_at']));
        $this->assertFalse($deliveries->claim((int) $row['id']));
        $deliveries->markQueuedFailed((int) $row['id'], 1, 'error', 0);
        $worker->process();
        $this->assertSame($transport->ids[0], $transport->ids[1]);
        $deliveries->replay((int) $row['id']);
        $transport->permanent = true;
        $summary = $worker->process();
        $this->assertSame(1, $summary['failed']);
        $this->assertNotSame($transport->ids[0], $transport->ids[2]);
        $this->assertSame(1, $deliveries->deliveryLog()[0]['attempts']);
    }

    public function testRetryDelayUsesExponentialEqualJitter(): void
    {
        $worker = new WebhookDeliveryWorker(new SqliteWebhookDeliveryRepository(':memory:'), new FakeWebhookTransport());
        $method = new \ReflectionMethod($worker, 'retryDelay');
        foreach ([1 => [30, 60], 2 => [60, 120], 100 => [1800, 3600]] as $attempt => [$minimum, $maximum]) {
            $delay = $method->invoke($worker, $attempt);
            $this->assertGreaterThanOrEqual($minimum, $delay);
            $this->assertLessThanOrEqual($maximum, $delay);
        }
    }

    public function testStoredTopLevelNumericPayloadFailsWithoutTransport(): void
    {
        $deliveries = new SqliteWebhookDeliveryRepository(':memory:');
        $deliveries->enqueue('contact', 'generic', 'https://hooks.example.test', []);
        $this->pdo($deliveries)->exec("UPDATE webhook_deliveries SET payload_json = '[1,2]'");
        $transport = new FakeWebhookTransport();
        $summary = (new WebhookDeliveryWorker($deliveries, $transport))->process();
        $this->assertSame(1, $summary['failed']);
        $this->assertSame([], $transport->requests);
        $this->assertSame('Stored webhook payload is invalid.', $deliveries->deliveryLog()[0]['error_message']);
    }

    private function pdo(SqliteWebhookDeliveryRepository $repository): \PDO
    {
        return (new \ReflectionProperty($repository, 'pdo'))->getValue($repository);
    }
}
