<?php

declare(strict_types=1);

namespace formflow;

use InvalidArgumentException;
use PDO;
use PDOException;
use RuntimeException;

final class SqliteAdminWhitelistRepository implements AdminWhitelistRepositoryInterface
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

    public function add(string $ipOrCidr, ?string $note): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO admin_ip_whitelist (ip_or_cidr, note, created_at)
             VALUES (:ip_or_cidr, :note, :created_at)'
        );

        try {
            $statement->execute([
                'ip_or_cidr' => $ipOrCidr,
                'note' => $note,
                'created_at' => Clock::nowIso(),
            ]);
        } catch (PDOException $exception) {
            if (str_contains($exception->getMessage(), 'UNIQUE constraint failed')) {
                throw new InvalidArgumentException(
                    'This IP or CIDR is already whitelisted.',
                    0,
                    $exception
                );
            }

            throw $exception;
        }
    }

    public function remove(int $id): void
    {
        $statement = $this->pdo->prepare('DELETE FROM admin_ip_whitelist WHERE id = :id');
        $statement->execute(['id' => $id]);
    }

    public function list(): array
    {
        $statement = $this->pdo->query('SELECT * FROM admin_ip_whitelist ORDER BY id DESC');

        return $statement->fetchAll();
    }

    private function createSchema(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS admin_ip_whitelist (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                ip_or_cidr TEXT NOT NULL UNIQUE,
                note TEXT,
                created_at TEXT NOT NULL
            )'
        );
    }
}
