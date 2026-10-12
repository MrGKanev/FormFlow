<?php

declare(strict_types=1);

namespace formflow\Tests;

use formflow\BackupService;
use PDO;
use PharData;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class BackupServiceTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/formflow-backup-' . bin2hex(random_bytes(8));
        mkdir($this->directory . '/source/storage/uploads', 0700, true);
        mkdir($this->directory . '/source/config', 0700, true);
        file_put_contents($this->directory . '/source/.env', 'SECRET=private');
        file_put_contents($this->directory . '/source/config/forms.php', '<?php return [];');
        file_put_contents($this->directory . '/source/storage/uploads/test.txt', 'uploaded file');
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) {
            $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->directory);
    }

    private function database(): PDO
    {
        $pdo = new PDO('sqlite:' . $this->directory . '/source/storage/submissions.sqlite');
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('CREATE TABLE submissions (id INTEGER PRIMARY KEY, value TEXT)');
        $pdo->exec("INSERT INTO submissions(value) VALUES ('committed in WAL')");
        return $pdo;
    }

    public function testRoundtripIncludesWalDataCredentialsConfigAndUploads(): void
    {
        $pdo = $this->database();
        $service = new BackupService();
        $created = $service->create($this->directory . '/source', $this->directory . '/backup.tar');
        $restored = $service->restore($this->directory . '/backup.tar', $this->directory . '/restored');
        self::assertSame($created, $restored);
        self::assertSame(4, $created['files']);
        self::assertSame('SECRET=private', file_get_contents($this->directory . '/restored/.env'));
        self::assertSame('uploaded file', file_get_contents($this->directory . '/restored/storage/uploads/test.txt'));
        self::assertSame('<?php return [];', file_get_contents($this->directory . '/restored/config/forms.php'));
        $restoredDb = new PDO('sqlite:' . $this->directory . '/restored/storage/submissions.sqlite');
        self::assertSame('committed in WAL', $restoredDb->query('SELECT value FROM submissions')->fetchColumn());
        self::assertSame('ok', $restoredDb->query('PRAGMA integrity_check')->fetchColumn());
        self::assertSame(1, $pdo->query('SELECT count(*) FROM submissions')->fetchColumn());
    }

    public function testTamperedFileRejectedBeforeTargetCreated(): void
    {
        $pdo = $this->database();
        $service = new BackupService();
        $service->create($this->directory . '/source', $this->directory . '/backup.tar');
        $archive = new PharData($this->directory . '/backup.tar');
        $archive['.env'] = 'tampered';
        try {
            $service->restore($this->directory . '/backup.tar', $this->directory . '/restored');
            self::fail('Tampered archive accepted');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('checksum', $error->getMessage());
            self::assertDirectoryDoesNotExist($this->directory . '/restored');
        }
    }

    public function testNonemptyRestoreTargetRejected(): void
    {
        $pdo = $this->database();
        $service = new BackupService();
        $service->create($this->directory . '/source', $this->directory . '/backup.tar');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('empty');
        $service->restore($this->directory . '/backup.tar', $this->directory . '/source');
    }

    public function testExternalSymlinkRejected(): void
    {
        $pdo = $this->database();
        file_put_contents($this->directory . '/outside', 'external secret');
        symlink($this->directory . '/outside', $this->directory . '/source/config/external');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('outside');
        (new BackupService())->create($this->directory . '/source', $this->directory . '/backup.tar');
    }

    public function testUnlistedEntryRejected(): void
    {
        $pdo = $this->database();
        $service = new BackupService();
        $service->create($this->directory . '/source', $this->directory . '/backup.tar');
        $archive = new PharData($this->directory . '/backup.tar');
        $archive['evil.php'] = '<?php';
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unlisted');
        $service->restore($this->directory . '/backup.tar', $this->directory . '/restored');
    }
    public function testTraversalInManifestRejected(): void
    {
        $pdo = $this->database();
        $service = new BackupService();
        $service->create($this->directory . '/source', $this->directory . '/backup.tar');
        $archive = new PharData($this->directory . '/backup.tar');
        $archive['manifest.json'] = json_encode([
            'version' => 1,
            'database' => 'storage/submissions.sqlite',
            'files' => ['../escaped' => ['size' => 0, 'sha256' => hash('sha256', '')]],
        ], JSON_THROW_ON_ERROR);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unsafe');
        $service->restore($this->directory . '/backup.tar', $this->directory . '/restored');
    }

    public function testSymlinkRestoreTargetRejected(): void
    {
        $pdo = $this->database();
        $service = new BackupService();
        $service->create($this->directory . '/source', $this->directory . '/backup.tar');
        mkdir($this->directory . '/empty');
        symlink($this->directory . '/empty', $this->directory . '/target');
        $this->expectException(RuntimeException::class);
        $service->restore($this->directory . '/backup.tar', $this->directory . '/target');
    }

    public function testAbsoluteDatabasePathBecomesPortableOnRestore(): void
    {
        $pdo = $this->database();
        $originalDatabase = $this->directory . '/source/storage/submissions.sqlite';
        file_put_contents($this->directory . '/source/.env', "SECRET=private\nDATABASE_PATH=\"" . $originalDatabase . "\"\n");
        $service = new BackupService();
        $service->create($this->directory . '/source', $this->directory . '/backup.tar', $originalDatabase);
        $service->restore($this->directory . '/backup.tar', $this->directory . '/restored');
        $content = file_get_contents($this->directory . '/restored/.env');
        self::assertIsString($content);
        $variables = (new \Symfony\Component\Dotenv\Dotenv())->parse($content);
        self::assertSame('storage/submissions.sqlite', $variables['DATABASE_PATH']);
        self::assertSame('private', $variables['SECRET']);
    }

    public function testTopLevelDirectorySymlinkRejected(): void
    {
        $pdo = $this->database();
        unlink($this->directory . '/source/config/forms.php');
        rmdir($this->directory . '/source/config');
        mkdir($this->directory . '/outside');
        symlink($this->directory . '/outside', $this->directory . '/source/config');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Directory symlinks');
        (new BackupService())->create($this->directory . '/source', $this->directory . '/backup.tar');
    }

    public function testExternalConfiguredDatabaseIsArchivedAndRestoredInternally(): void
    {
        $external = $this->directory . '/external.sqlite';
        $pdo = new PDO('sqlite:' . $external);
        $pdo->exec('CREATE TABLE example (value TEXT)');
        $pdo->exec("INSERT INTO example VALUES ('external database')");
        file_put_contents($this->directory . '/source/.env', 'DATABASE_PATH="' . $external . '"');
        $service = new BackupService();
        $service->create($this->directory . '/source', $this->directory . '/backup.tar', $external);
        $service->restore($this->directory . '/backup.tar', $this->directory . '/restored');
        $restoredDb = new PDO('sqlite:' . $this->directory . '/restored/storage/submissions.sqlite');
        self::assertSame('external database', $restoredDb->query('SELECT value FROM example')->fetchColumn());
        $environment = file_get_contents($this->directory . '/restored/.env');
        self::assertIsString($environment);
        self::assertSame('storage/submissions.sqlite', (new \Symfony\Component\Dotenv\Dotenv())->parse($environment)['DATABASE_PATH']);
    }

    public function testNonSqliteConfiguredFileRejected(): void
    {
        file_put_contents($this->directory . '/external.txt', 'not a database');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not SQLite');
        (new BackupService())->create($this->directory . '/source', $this->directory . '/backup.tar', $this->directory . '/external.txt');
    }

    public function testMissingReferencedUploadRejectsIncompleteBackup(): void
    {
        $pdo = $this->database();
        $pdo->exec('ALTER TABLE submissions ADD COLUMN payload TEXT');
        $payload = json_encode(['attachment' => ['type' => 'upload', 'stored_name' => 'missing.txt']], JSON_THROW_ON_ERROR);
        $statement = $pdo->prepare('UPDATE submissions SET payload = ?');
        $statement->execute([$payload]);
        try {
            (new BackupService())->create($this->directory . '/source', $this->directory . '/backup.tar');
            self::fail('Missing upload accepted');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('upload missing', $error->getMessage());
            self::assertFileDoesNotExist($this->directory . '/backup.tar');
        }
    }

    public function testRestoreWithoutEnvironmentEscapesSpecialDatabasePath(): void
    {
        $pdo = $this->database();
        unset($pdo);
        unlink($this->directory . '/source/.env');
        $database = 'storage/data$backup".sqlite';
        rename($this->directory . '/source/storage/submissions.sqlite', $this->directory . '/source/' . $database);
        $service = new BackupService();
        $service->create($this->directory . '/source', $this->directory . '/backup.tar', $database);
        $service->restore($this->directory . '/backup.tar', $this->directory . '/restored');
        $content = file_get_contents($this->directory . '/restored/.env');
        self::assertIsString($content);
        self::assertSame($database, (new \Symfony\Component\Dotenv\Dotenv())->parse($content)['DATABASE_PATH']);
        self::assertFileExists($this->directory . '/restored/' . $database);
    }

    public function testCorruptDatabaseWithValidChecksumCleansAllRestoredFiles(): void
    {
        $pdo = $this->database();
        $service = new BackupService();
        $archivePath = $this->directory . '/backup.tar';
        $service->create($this->directory . '/source', $archivePath);
        $archive = new PharData($archivePath);
        $database = 'storage/submissions.sqlite';
        $corrupt = "SQLite format 3\0" . str_repeat("\0", 100);
        $archive[$database] = $corrupt;
        $manifest = json_decode($archive['manifest.json']->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $manifest['files'][$database] = ['size' => strlen($corrupt), 'sha256' => hash('sha256', $corrupt)];
        $archive['manifest.json'] = json_encode($manifest, JSON_THROW_ON_ERROR);
        $target = $this->directory . '/restored';
        try {
            $service->restore($archivePath, $target);
            self::fail('Corrupt SQLite database accepted');
        } catch (\PDOException | RuntimeException $error) {
            self::assertDirectoryExists($target);
            self::assertSame([], array_values(array_diff(scandir($target), ['.', '..'])));
            self::assertSame('SECRET=private', file_get_contents($this->directory . '/source/.env'));
        }
    }

    public function testMultilineDatabaseEnvironmentIsRewrittenWithoutChangingCredentials(): void
    {
        $pdo = $this->database();
        file_put_contents($this->directory . '/source/.env', "SECRET=private\nexport DATABASE_PATH=\"old\nlocation\"\nOTHER=value\n");
        $service = new BackupService();
        $service->create($this->directory . '/source', $this->directory . '/backup.tar');
        $service->restore($this->directory . '/backup.tar', $this->directory . '/restored');
        $content = file_get_contents($this->directory . '/restored/.env');
        self::assertIsString($content);
        $variables = (new \Symfony\Component\Dotenv\Dotenv())->parse($content);
        self::assertSame('storage/submissions.sqlite', $variables['DATABASE_PATH']);
        self::assertSame('private', $variables['SECRET']);
        self::assertSame('value', $variables['OTHER']);
    }

    public function testUnsafeManifestPathsAreRejectedBeforeExtraction(): void
    {
        $pdo = $this->database();
        $service = new BackupService();
        $archivePath = $this->directory . '/backup.tar';
        $service->create($this->directory . '/source', $archivePath);
        $archive = new PharData($archivePath);
        foreach (['/absolute', 'config/../escaped', 'config/./file', 'config//file', 'config\\file', 'config/file:stream', "config/file\nname"] as $unsafe) {
            $archive['manifest.json'] = json_encode([
                'version' => 1,
                'database' => 'storage/submissions.sqlite',
                'files' => [$unsafe => ['size' => 0, 'sha256' => hash('sha256', '')]],
            ], JSON_THROW_ON_ERROR);
            try {
                $service->restore($archivePath, $this->directory . '/restored');
                self::fail('Unsafe archive path accepted: ' . $unsafe);
            } catch (RuntimeException $error) {
                self::assertStringContainsString('Unsafe backup path', $error->getMessage());
                self::assertDirectoryDoesNotExist($this->directory . '/restored');
            }
        }
    }

    public function testArchivesAndRestoredCredentialsUsePrivatePermissions(): void
    {
        $pdo = $this->database();
        $service = new BackupService();
        $archive = $this->directory . '/backup.tar';
        $service->create($this->directory . '/source', $archive);
        $target = $this->directory . '/restored';
        $service->restore($archive, $target);
        self::assertSame(0600, fileperms($archive) & 0777);
        self::assertSame(0700, fileperms($target) & 0777);
        foreach (['.env', 'config/forms.php', 'storage/submissions.sqlite', 'storage/uploads/test.txt'] as $file) {
            self::assertSame(0600, fileperms($target . '/' . $file) & 0777, $file);
        }
    }

}
