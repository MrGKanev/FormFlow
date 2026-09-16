<?php

declare(strict_types=1);

namespace formflow;

use PDO;
use RuntimeException;

final class SqliteSubmissionRepository implements SubmissionRepositoryInterface
{
    private PDO $pdo;

    public function __construct(string $databasePath, private readonly string $uploadDirectory = '')
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
            'created_at' => Clock::nowIso(),
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
            'sent_at' => Clock::nowIso(),
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
            'reviewed_at' => Clock::nowIso(),
            'id' => $submissionId,
        ]);
    }

    public function delete(int $submissionId): void
    {
        $submission = $this->find($submissionId);
        $statement = $this->pdo->prepare('DELETE FROM submissions WHERE id = :id');
        $statement->execute(['id' => $submissionId]);

        if ($statement->rowCount() > 0 && $submission !== null) {
            $this->deletePayloadUploads((string) $submission['payload']);
        }
    }

    public function deleteOlderThan(int $days): int
    {
        $cutoff = Clock::relativeIso(-(max(1, $days) * 86400));
        $payloads = $this->payloadsOlderThan($cutoff);
        $statement = $this->pdo->prepare(
            'DELETE FROM submissions WHERE created_at < :cutoff'
        );
        $statement->execute(['cutoff' => $cutoff]);
        $deleted = $statement->rowCount();

        if ($deleted > 0) {
            foreach ($payloads as $payload) {
                $this->deletePayloadUploads($payload);
            }
        }

        return $deleted;
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

    public function findFailed(int $limit = 100): array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM submissions
             WHERE status = :status
             ORDER BY created_at ASC, id ASC
             LIMIT :limit'
        );
        $statement->bindValue(':status', 'failed', PDO::PARAM_STR);
        $statement->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    public function findPendingMail(int $limit = 100, bool $includeFailed = false): array
    {
        $statuses = $includeFailed ? ['pending_mail', 'failed'] : ['pending_mail'];
        $placeholders = implode(', ', array_fill(0, count($statuses), '?'));
        $statement = $this->pdo->prepare(
            'SELECT * FROM submissions
             WHERE status IN (' . $placeholders . ')
             ORDER BY created_at ASC, id ASC
             LIMIT ?'
        );
        $statement->execute([...$statuses, max(1, $limit)]);

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
            'day_cutoff' => Clock::relativeIso(-86400),
            'week_cutoff' => Clock::relativeIso(-(7 * 86400)),
            'month_cutoff' => Clock::relativeIso(-(30 * 86400)),
        ]);

        return $statement->fetchAll();
    }

    public function analyticsOverview(int $days = 30, ?string $formId = null): array
    {
        $days = in_array($days, [7, 30, 90], true) ? $days : 30;
        $cutoff = Clock::relativeIso(-(($days - 1) * 86400));
        $where = ' WHERE created_at >= :cutoff';
        $params = ['cutoff' => substr($cutoff, 0, 10) . 'T00:00:00+00:00'];

        if ($formId !== null && $formId !== '') {
            $where .= ' AND form_id = :form_id';
            $params['form_id'] = $formId;
        }

        // Only accepted submissions identify people; missing emails are not identities.
        $peopleSql = "COUNT(DISTINCT CASE WHEN status NOT LIKE 'blocked_%'
            AND json_type(payload, '$.email') = 'text'
            THEN NULLIF(lower(trim(json_extract(payload, '$.email'))), '') END)";
        $acceptedSql = "SUM(CASE WHEN status NOT LIKE 'blocked_%' THEN 1 ELSE 0 END)";
        $summaryStatement = $this->pdo->prepare(
            'SELECT COUNT(*) AS total, ' . $peopleSql . ' AS unique_emails, ' . $acceptedSql . ' AS accepted,
                    SUM(CASE WHEN status = "sent" THEN 1 ELSE 0 END) AS sent,
                    SUM(CASE WHEN status = "failed" THEN 1 ELSE 0 END) AS failed,
                    SUM(CASE WHEN status LIKE "blocked_%" THEN 1 ELSE 0 END) AS blocked
             FROM submissions' . $where
        );
        $summaryStatement->execute($params);
        $summaryRow = $summaryStatement->fetch() ?: [];
        $total = (int) ($summaryRow['total'] ?? 0);
        $sent = (int) ($summaryRow['sent'] ?? 0);
        $blocked = (int) ($summaryRow['blocked'] ?? 0);
        $deliverable = max(0, $total - $blocked);

        $trendStatement = $this->pdo->prepare(
            'SELECT substr(created_at, 1, 10) AS date, COUNT(*) AS total
             FROM submissions' . $where . '
             GROUP BY substr(created_at, 1, 10)
             ORDER BY date ASC'
        );
        $trendStatement->execute($params);
        $trendByDate = [];

        foreach ($trendStatement->fetchAll() as $row) {
            $trendByDate[(string) $row['date']] = (int) $row['total'];
        }

        $trend = [];
        for ($offset = $days - 1; $offset >= 0; $offset--) {
            $date = substr(Clock::relativeIso(-($offset * 86400)), 0, 10);
            $trend[] = ['date' => $date, 'total' => $trendByDate[$date] ?? 0];
        }

        $statusStatement = $this->pdo->prepare(
            'SELECT status, COUNT(*) AS total FROM submissions' . $where . '
             GROUP BY status ORDER BY total DESC, status ASC'
        );
        $statusStatement->execute($params);
        $statuses = array_map(
            static fn (array $row): array => ['status' => (string) $row['status'], 'total' => (int) $row['total']],
            $statusStatement->fetchAll()
        );

        $formStatement = $this->pdo->prepare(
            'SELECT form_id, COUNT(*) AS total, ' . $peopleSql . ' AS unique_emails, '
                . $acceptedSql . ' AS accepted,
                SUM(CASE WHEN status = "sent" THEN 1 ELSE 0 END) AS sent
                FROM submissions' . $where . '
             GROUP BY form_id ORDER BY total DESC, form_id ASC'
        );
        $formStatement->execute($params);
        $forms = array_map(
            static fn (array $row): array => ['form_id' => (string) $row['form_id'], 'total' => (int) $row['total'], 'accepted' => (int) $row['accepted'], 'unique_emails' => (int) $row['unique_emails'], 'sent' => (int) $row['sent']],
            $formStatement->fetchAll()
        );

        return [
            'summary' => [
                'total' => $total,
                'unique_emails' => (int) ($summaryRow['unique_emails'] ?? 0),
                'accepted' => $deliverable,
                'sent' => $sent,
                'failed' => (int) ($summaryRow['failed'] ?? 0),
                'blocked' => $blocked,
                'delivery_rate' => $deliverable > 0 ? round(($sent / $deliverable) * 100, 1) : 0.0,
            ],
            'trend' => $trend,
            'statuses' => $statuses,
            'forms' => $forms,
        ];
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

    public function oldestCreatedAtByStatus(string $status): ?string
    {
        $statement = $this->pdo->prepare(
            'SELECT created_at FROM submissions
             WHERE status = :status
             ORDER BY created_at ASC, id ASC
             LIMIT 1'
        );
        $statement->execute(['status' => $status]);
        $row = $statement->fetch();

        return $row === false ? null : (string) $row['created_at'];
    }

    /** @return list<string> */
    private function payloadsOlderThan(string $cutoff): array
    {
        $statement = $this->pdo->prepare('SELECT payload FROM submissions WHERE created_at < :cutoff');
        $statement->execute(['cutoff' => $cutoff]);

        return array_values(array_map('strval', array_column($statement->fetchAll(), 'payload')));
    }

    private function deletePayloadUploads(string $payloadJson): void
    {
        if ($this->uploadDirectory === '') {
            return;
        }

        $payload = json_decode($payloadJson, true);

        if (!is_array($payload)) {
            return;
        }

        foreach ($payload as $value) {
            if (!is_array($value) || ($value['type'] ?? null) !== 'upload') {
                continue;
            }

            $basename = $this->uploadBasename($value);

            if ($basename === null) {
                continue;
            }

            $path = rtrim($this->uploadDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $basename;

            if (is_file($path) && $this->pathIsWithin($path, $this->uploadDirectory)) {
                @unlink($path);
            }
        }
    }

    /** @param array<string, mixed> $upload */
    private function uploadBasename(array $upload): ?string
    {
        $storedName = trim((string) ($upload['stored_name'] ?? ''));

        if ($storedName === '' && isset($upload['relative_path'])) {
            $storedName = basename((string) $upload['relative_path']);
        }

        if ($storedName === '' || $storedName !== basename($storedName)) {
            return null;
        }

        return $storedName;
    }

    private function pathIsWithin(string $path, string $root): bool
    {
        $rootPath = realpath($root);
        $realPath = realpath($path);

        if ($rootPath === false || $realPath === false) {
            return false;
        }

        return $realPath === $rootPath || str_starts_with($realPath, rtrim($rootPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR);
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
            $conditions[] = 'id IN (SELECT rowid FROM submissions_search WHERE x LIKE :search)';
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

        $this->createSearchIndex();
    }

    /**
     * A trigram-tokenized FTS5 table so free-text search can use an index instead of scanning
     * every row with LIKE '%term%'. Kept in sync via triggers so no repository method needs to
     * know about it; `x` holds the concatenated searchable columns for one submission.
     */
    private function createSearchIndex(): void
    {
        $searchIndexExists = (bool) $this->pdo
            ->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'submissions_search'")
            ->fetchColumn();

        $this->pdo->exec("CREATE VIRTUAL TABLE IF NOT EXISTS submissions_search USING fts5(x, tokenize = 'trigram')");

        if (!$searchIndexExists) {
            $this->pdo->exec(
                "INSERT INTO submissions_search (rowid, x)
                 SELECT id, form_id || ' ' || status || ' ' || COALESCE(error_message, '') || ' ' || payload
                 FROM submissions"
            );
        }

        $this->pdo->exec(
            "CREATE TRIGGER IF NOT EXISTS submissions_search_ai AFTER INSERT ON submissions BEGIN
                INSERT INTO submissions_search (rowid, x)
                VALUES (
                    new.id,
                    new.form_id || ' ' || new.status || ' ' || COALESCE(new.error_message, '') || ' ' || new.payload
                );
            END"
        );

        $this->pdo->exec(
            "CREATE TRIGGER IF NOT EXISTS submissions_search_au AFTER UPDATE ON submissions BEGIN
                DELETE FROM submissions_search WHERE rowid = old.id;
                INSERT INTO submissions_search (rowid, x)
                VALUES (
                    new.id,
                    new.form_id || ' ' || new.status || ' ' || COALESCE(new.error_message, '') || ' ' || new.payload
                );
            END"
        );

        $this->pdo->exec(
            'CREATE TRIGGER IF NOT EXISTS submissions_search_ad AFTER DELETE ON submissions BEGIN
                DELETE FROM submissions_search WHERE rowid = old.id;
            END'
        );
    }
}
