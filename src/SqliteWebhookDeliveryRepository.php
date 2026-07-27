<?php

declare(strict_types=1);

namespace formflow;

use PDO;
use RuntimeException;

final class SqliteWebhookDeliveryRepository implements WebhookDeliveryRepositoryInterface
{
    private PDO $pdo;

    public function __construct(string $databasePath)
    {
        if ($databasePath !== ':memory:') {
            $directory = dirname($databasePath);

            if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
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

    public function record(
        string $formId,
        string $channel,
        string $status,
        int $attempts,
        ?string $errorMessage = null
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO webhook_deliveries (form_id, channel, status, attempts, error_message, created_at, sent_at)
             VALUES (:form_id, :channel, :status, :attempts, :error_message, :created_at, :sent_at)'
        );

        $createdAt = gmdate('c');
        $statement->execute([
            'form_id' => $formId,
            'channel' => $channel,
            'status' => $status,
            'attempts' => max(1, $attempts),
            'error_message' => $errorMessage === null ? null : mb_substr($errorMessage, 0, 1000),
            'created_at' => $createdAt,
            'sent_at' => $status === 'sent' ? $createdAt : null,
        ]);
    }

    public function deliveryLog(int $limit = 100): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, form_id, channel, status, attempts, error_message, created_at, sent_at
             FROM webhook_deliveries
             ORDER BY created_at DESC, id DESC
             LIMIT :limit'
        );
        $statement->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    private function createSchema(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS webhook_deliveries (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                form_id TEXT NOT NULL,
                channel TEXT NOT NULL,
                status TEXT NOT NULL,
                attempts INTEGER NOT NULL,
                error_message TEXT,
                created_at TEXT NOT NULL,
                sent_at TEXT
            )'
        );
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_webhook_deliveries_created_at ON webhook_deliveries(created_at)');
    }
}
