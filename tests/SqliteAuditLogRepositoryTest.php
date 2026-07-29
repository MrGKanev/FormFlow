<?php

declare(strict_types=1);

namespace formflow\Tests;

use formflow\SqliteAuditLogRepository;
use PHPUnit\Framework\TestCase;

final class SqliteAuditLogRepositoryTest extends TestCase
{
    public function testRecordAndListReturnsEntry(): void
    {
        $repository = new SqliteAuditLogRepository(':memory:');

        $repository->record('ada', 'login', 'Logged in successfully.');

        $entries = $repository->list();

        $this->assertCount(1, $entries);
        $this->assertSame('ada', $entries[0]['username']);
        $this->assertSame('login', $entries[0]['action']);
        $this->assertSame('Logged in successfully.', $entries[0]['detail']);
        $this->assertNotEmpty($entries[0]['created_at']);
    }

    public function testRecordWithoutUsernameStoresNull(): void
    {
        $repository = new SqliteAuditLogRepository(':memory:');

        $repository->record(null, 'system.event', 'Automated action.');

        $this->assertNull($repository->list()[0]['username']);
    }

    public function testRecordTruncatesDetailTo1000Characters(): void
    {
        $repository = new SqliteAuditLogRepository(':memory:');

        $repository->record('ada', 'note', str_repeat('x', 2000));

        $this->assertSame(1000, mb_strlen($repository->list()[0]['detail']));
    }

    public function testListReturnsNewestFirst(): void
    {
        $repository = new SqliteAuditLogRepository(':memory:');
        $repository->record('ada', 'first', 'first entry');
        $repository->record('ada', 'second', 'second entry');
        $repository->record('ada', 'third', 'third entry');

        $actions = array_column($repository->list(), 'action');

        $this->assertSame(['third', 'second', 'first'], $actions);
    }

    public function testListRespectsLimit(): void
    {
        $repository = new SqliteAuditLogRepository(':memory:');
        $repository->record('ada', 'one', 'one');
        $repository->record('ada', 'two', 'two');
        $repository->record('ada', 'three', 'three');

        $entries = $repository->list(2);

        $this->assertCount(2, $entries);
        $this->assertSame(['three', 'two'], array_column($entries, 'action'));
    }
}
