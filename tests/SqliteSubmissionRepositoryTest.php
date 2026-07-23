<?php

declare(strict_types=1);

namespace formflow\Tests;

use formflow\SqliteSubmissionRepository;
use PHPUnit\Framework\TestCase;

final class SqliteSubmissionRepositoryTest extends TestCase
{
    public function testCreateStoresSubmissionAndReturnsId(): void
    {
        $repository = new SqliteSubmissionRepository(':memory:');

        $id = $repository->create('contact', ['name' => 'Ada'], 'hash123');

        $row = $repository->find($id);

        $this->assertNotNull($row);
        $this->assertSame('contact', $row['form_id']);
        $this->assertSame('received', $row['status']);
        $this->assertSame(['name' => 'Ada'], json_decode($row['payload'], true));
    }

    public function testMarkSentUpdatesStatusAndSentAt(): void
    {
        $repository = new SqliteSubmissionRepository(':memory:');
        $id = $repository->create('contact', ['name' => 'Ada'], 'hash123');

        $repository->markSent($id);

        $row = $repository->find($id);

        $this->assertSame('sent', $row['status']);
        $this->assertNotNull($row['sent_at']);
    }

    public function testMarkFailedUpdatesStatusAndErrorMessage(): void
    {
        $repository = new SqliteSubmissionRepository(':memory:');
        $id = $repository->create('contact', ['name' => 'Ada'], 'hash123');

        $repository->markFailed($id, 'SMTP down');

        $row = $repository->find($id);

        $this->assertSame('failed', $row['status']);
        $this->assertSame('SMTP down', $row['error_message']);
    }

    public function testCreateAcceptsCustomStatus(): void
    {
        $repository = new SqliteSubmissionRepository(':memory:');

        $id = $repository->create('contact', [], 'hash123', 'blocked_spam');

        $row = $repository->find($id);

        $this->assertSame('blocked_spam', $row['status']);
    }

    public function testFindReturnsNullForMissingId(): void
    {
        $repository = new SqliteSubmissionRepository(':memory:');

        $this->assertNull($repository->find(999));
    }
}
