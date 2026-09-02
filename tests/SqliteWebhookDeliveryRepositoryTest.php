<?php

declare(strict_types=1);

namespace formflow\Tests;

use formflow\SqliteWebhookDeliveryRepository;
use PHPUnit\Framework\TestCase;

final class SqliteWebhookDeliveryRepositoryTest extends TestCase
{
    public function testRecordsDeliveryResults(): void
    {
        $repository = new SqliteWebhookDeliveryRepository(':memory:');

        $repository->record('contact', 'slack', 'sent', 2);
        $repository->record('contact', 'discord', 'failed', 3, 'Webhook returned HTTP 500.');

        $entries = $repository->deliveryLog();

        $this->assertCount(2, $entries);
        $this->assertSame('discord', $entries[0]['channel']);
        $this->assertSame('failed', $entries[0]['status']);
        $this->assertSame(3, $entries[0]['attempts']);
        $this->assertSame('Webhook returned HTTP 500.', $entries[0]['error_message']);
        $this->assertSame('slack', $entries[1]['channel']);
        $this->assertSame('sent', $entries[1]['status']);
        $this->assertNotEmpty($entries[1]['sent_at']);
    }

    public function testQueuesWebhookDeliveryForWorker(): void
    {
        $repository = new SqliteWebhookDeliveryRepository(':memory:');

        $repository->enqueue('contact', 'generic', 'https://example.test/hook', [
            'form_id' => 'contact',
            'fields' => ['email' => 'ada@example.com'],
        ]);

        $due = $repository->due();

        $this->assertCount(1, $due);
        $this->assertSame('pending', $due[0]['status']);
        $this->assertSame(0, $due[0]['attempts']);
        $this->assertSame('https://example.test/hook', $due[0]['url']);
        $this->assertJson((string) $due[0]['payload_json']);
    }

    public function testTemporaryFailureDefersDeliveryUntilItsRetryTime(): void
    {
        $repository = new SqliteWebhookDeliveryRepository(':memory:');
        $repository->enqueue('contact', 'generic', 'https://example.test/hook', ['form_id' => 'contact']);
        $id = $repository->due()[0]['id'];

        $repository->markQueuedFailed($id, 1, 'Temporary failure.', 60);

        $this->assertSame([], $repository->due());
        $entry = $repository->deliveryLog()[0];
        $this->assertSame('pending', $entry['status']);
        $this->assertSame(1, $entry['attempts']);
        $this->assertSame('Temporary failure.', $entry['error_message']);
        $this->assertSame(1, $repository->countByStatus('pending'));
    }

    public function testReplayCreatesANewPendingDeliveryAndPreservesOriginal(): void
    {
        $repository = new SqliteWebhookDeliveryRepository(':memory:');
        $repository->record(
            'contact',
            'generic',
            'failed',
            2,
            'HTTP 500',
            'https://example.test/hook',
            ['form_id' => 'contact']
        );
        $originalId = (int) $repository->deliveryLog()[0]['id'];

        $replayId = $repository->replay($originalId);

        $this->assertNotNull($replayId);
        $this->assertNotSame($originalId, $replayId);
        $this->assertCount(2, $repository->deliveryLog());
        $this->assertSame('pending', $repository->deliveryLog()[0]['status']);
        $this->assertSame('failed', $repository->deliveryLog()[1]['status']);
        $this->assertSame('https://example.test/hook', $repository->due()[0]['url']);
    }

    public function testLegacyDeliveryWithoutPayloadCannotBeReplayed(): void
    {
        $repository = new SqliteWebhookDeliveryRepository(':memory:');
        $repository->record('contact', 'slack', 'sent', 1);

        $this->assertNull($repository->replay((int) $repository->deliveryLog()[0]['id']));
    }
}
