<?php

declare(strict_types=1);

namespace formflow\Tests;

use formflow\Admin\AdminBackupService;
use formflow\SqliteFormRepository;
use formflow\SqliteSubmissionRepository;
use PDO;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Dotenv\Dotenv;

final class BackupCliTest extends TestCase
{
    private string $directory;
    private string $root;
    private string $database;
    private SqliteFormRepository $forms;
    private SqliteSubmissionRepository $submissions;
    private int $submissionId;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/formflow-backup-cli-' . bin2hex(random_bytes(8));
        $this->root = $this->directory . '/source';
        mkdir($this->root . '/storage/uploads', 0700, true);
        mkdir($this->root . '/config', 0700, true);
        $this->database = $this->root . '/storage/submissions.sqlite';
        file_put_contents($this->root . '/.env', 'DATABASE_PATH="' . $this->database . '"' . "\nSECRET=private\n");
        file_put_contents($this->root . '/config/forms.php', '<?php return ["static" => ["name" => "Static form"]];');
        file_put_contents($this->root . '/storage/uploads/document.txt', 'attachment content');
        $this->forms = new SqliteFormRepository($this->database);
        $this->forms->create('contact', ['name' => 'Contact', 'recipient' => 'receiver@example.test']);
        $this->submissions = new SqliteSubmissionRepository($this->database);
        $this->submissionId = $this->submissions->create('contact', [
            'name' => 'Alice',
            'attachment' => ['type' => 'upload', 'stored_name' => 'document.txt', 'relative_path' => 'document.txt'],
        ], null);
    }

    protected function tearDown(): void
    {
        unset($this->forms, $this->submissions);
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->directory);
    }

    /** @param list<string> $arguments
     *  @return array{exit:int,output:string,error:string}
     */
    private function runCli(array $arguments): array
    {
        $environment = getenv();
        unset($environment['DATABASE_PATH']);
        $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/bin/formflow', ...$arguments, '--root=' . $this->root], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__), $environment);
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['exit' => proc_close($process), 'output' => $output, 'error' => $error];
    }

    public function testCliRoundtripPreservesUsableApplicationData(): void
    {
        $archive = $this->directory . '/backup.tar';
        $target = $this->directory . '/restored';
        $created = $this->runCli(['backup:create', '--output=' . $archive]);
        self::assertSame(0, $created['exit'], $created['error']);
        $restored = $this->runCli(['backup:restore', '--input=' . $archive, '--target=' . $target]);
        self::assertSame(0, $restored['exit'], $restored['error']);
        $content = file_get_contents($target . '/.env');
        self::assertIsString($content);
        $variables = (new Dotenv())->parse($content);
        self::assertSame('storage/submissions.sqlite', $variables['DATABASE_PATH']);
        self::assertSame('private', $variables['SECRET']);
        $database = $target . '/' . $variables['DATABASE_PATH'];
        self::assertSame($this->forms->all(), (new SqliteFormRepository($database))->all());
        $repository = new SqliteSubmissionRepository($database, $target . '/storage/uploads');
        $submission = $repository->find($this->submissionId);
        self::assertNotNull($submission);
        self::assertSame($this->submissions->find($this->submissionId), $submission);
        $payload = json_decode($submission['payload'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('attachment content', file_get_contents($target . '/storage/uploads/' . $payload['attachment']['stored_name']));
        self::assertSame(['static' => ['name' => 'Static form']], require $target . '/config/forms.php');
        $repository->create('contact', ['name' => 'Bob'], null);
        self::assertSame(2, (new PDO('sqlite:' . $database))->query('SELECT count(*) FROM submissions')->fetchColumn());
    }

    public function testCliRejectsOverwriteAndNonemptyRestore(): void
    {
        $archive = $this->directory . '/backup.tar';
        self::assertSame(0, $this->runCli(['backup:create', '--output=' . $archive])['exit']);
        $hash = hash_file('sha256', $archive);
        $duplicate = $this->runCli(['backup:create', '--output=' . $archive]);
        self::assertSame(1, $duplicate['exit']);
        self::assertStringContainsString('already exists', $duplicate['error']);
        self::assertSame($hash, hash_file('sha256', $archive));
        $rejected = $this->runCli(['backup:restore', '--input=' . $archive, '--target=' . $this->root]);
        self::assertSame(1, $rejected['exit']);
        self::assertStringContainsString('empty', $rejected['error']);
        self::assertNotNull($this->submissions->find($this->submissionId));
    }

    public function testCliRequiresArchiveArguments(): void
    {
        foreach ([['backup:create'], ['backup:restore'], ['backup:restore', '--input=missing.tar']] as $arguments) {
            $result = $this->runCli($arguments);
            self::assertSame(2, $result['exit']);
            self::assertStringContainsString('required', $result['error']);
            self::assertSame('', $result['output']);
        }
    }

    public function testLegacyAdminSnapshotIncludesCommittedWalData(): void
    {
        $content = (new AdminBackupService($this->root))->snapshotDatabase($this->database);
        $target = $this->directory . '/admin-backup.sqlite';
        file_put_contents($target, $content);
        $pdo = new PDO('sqlite:' . $target);
        self::assertSame('ok', $pdo->query('PRAGMA integrity_check')->fetchColumn());
        self::assertSame(1, $pdo->query('SELECT count(*) FROM submissions')->fetchColumn());
        self::assertSame('contact', $pdo->query('SELECT form_id FROM forms')->fetchColumn());
    }
}
