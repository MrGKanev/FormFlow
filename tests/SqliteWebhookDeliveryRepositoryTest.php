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
}
