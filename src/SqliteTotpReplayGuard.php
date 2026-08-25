<?php

declare(strict_types=1);

namespace formflow;

use PDO;
use PDOException;
use RuntimeException;

final class SqliteTotpReplayGuard implements TotpReplayGuardInterface
{
    private PDO $pdo;

    public function __construct(string $databasePath)
    {
        if ($databasePath !== ':memory:') {
            $directory = dirname($databasePath);

            if (
                !is_dir($directory)
                && !mkdir($directory, 0775, true)
                && !is_dir($directory)
            ) {
                throw new RuntimeException('Unable to create the storage directory.');
            }
        }

        $this->pdo = new PDO('sqlite:' . $databasePath, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $this->pdo->exec('PRAGMA journal_mode = WAL');
        $this->pdo->exec('PRAGMA busy_timeout = 5000');
        $this->createSchema();
    }

    public function consume(string $identity, int $step): bool
    {
        $this->pruneOldSteps();

        $statement = $this->pdo->prepare(
            'INSERT INTO totp_replay_steps (identity, step, created_at) VALUES (:identity, :step, :created_at)'
        );

        try {
            $statement->execute([
                'identity' => $identity,
                'step' => $step,
                'created_at' => Clock::nowIso(),
            ]);
        } catch (PDOException $exception) {
            if (str_contains($exception->getMessage(), 'UNIQUE constraint failed')) {
                return false;
            }

            throw $exception;
        }

        return true;
    }

    private function pruneOldSteps(): void
    {
        if (random_int(1, 100) > 10) {
            return;
        }

        // Steps are only ever checked within a 90-second window (3 * 30s), so anything older is dead weight.
        $cutoff = Clock::relativeIso(-300);

        $this->pdo
            ->prepare('DELETE FROM totp_replay_steps WHERE created_at < :cutoff')
            ->execute(['cutoff' => $cutoff]);
    }

    private function createSchema(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS totp_replay_steps (
                identity TEXT NOT NULL,
                step INTEGER NOT NULL,
                created_at TEXT NOT NULL,
                UNIQUE(identity, step)
            )'
        );
    }
}
