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

    public function enqueue(string $formId, string $channel, string $url, array $payload): void
    {
        $createdAt = gmdate('c');
        $statement = $this->pdo->prepare(
            'INSERT INTO webhook_deliveries
                (form_id, channel, status, attempts, error_message, created_at, sent_at, url, payload_json, next_attempt_at)
             VALUES
                (:form_id, :channel, :status, :attempts, NULL, :created_at, NULL, :url, :payload_json, :next_attempt_at)'
        );
        $statement->execute([
            'form_id' => $formId,
            'channel' => $channel,
            'status' => 'pending',
            'attempts' => 0,
            'created_at' => $createdAt,
            'url' => $url,
            'payload_json' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'next_attempt_at' => $createdAt,
        ]);
    }

    public function due(int $limit = 100): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, form_id, channel, status, attempts, error_message, created_at, sent_at, url, payload_json, next_attempt_at
             FROM webhook_deliveries
             WHERE status = :status
               AND url IS NOT NULL
               AND payload_json IS NOT NULL
               AND (next_attempt_at IS NULL OR next_attempt_at <= :now)
             ORDER BY created_at ASC, id ASC
             LIMIT :limit'
        );
        $statement->bindValue(':status', 'pending', PDO::PARAM_STR);
        $statement->bindValue(':now', gmdate('c'), PDO::PARAM_STR);
        $statement->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    public function markQueuedSent(int $id, int $attempts): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE webhook_deliveries
             SET status = :status, attempts = :attempts, error_message = NULL, sent_at = :sent_at, next_attempt_at = NULL
             WHERE id = :id'
        );
        $statement->execute([
            'status' => 'sent',
            'attempts' => max(1, $attempts),
            'sent_at' => gmdate('c'),
            'id' => $id,
        ]);
    }

    public function markQueuedFailed(int $id, int $attempts, string $errorMessage, ?int $retryAfterSeconds = null): void
    {
        $status = $retryAfterSeconds === null ? 'failed' : 'pending';
        $statement = $this->pdo->prepare(
            'UPDATE webhook_deliveries
             SET status = :status, attempts = :attempts, error_message = :error_message, next_attempt_at = :next_attempt_at
             WHERE id = :id'
        );
        $statement->execute([
            'status' => $status,
            'attempts' => max(1, $attempts),
            'error_message' => mb_substr($errorMessage, 0, 1000),
            'next_attempt_at' => $retryAfterSeconds === null ? null : gmdate('c', time() + $retryAfterSeconds),
            'id' => $id,
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
                sent_at TEXT,
                url TEXT,
                payload_json TEXT,
                next_attempt_at TEXT
            )'
        );
        $columns = $this->pdo->query('PRAGMA table_info(webhook_deliveries)')->fetchAll();
        $columnNames = array_column($columns, 'name');

        foreach (['url' => 'TEXT', 'payload_json' => 'TEXT', 'next_attempt_at' => 'TEXT'] as $column => $type) {
            if (!in_array($column, $columnNames, true)) {
                $this->pdo->exec('ALTER TABLE webhook_deliveries ADD COLUMN ' . $column . ' ' . $type);
            }
        }

        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_webhook_deliveries_created_at ON webhook_deliveries(created_at)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_webhook_deliveries_pending ON webhook_deliveries(status, next_attempt_at)');
    }
}
