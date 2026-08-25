<?php

declare(strict_types=1);

namespace formflow;

use PDO;
use RuntimeException;

final class SqliteRateLimiter implements RateLimiterInterface
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

    public function hit(string $formId, ?string $ipHash): void
    {
        $this->pruneOldHits();

        $statement = $this->pdo->prepare(
            'INSERT INTO rate_limit_hits (form_id, ip_hash, created_at)
             VALUES (:form_id, :ip_hash, :created_at)'
        );

        $statement->execute([
            'form_id' => $formId,
            'ip_hash' => $ipHash,
            'created_at' => Clock::nowIso(),
        ]);
    }

    public function countRecentHitsByIp(string $formId, ?string $ipHash, int $windowMinutes): int
    {
        $since = Clock::relativeIso(-($windowMinutes * 60));

        if ($ipHash === null) {
            $statement = $this->pdo->prepare(
                'SELECT COUNT(*) AS hits FROM rate_limit_hits
                 WHERE form_id = :form_id AND ip_hash IS NULL AND created_at >= :since'
            );

            $statement->execute([
                'form_id' => $formId,
                'since' => $since,
            ]);
        } else {
            $statement = $this->pdo->prepare(
                'SELECT COUNT(*) AS hits FROM rate_limit_hits
                 WHERE form_id = :form_id AND ip_hash = :ip_hash AND created_at >= :since'
            );

            $statement->execute([
                'form_id' => $formId,
                'ip_hash' => $ipHash,
                'since' => $since,
            ]);
        }

        return (int) $statement->fetch()['hits'];
    }

    public function countRecentHitsForForm(string $formId, int $windowMinutes): int
    {
        $since = Clock::relativeIso(-($windowMinutes * 60));

        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) AS hits FROM rate_limit_hits
             WHERE form_id = :form_id AND created_at >= :since'
        );

        $statement->execute([
            'form_id' => $formId,
            'since' => $since,
        ]);

        return (int) $statement->fetch()['hits'];
    }

    private function pruneOldHits(): void
    {
        if (random_int(1, 100) > 2) {
            return;
        }

        $cutoff = Clock::relativeIso(-86400);

        $this->pdo
            ->prepare('DELETE FROM rate_limit_hits WHERE created_at < :cutoff')
            ->execute(['cutoff' => $cutoff]);
    }

    private function createSchema(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS rate_limit_hits (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                form_id TEXT NOT NULL,
                ip_hash TEXT,
                created_at TEXT NOT NULL
            )'
        );

        $this->pdo->exec(
            'CREATE INDEX IF NOT EXISTS idx_rate_limit_hits_form_ip
             ON rate_limit_hits(form_id, ip_hash, created_at)'
        );
    }
}
