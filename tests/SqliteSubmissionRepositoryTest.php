<?php

declare(strict_types=1);

namespace formflow\Tests;

use PDO;
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

    public function testFindPaginatedReturnsMostRecentFirst(): void
    {
        $repository = new SqliteSubmissionRepository(':memory:');

        $repository->create('contact', ['n' => '1'], null);
        $repository->create('contact', ['n' => '2'], null);
        $repository->create('contact', ['n' => '3'], null);

        $rows = $repository->findPaginated(null, null, 1, 2);

        $this->assertCount(2, $rows);
        $this->assertSame(3, $rows[0]['id']);
        $this->assertSame(2, $rows[1]['id']);
    }

    public function testFindPaginatedSecondPageReturnsRemainder(): void
    {
        $repository = new SqliteSubmissionRepository(':memory:');

        $repository->create('contact', ['n' => '1'], null);
        $repository->create('contact', ['n' => '2'], null);
        $repository->create('contact', ['n' => '3'], null);

        $rows = $repository->findPaginated(null, null, 2, 2);

        $this->assertCount(1, $rows);
        $this->assertSame(1, $rows[0]['id']);
    }

    public function testFindPaginatedFiltersByFormId(): void
    {
        $repository = new SqliteSubmissionRepository(':memory:');

        $repository->create('contact', [], null);
        $repository->create('support', [], null);

        $rows = $repository->findPaginated('support', null, 1, 10);

        $this->assertCount(1, $rows);
        $this->assertSame('support', $rows[0]['form_id']);
    }

    public function testFindPaginatedFiltersByStatus(): void
    {
        $repository = new SqliteSubmissionRepository(':memory:');

        $repository->create('contact', [], null, 'blocked_spam');
        $repository->create('contact', [], null, 'received');

        $rows = $repository->findPaginated(null, 'blocked_spam', 1, 10);

        $this->assertCount(1, $rows);
        $this->assertSame('blocked_spam', $rows[0]['status']);
    }

    public function testCountRespectsFilters(): void
    {
        $repository = new SqliteSubmissionRepository(':memory:');

        $repository->create('contact', [], null, 'received');
        $repository->create('contact', [], null, 'blocked_spam');
        $repository->create('support', [], null, 'received');

        $this->assertSame(3, $repository->count(null, null));
        $this->assertSame(2, $repository->count('contact', null));
        $this->assertSame(1, $repository->count(null, 'blocked_spam'));
    }

    public function testSearchMatchesSubstringInsidePayloadWord(): void
    {
        $repository = new SqliteSubmissionRepository(':memory:');
        $repository->create('contact', ['email' => 'ada@example.com'], null);
        $repository->create('contact', ['email' => 'bob@other.test'], null);

        $rows = $repository->findPaginated(null, null, 1, 10, search: 'xamp');

        $this->assertCount(1, $rows);
        $this->assertStringContainsString('ada@example.com', $rows[0]['payload']);
    }

    public function testSearchMatchesFormIdAndStatus(): void
    {
        $repository = new SqliteSubmissionRepository(':memory:');
        $repository->create('newsletter', [], null, 'received');
        $repository->create('contact', [], null, 'blocked_spam');

        $this->assertCount(1, $repository->findPaginated(null, null, 1, 10, search: 'newsletter'));
        $this->assertCount(1, $repository->findPaginated(null, null, 1, 10, search: 'blocked_spam'));
    }

    public function testSearchReflectsErrorMessageAfterMarkFailed(): void
    {
        $repository = new SqliteSubmissionRepository(':memory:');
        $id = $repository->create('contact', [], null);

        $this->assertCount(0, $repository->findPaginated(null, null, 1, 10, search: 'SMTP down'));

        $repository->markFailed($id, 'SMTP down');

        $this->assertCount(1, $repository->findPaginated(null, null, 1, 10, search: 'SMTP down'));
    }

    public function testSearchExcludesDeletedSubmissions(): void
    {
        $repository = new SqliteSubmissionRepository(':memory:');
        $id = $repository->create('contact', ['name' => 'Ada Lovelace'], null);

        $repository->delete($id);

        $this->assertCount(0, $repository->findPaginated(null, null, 1, 10, search: 'Lovelace'));
    }

    public function testDeleteRemovesStoredUploadFiles(): void
    {
        $directory = sys_get_temp_dir() . '/formflow-repository-upload-' . bin2hex(random_bytes(6));
        mkdir($directory);
        $path = $directory . '/stored-file.pdf';
        file_put_contents($path, 'pdf');
        $repository = new SqliteSubmissionRepository(':memory:', $directory);
        $id = $repository->create('contact', [
            'attachment' => [
                'type' => 'upload',
                'original_name' => 'document.pdf',
                'stored_name' => 'stored-file.pdf',
            ],
        ], null);

        try {
            $repository->delete($id);

            $this->assertFileDoesNotExist($path);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }

            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

    public function testDeleteOlderThanRemovesStoredUploadFiles(): void
    {
        $directory = sys_get_temp_dir() . '/formflow-repository-upload-' . bin2hex(random_bytes(6));
        mkdir($directory);
        $databasePath = $directory . '/submissions.sqlite';
        $uploadDirectory = $directory . '/uploads';
        mkdir($uploadDirectory);
        $uploadPath = $uploadDirectory . '/stored-file.pdf';
        file_put_contents($uploadPath, 'pdf');
        $repository = new SqliteSubmissionRepository($databasePath, $uploadDirectory);
        $id = $repository->create('contact', [
            'attachment' => [
                'type' => 'upload',
                'original_name' => 'document.pdf',
                'stored_name' => 'stored-file.pdf',
            ],
        ], null);
        $pdo = new PDO('sqlite:' . $databasePath);
        $statement = $pdo->prepare('UPDATE submissions SET created_at = :created_at WHERE id = :id');
        $statement->execute(['created_at' => gmdate('c', time() - (200 * 86400)), 'id' => $id]);

        try {
            $this->assertSame(1, $repository->deleteOlderThan(180));
            $this->assertFileDoesNotExist($uploadPath);
        } finally {
            foreach (glob($uploadDirectory . '/*') ?: [] as $file) {
                unlink($file);
            }

            if (is_dir($uploadDirectory)) {
                rmdir($uploadDirectory);
            }

            foreach (glob($directory . '/*') ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }

            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }
}
