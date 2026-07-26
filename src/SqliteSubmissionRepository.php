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

    public function markReviewed(int $submissionId): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE submissions
             SET reviewed_at = :reviewed_at
             WHERE id = :id'
        );

        $statement->execute([
            'reviewed_at' => gmdate('c'),
            'id' => $submissionId,
        ]);
    }

    public function delete(int $submissionId): void
    {
        $statement = $this->pdo->prepare('DELETE FROM submissions WHERE id = :id');
        $statement->execute(['id' => $submissionId]);
    }

    public function deleteOlderThan(int $days): int
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM submissions WHERE created_at < :cutoff'
        );
        $statement->execute(['cutoff' => gmdate('c', time() - (max(1, $days) * 86400))]);

        return $statement->rowCount();
    }

    public function find(int $submissionId): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM submissions WHERE id = :id');
        $statement->execute(['id' => $submissionId]);

        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    public function findPaginated(
        ?string $formId,
        ?string $status,
        int $page,
        int $perPage,
        ?string $search = null,
        ?string $dateFrom = null,
        ?string $dateTo = null
    ): array
    {
        [$where, $params] = $this->buildFilter($formId, $status, $search, $dateFrom, $dateTo);

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

    public function findForExport(
        ?string $formId,
        ?string $status,
        ?string $search = null,
        ?string $dateFrom = null,
        ?string $dateTo = null
    ): array
    {
        [$where, $params] = $this->buildFilter($formId, $status, $search, $dateFrom, $dateTo);

        $statement = $this->pdo->prepare(
            'SELECT * FROM submissions' . $where . '
             ORDER BY created_at DESC, id DESC'
        );
        $statement->execute($params);

        return $statement->fetchAll();
    }

    public function findByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));

        if ($ids === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $statement = $this->pdo->prepare(
            'SELECT * FROM submissions WHERE id IN (' . $placeholders . ')
             ORDER BY created_at DESC, id DESC'
        );
        $statement->execute($ids);

        return $statement->fetchAll();
    }

    public function deliveryLog(int $limit = 100): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, form_id, status, error_message, created_at, sent_at, reviewed_at
             FROM submissions
             ORDER BY created_at DESC, id DESC
             LIMIT :limit'
        );
        $statement->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    public function analytics(): array
    {
        $statement = $this->pdo->prepare(
            <<<SQL
            SELECT
                form_id,
                COUNT(*) AS total,
                SUM(CASE WHEN created_at >= :day_cutoff THEN 1 ELSE 0 END) AS day_count,
                SUM(CASE WHEN created_at >= :week_cutoff THEN 1 ELSE 0 END) AS week_count,
                SUM(CASE WHEN created_at >= :month_cutoff THEN 1 ELSE 0 END) AS month_count,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed_count,
                SUM(CASE WHEN status LIKE 'blocked_%' THEN 1 ELSE 0 END) AS blocked_count,
                MAX(created_at) AS last_submission
            FROM submissions
            GROUP BY form_id
            ORDER BY total DESC, form_id ASC
            SQL
        );
        $statement->execute([
            'day_cutoff' => gmdate('c', time() - 86400),
            'week_cutoff' => gmdate('c', time() - (7 * 86400)),
            'month_cutoff' => gmdate('c', time() - (30 * 86400)),
        ]);

        return $statement->fetchAll();
    }

    public function count(
        ?string $formId,
        ?string $status,
        ?string $search = null,
        ?string $dateFrom = null,
        ?string $dateTo = null
    ): int {
        [$where, $params] = $this->buildFilter($formId, $status, $search, $dateFrom, $dateTo);

        $statement = $this->pdo->prepare('SELECT COUNT(*) AS total FROM submissions' . $where);
        $statement->execute($params);

        return (int) $statement->fetch()['total'];
    }

    /** @return array{0: string, 1: array<string, string>} */
    private function buildFilter(
        ?string $formId,
        ?string $status,
        ?string $search = null,
        ?string $dateFrom = null,
        ?string $dateTo = null
    ): array
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

        if ($search !== null && trim($search) !== '') {
            $conditions[] = '(payload LIKE :search OR form_id LIKE :search OR status LIKE :search OR error_message LIKE :search)';
            $params['search'] = '%' . trim($search) . '%';
        }

        if ($dateFrom !== null && trim($dateFrom) !== '') {
            $conditions[] = 'created_at >= :date_from';
            $params['date_from'] = trim($dateFrom) . 'T00:00:00+00:00';
        }

        if ($dateTo !== null && trim($dateTo) !== '') {
            $conditions[] = 'created_at <= :date_to';
            $params['date_to'] = trim($dateTo) . 'T23:59:59+00:00';
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
                sent_at TEXT,
                reviewed_at TEXT
            )'
        );

        $columns = $this->pdo->query('PRAGMA table_info(submissions)')->fetchAll();
        $columnNames = array_column($columns, 'name');

        if (!in_array('reviewed_at', $columnNames, true)) {
            $this->pdo->exec('ALTER TABLE submissions ADD COLUMN reviewed_at TEXT');
        }

        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_submissions_form_id ON submissions(form_id)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_submissions_status ON submissions(status)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_submissions_created_at ON submissions(created_at)');
    }
}
