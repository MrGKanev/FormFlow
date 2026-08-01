<?php

declare(strict_types=1);

namespace formflow;

use InvalidArgumentException;
use PDO;
use PDOException;
use RuntimeException;

final class SqliteAdminUserRepository implements AdminUserRepositoryInterface
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

    public function findByUsername(string $username): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM admin_users WHERE username = :username LIMIT 1');
        $statement->execute(['username' => $username]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    public function list(): array
    {
        $statement = $this->pdo->query('SELECT id, username, created_at FROM admin_users ORDER BY username ASC');

        return $statement->fetchAll();
    }

    public function create(string $username, string $passwordHash, ?string $totpSecret = null): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO admin_users (username, password_hash, totp_secret, created_at)
             VALUES (:username, :password_hash, :totp_secret, :created_at)'
        );

        try {
            $statement->execute([
                'username' => $username,
                'password_hash' => $passwordHash,
                'totp_secret' => $totpSecret,
                'created_at' => Clock::nowIso(),
            ]);
        } catch (PDOException $exception) {
            if (str_contains($exception->getMessage(), 'UNIQUE constraint failed')) {
                throw new InvalidArgumentException('This admin user already exists.', 0, $exception);
            }

            throw $exception;
        }
    }

    public function delete(int $id): void
    {
        $statement = $this->pdo->prepare('DELETE FROM admin_users WHERE id = :id');
        $statement->execute(['id' => $id]);
    }

    private function createSchema(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS admin_users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT NOT NULL UNIQUE,
                password_hash TEXT NOT NULL,
                totp_secret TEXT,
                created_at TEXT NOT NULL
            )'
        );

        $columns = $this->pdo->query('PRAGMA table_info(admin_users)')->fetchAll();
        $columnNames = array_column($columns, 'name');

        if (!in_array('totp_secret', $columnNames, true)) {
            $this->pdo->exec('ALTER TABLE admin_users ADD COLUMN totp_secret TEXT');
        }
    }
}
