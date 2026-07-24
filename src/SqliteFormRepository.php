<?php

declare(strict_types=1);

namespace formflow;

use PDO;
use RuntimeException;

final class SqliteFormRepository implements FormConfigRepositoryInterface
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

    public function all(): array
    {
        $statement = $this->pdo->query('SELECT form_id, config_json FROM forms ORDER BY form_id ASC');
        $forms = [];

        foreach ($statement->fetchAll() as $row) {
            $config = json_decode((string) $row['config_json'], true, flags: JSON_THROW_ON_ERROR);

            if (!is_array($config)) {
                throw new RuntimeException('Stored form config is invalid.');
            }

            $forms[(string) $row['form_id']] = $config;
        }

        return $forms;
    }

    public function create(string $formId, array $config): void
    {
        $now = gmdate('c');

        $statement = $this->pdo->prepare(
            'INSERT INTO forms (form_id, config_json, created_at, updated_at)
             VALUES (:form_id, :config_json, :created_at, :updated_at)'
        );

        $statement->execute([
            'form_id' => $formId,
            'config_json' => json_encode(
                $config,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function exists(string $formId): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM forms WHERE form_id = :form_id LIMIT 1');
        $statement->execute(['form_id' => $formId]);

        return $statement->fetchColumn() !== false;
    }

    private function createSchema(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS forms (
                form_id TEXT PRIMARY KEY,
                config_json TEXT NOT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )'
        );
    }
}
