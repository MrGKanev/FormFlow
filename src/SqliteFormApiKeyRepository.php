<?php

declare(strict_types=1);

namespace formflow;

use PDO;
use RuntimeException;

final class SqliteFormApiKeyRepository implements FormApiKeyRepositoryInterface
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

    public function get(string $formId): ?string
    {
        $statement = $this->pdo->prepare('SELECT api_key FROM form_api_keys WHERE form_id = :form_id');
        $statement->execute(['form_id' => $formId]);

        $key = $statement->fetchColumn();

        return $key === false ? null : (string) $key;
    }

    public function regenerate(string $formId): string
    {
        $apiKey = bin2hex(random_bytes(32));
        $now = gmdate('c');

        $statement = $this->pdo->prepare(
            'INSERT INTO form_api_keys (form_id, api_key, created_at, updated_at)
             VALUES (:form_id, :api_key, :created_at, :updated_at)
             ON CONFLICT(form_id) DO UPDATE SET
                api_key = excluded.api_key,
                updated_at = excluded.updated_at'
        );

        $statement->execute([
            'form_id' => $formId,
            'api_key' => $apiKey,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $apiKey;
    }

    public function all(): array
    {
        $statement = $this->pdo->query('SELECT * FROM form_api_keys');

        $result = [];

        foreach ($statement->fetchAll() as $row) {
            $result[$row['form_id']] = [
                'api_key' => $row['api_key'],
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at'],
            ];
        }

        return $result;
    }

    private function createSchema(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS form_api_keys (
                form_id TEXT PRIMARY KEY,
                api_key TEXT NOT NULL UNIQUE,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )'
        );
    }
}
