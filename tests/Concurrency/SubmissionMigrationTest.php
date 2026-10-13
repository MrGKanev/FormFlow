<?php

declare(strict_types=1);

namespace formflow\Tests\Concurrency;

use PDO;
use PHPUnit\Framework\TestCase;

final class SubmissionMigrationTest extends TestCase
{
    public function testParallelStartupMigratesLegacyDatabaseAndPreservesExistingRows(): void
    {
        $this->assertParallelMigration(false);
    }

    public function testParallelStartupAddsOnlyMailAttemptColumnToPreviousSchema(): void
    {
        $this->assertParallelMigration(true);
    }

    private function assertParallelMigration(bool $hasPreviousColumns): void
    {
        $directory = sys_get_temp_dir() . '/formflow-migration-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $children = [];

        try {
            $database = $directory . '/data.sqlite';
            $pdo = new PDO('sqlite:' . $database);
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('CREATE TABLE submissions (
                id INTEGER PRIMARY KEY AUTOINCREMENT, form_id TEXT NOT NULL,
                payload TEXT NOT NULL, ip_hash TEXT, status TEXT NOT NULL,
                error_message TEXT, created_at TEXT NOT NULL, sent_at TEXT
            )');
            $pdo->exec("INSERT INTO submissions (form_id, payload, status, created_at)
                VALUES ('contact', '{}', 'pending_mail', '2026-10-01T00:00:00+00:00'),
                    ('contact', '{}', 'failed', '2026-10-01T00:00:00+00:00'),
                    ('contact', '{}', 'sent', '2026-10-01T00:00:00+00:00')");
            if ($hasPreviousColumns) {
                $pdo->exec('ALTER TABLE submissions ADD COLUMN reviewed_at TEXT');
                $pdo->exec('ALTER TABLE submissions ADD COLUMN locked_at TEXT');
            }
            $pdo->exec('BEGIN IMMEDIATE');

            for ($index = 0; $index < 8; $index++) {
                $process = proc_open([PHP_BINARY, __DIR__ . '/migration-child.php', $database],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                self::assertIsResource($process);
                stream_set_timeout($pipes[1], 15);
                $children[] = [$process, $pipes];
            }
            foreach ($children as [$process, $pipes]) {
                self::assertSame("ready\n", fgets($pipes[1]));
            }
            foreach ($children as [$process, $pipes]) {
                fwrite($pipes[0], "go\n");
                fflush($pipes[0]);
            }
            // Keep every process waiting for the same schema writer lock.
            usleep(250000);
            $pdo->exec('COMMIT');

            foreach ($children as [$process, $pipes]) {
                $result = fgets($pipes[1]);
                self::assertSame("migrated\n", $result, (string) stream_get_contents($pipes[2]));
            }
            $columns = $pdo->query('PRAGMA table_info(submissions)')->fetchAll(PDO::FETCH_COLUMN, 1);
            foreach (['reviewed_at', 'locked_at', 'mail_attempted_at', 'mail_attempts', 'mail_next_attempt_at', 'mail_retryable'] as $column) {
                self::assertContains($column, $columns);
            }
            self::assertSame('pending_mail', $pdo->query('SELECT status FROM submissions WHERE id = 1')->fetchColumn());
            self::assertSame([0, 1, 1], $pdo->query('SELECT mail_attempts FROM submissions ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
            self::assertSame([1, 1, 0], $pdo->query('SELECT mail_retryable FROM submissions ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
        } finally {
            if (isset($pdo)) {
                try { $pdo->exec('ROLLBACK'); } catch (\PDOException) {}
            }
            foreach ($children as [$process, $pipes]) {
                if (proc_get_status($process)['running']) { proc_terminate($process, 9); }
                foreach ($pipes as $pipe) { fclose($pipe); }
                proc_close($process);
            }
            unset($pdo);
            foreach (glob($directory . '/*') ?: [] as $path) { unlink($path); }
            rmdir($directory);
        }
    }
}
