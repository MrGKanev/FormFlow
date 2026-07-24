<?php

declare(strict_types=1);

namespace formflow\Tests;

use formflow\SqliteAdminWhitelistRepository;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SqliteAdminWhitelistRepositoryTest extends TestCase
{
    public function testAddAndListReturnsEntry(): void
    {
        $repository = new SqliteAdminWhitelistRepository(':memory:');

        $repository->add('203.0.113.10', 'office IP');

        $entries = $repository->list();

        $this->assertCount(1, $entries);
        $this->assertSame('203.0.113.10', $entries[0]['ip_or_cidr']);
        $this->assertSame('office IP', $entries[0]['note']);
    }

    public function testAddWithoutNoteStoresNull(): void
    {
        $repository = new SqliteAdminWhitelistRepository(':memory:');

        $repository->add('203.0.113.11', null);

        $entries = $repository->list();

        $this->assertNull($entries[0]['note']);
    }

    public function testRemoveDeletesEntry(): void
    {
        $repository = new SqliteAdminWhitelistRepository(':memory:');
        $repository->add('203.0.113.10', null);

        $entries = $repository->list();
        $repository->remove((int) $entries[0]['id']);

        $this->assertSame([], $repository->list());
    }

    public function testDuplicateIpIsRejected(): void
    {
        $repository = new SqliteAdminWhitelistRepository(':memory:');
        $repository->add('203.0.113.10', null);

        $this->expectException(InvalidArgumentException::class);

        $repository->add('203.0.113.10', 'duplicate attempt');
    }

    public function testListReturnsNewestFirst(): void
    {
        $repository = new SqliteAdminWhitelistRepository(':memory:');
        $repository->add('203.0.113.10', 'first');
        $repository->add('203.0.113.11', 'second');

        $entries = $repository->list();

        $this->assertSame('203.0.113.11', $entries[0]['ip_or_cidr']);
        $this->assertSame('203.0.113.10', $entries[1]['ip_or_cidr']);
    }
}
