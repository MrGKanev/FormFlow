<?php

declare(strict_types=1);

namespace formflow;

use PDO;
use RuntimeException;

final class SqliteSubmissionRepository implements SubmissionRepositoryInterface
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
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->pdo->exec('PRAGMA busy_timeout = 5000');

        $this->createSchema();
    }

    public function create(
        string $formId,
        array $payload,
        ?string $ipHash,
        string $status = 'received'
    ): int {
        $statement = $this->pdo->prepare(
            'INSERT INTO submissions (form_id, payload, ip_hash, status, created_at)
             VALUES (:form_id, :payload, :ip_hash, :status, :created_at)'
        );

        $statement->execute([
            'form_id' => $formId,
            'payload' => json_encode(
                $payload,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ),
            'ip_hash' => $ipHash,
            'status' => $status,
            'created_at' => gmdate('c'),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function markSent(int $submissionId): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE submissions
             SET status = :status, sent_at = :sent_at, error_message = NULL
             WHERE id = :id'
        );

        $statement->execute([
            'status' => 'sent',
            'sent_at' => gmdate('c'),
            'id' => $submissionId,
        ]);
    }

    public function markFailed(int $submissionId, string $errorMessage): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE submissions
             SET status = :status, error_message = :error_message
             WHERE id = :id'
        );

        $statement->execute([
            'status' => 'failed',
            'error_message' => mb_substr($errorMessage, 0, 1000),
            'id' => $submissionId,
        ]);
    }

    public function find(int $submissionId): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM submissions WHERE id = :id');
        $statement->execute(['id' => $submissionId]);

        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    public function findPaginated(?string $formId, ?string $status, int $page, int $perPage): array
    {
        [$where, $params] = $this->buildFilter($formId, $status);

        $page = max(1, $page);
        $perPage = max(1, $perPage);
        $offset = ($page - 1) * $perPage;

        $statement = $this->pdo->prepare(
            'SELECT * FROM submissions' . $where . '
             ORDER BY created_at DESC, id DESC
             LIMIT :limit OFFSET :offset'
        );

        foreach ($params as $key => $value) {
            $statement->bindValue(':' . $key, $value, PDO::PARAM_STR);
        }

        $statement->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    public function count(?string $formId, ?string $status): int
    {
        [$where, $params] = $this->buildFilter($formId, $status);

        $statement = $this->pdo->prepare('SELECT COUNT(*) AS total FROM submissions' . $where);
        $statement->execute($params);

        return (int) $statement->fetch()['total'];
    }

    /** @return array{0: string, 1: array<string, string>} */
    private function buildFilter(?string $formId, ?string $status): array
    {
        $conditions = [];
        $params = [];

        if ($formId !== null) {
            $conditions[] = 'form_id = :form_id';
            $params['form_id'] = $formId;
        }

        if ($status !== null) {
            $conditions[] = 'status = :status';
            $params['status'] = $status;
        }

        $where = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);

        return [$where, $params];
    }

    private function createSchema(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS submissions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                form_id TEXT NOT NULL,
                payload TEXT NOT NULL,
                ip_hash TEXT,
                status TEXT NOT NULL DEFAULT "received",
                error_message TEXT,
                created_at TEXT NOT NULL,
                sent_at TEXT
            )'
        );

        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_submissions_form_id ON submissions(form_id)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_submissions_status ON submissions(status)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_submissions_created_at ON submissions(created_at)');
    }
}
