<?php

declare(strict_types=1);

namespace formflow\Admin;

use formflow\AdminAuth;
use formflow\AuditLogRepositoryInterface;
use formflow\MailSenderInterface;
use formflow\SubmissionRepositoryInterface;
use formflow\WebhookDeliveryRepositoryInterface;
final class AdminSubmissionController
{
    private const PER_PAGE = 20;

    private readonly AdminSubmissionService $service;

    /** @param array<string, array<string, mixed>> $forms */
    public function __construct(
        private readonly SubmissionRepositoryInterface $submissions,
        ?MailSenderInterface $mailSender,
        private readonly array $forms,
        private readonly ?WebhookDeliveryRepositoryInterface $webhookDeliveries,
        private readonly string $uploadDirectory,
        private readonly ?AuditLogRepositoryInterface $auditLog,
        private readonly AdminAuth $auth,
        private readonly AdminViewRenderer $renderer
    ) {
        $this->service = new AdminSubmissionService($submissions, $mailSender, $forms);
    }

    /** @return array<string, mixed> */
    public function dashboard(): array
    {
        [$formId, $status, $search, $dateFrom, $dateTo, $perPage] = $this->filters($_GET);
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $submissions = $this->submissions->findPaginated($formId, $status, $page, $perPage, $search, $dateFrom, $dateTo);
        $total = $this->submissions->count($formId, $status, $search, $dateFrom, $dateTo);

        return $this->htmlResponse(200, $this->renderer->render('dashboard', [
            'submissions' => $submissions,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'formId' => $formId,
            'status' => $status,
            'search' => $search,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'analytics' => $this->submissions->analytics(),
            'containerClass' => 'admin-wide',
        ], 'Submissions'));
    }

    /** @return array<string, mixed> */
    public function detail(int $id): array
    {
        $submission = $this->submissions->find($id);

        if ($submission === null) {
            return $this->htmlResponse(404, '<h1>Submission not found</h1>');
        }

        return $this->htmlResponse(200, $this->renderer->render(
            'submission',
            ['submission' => $submission],
            'Submission #' . $id
        ));
    }

    /** @return array<string, mixed> */
    public function download(int $id, string $field): array
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            return $this->htmlResponse(405, '<h1>Method not allowed</h1>');
        }

        $submission = $this->submissions->find($id);

        if ($submission === null) {
            return $this->htmlResponse(404, '<h1>Submission not found</h1>');
        }

        $payload = json_decode((string) $submission['payload'], true);
        $upload = is_array($payload) && is_array($payload[$field] ?? null) ? $payload[$field] : null;

        if ($upload === null || ($upload['type'] ?? null) !== 'upload') {
            return $this->htmlResponse(404, '<h1>Upload not found</h1>');
        }

        $storedName = $this->uploadStoredName($upload);
        $path = $storedName === null ? null : $this->uploadedFilePath($storedName);

        if ($path === null || !is_file($path)) {
            return $this->htmlResponse(404, '<h1>Upload file not found</h1>');
        }

        $originalName = trim((string) ($upload['original_name'] ?? 'upload'));
        $originalName = $originalName !== '' ? basename($originalName) : 'upload';
        $mimeType = trim((string) ($upload['mime_type'] ?? 'application/octet-stream'));
        $this->recordAudit('submission.upload_download', 'Downloaded upload "' . $field . '" from submission #' . $id . '.');

        return [
            'status' => 200,
            'body' => (string) file_get_contents($path),
            'redirect' => null,
            'headers' => [
                'Content-Type' => $mimeType !== '' ? $mimeType : 'application/octet-stream',
                'Content-Disposition' => 'attachment; filename="' . addcslashes($originalName, "\\\"") . '"',
                'X-Content-Type-Options' => 'nosniff',
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function action(int $id): array
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return $this->htmlResponse(405, '<h1>Method not allowed</h1>');
        }

        if (!$this->verifyCsrfToken()) {
            return $this->htmlResponse(419, '<h1>Invalid CSRF token.</h1>');
        }

        $submission = $this->submissions->find($id);

        if ($submission === null) {
            return $this->htmlResponse(404, '<h1>Submission not found</h1>');
        }

        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'review') {
            $this->submissions->markReviewed($id);
            $this->recordAudit('submission.review', 'Marked submission #' . $id . ' reviewed.');

            return ['status' => 302, 'body' => '', 'redirect' => '/admin/submissions/' . $id];
        }

        if ($action === 'delete') {
            $this->submissions->delete($id);
            $this->recordAudit('submission.delete', 'Deleted submission #' . $id . '.');

            return ['status' => 302, 'body' => '', 'redirect' => '/admin'];
        }

        if ($action === 'resend') {
            $error = $this->service->resend($submission);

            if ($error !== null) {
                $this->recordAudit('submission.resend_failed', 'Resend failed for submission #' . (int) $submission['id'] . '.');

                return $this->htmlResponse(422, $this->renderer->render(
                    'submission',
                    ['submission' => $this->submissions->find($id) ?? $submission, 'error' => $error],
                    'Submission #' . $id
                ));
            }

            $this->recordAudit('submission.resend', 'Resent submission #' . (int) $submission['id'] . '.');

            return ['status' => 302, 'body' => '', 'redirect' => '/admin/submissions/' . $id];
        }

        return $this->htmlResponse(422, '<h1>Unknown submission action.</h1>');
    }

    /** @return array<string, mixed> */
    public function export(): array
    {
        [$formId, $status, $search, $dateFrom, $dateTo] = $this->filters($_GET);
        $rows = $this->submissions->findForExport($formId, $status, $search, $dateFrom, $dateTo);
        $this->recordAudit('submissions.export', 'Exported ' . count($rows) . ' submissions.');

        return $this->csvResponse($rows);
    }

    /** @return array<string, mixed> */
    public function bulkAction(): array
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return $this->htmlResponse(405, '<h1>Method not allowed</h1>');
        }

        if (!$this->verifyCsrfToken()) {
            return $this->htmlResponse(419, '<h1>Invalid CSRF token.</h1>');
        }

        $ids = $this->service->selectedIds($_POST['submission_ids'] ?? []);
        $action = (string) ($_POST['bulk_action'] ?? '');

        if ($ids === []) {
            return ['status' => 302, 'body' => '', 'redirect' => '/admin'];
        }

        if ($action === 'export') {
            $rows = $this->submissions->findByIds($ids);
            $this->recordAudit('submissions.bulk_export', 'Exported ' . count($rows) . ' selected submissions.');

            return $this->csvResponse($rows, 'formflow-selected-submissions.csv');
        }

        foreach ($ids as $id) {
            $submission = $this->submissions->find($id);

            if ($submission === null) {
                continue;
            }

            if ($action === 'review') {
                $this->submissions->markReviewed($id);
            }

            if ($action === 'delete') {
                $this->submissions->delete($id);
            }

            if ($action === 'resend' && (string) $submission['status'] === 'failed') {
                $this->service->resend($submission);
            }
        }

        $this->recordAudit('submissions.bulk_' . $action, 'Ran bulk action on ' . count($ids) . ' submissions.');

        return ['status' => 302, 'body' => '', 'redirect' => '/admin'];
    }

    /** @return array<string, mixed> */
    public function delivery(): array
    {
        return $this->htmlResponse(200, $this->renderer->render('delivery', [
            'entries' => $this->submissions->deliveryLog(),
            'webhookEntries' => $this->webhookDeliveries?->deliveryLog() ?? [],
        ], 'Delivery log'));
    }

    /** @return array<string, mixed> */
    public function replayWebhook(int $id): array
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return $this->htmlResponse(405, '<h1>Method not allowed</h1>');
        }

        if (!$this->verifyCsrfToken()) {
            return $this->htmlResponse(419, '<h1>Invalid CSRF token.</h1>');
        }

        $replayId = $this->webhookDeliveries?->replay($id);

        if ($replayId === null) {
            return $this->htmlResponse(422, '<h1>This delivery cannot be replayed.</h1>');
        }

        $this->recordAudit('webhook.replay', 'Queued webhook delivery #' . $id . ' as #' . $replayId . '.');

        return ['status' => 302, 'body' => '', 'redirect' => '/admin/delivery'];
    }

    /** @return array<string, mixed> */
    public function analytics(): array
    {
        $days = (int) ($_GET['days'] ?? 30);
        $days = in_array($days, [7, 30, 90], true) ? $days : 30;
        $formId = trim((string) ($_GET['form_id'] ?? ''));
        $formId = $formId !== '' ? $formId : null;

        return $this->htmlResponse(200, $this->renderer->render('analytics', [
            'analytics' => $this->submissions->analyticsOverview($days, $formId),
            'days' => $days,
            'formId' => $formId,
            'forms' => array_keys($this->forms),
        ], 'Analytics'));
    }

    private function csvSafeCell(mixed $value): mixed
    {
        if (!is_string($value) || $value === '') {
            return $value;
        }

        return str_contains("=+-@\t\r", $value[0]) ? "'" . $value : $value;
    }

    /** @param list<array<string, mixed>> $rows @return array<string, mixed> */
    private function csvResponse(array $rows, string $filename = 'formflow-submissions.csv'): array
    {
        $csv = fopen('php://temp', 'r+');

        if ($csv === false) {
            return $this->htmlResponse(500, '<h1>Unable to export CSV.</h1>');
        }

        fputcsv($csv, ['id', 'form_id', 'status', 'created_at', 'sent_at', 'reviewed_at', 'error_message', 'payload_json'], ',', '"', '');

        foreach ($rows as $row) {
            fputcsv($csv, array_map($this->csvSafeCell(...), [
                $row['id'] ?? '',
                $row['form_id'] ?? '',
                $row['status'] ?? '',
                $row['created_at'] ?? '',
                $row['sent_at'] ?? '',
                $row['reviewed_at'] ?? '',
                $row['error_message'] ?? '',
                $row['payload'] ?? '',
            ]), ',', '"', '');
        }

        rewind($csv);
        $body = stream_get_contents($csv);
        fclose($csv);

        return [
            'status' => 200,
            'body' => (string) $body,
            'redirect' => null,
            'headers' => [
                'Content-Type' => 'text/csv; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $source
     * @return array{0: string|null, 1: string|null, 2: string|null, 3: string|null, 4: string|null, 5: int}
     */
    private function filters(array $source): array
    {
        $formId = isset($source['form_id']) && $source['form_id'] !== '' ? (string) $source['form_id'] : null;
        $status = isset($source['status']) && $source['status'] !== '' ? (string) $source['status'] : null;
        $search = isset($source['q']) && trim((string) $source['q']) !== '' ? trim((string) $source['q']) : null;
        $dateFrom = isset($source['date_from']) && trim((string) $source['date_from']) !== '' ? trim((string) $source['date_from']) : null;
        $dateTo = isset($source['date_to']) && trim((string) $source['date_to']) !== '' ? trim((string) $source['date_to']) : null;
        $perPage = (int) ($source['per_page'] ?? self::PER_PAGE);

        if (!in_array($perPage, [20, 50, 100], true)) {
            $perPage = self::PER_PAGE;
        }

        return [$formId, $status, $search, $dateFrom, $dateTo, $perPage];
    }

    /** @param array<string, mixed> $upload */
    private function uploadStoredName(array $upload): ?string
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

    private function uploadedFilePath(string $storedName): ?string
    {
        $uploadRoot = realpath($this->uploadDirectory) ?: $this->uploadDirectory;
        $realPath = realpath($uploadRoot . DIRECTORY_SEPARATOR . $storedName);

        if ($realPath === false || !$this->pathIsWithin($realPath, $uploadRoot)) {
            return null;
        }

        return $realPath;
    }

    private function pathIsWithin(string $path, string $root): bool
    {
        $realRoot = realpath($root) ?: $root;
        $path = rtrim($path, DIRECTORY_SEPARATOR);
        $root = rtrim($realRoot, DIRECTORY_SEPARATOR);

        return $path === $root || str_starts_with($path, $root . DIRECTORY_SEPARATOR);
    }

    private function verifyCsrfToken(): bool
    {
        return hash_equals(
            (string) ($_SESSION['csrf_token'] ?? ''),
            (string) ($_POST['csrf_token'] ?? '')
        );
    }

    private function recordAudit(string $action, string $detail): void
    {
        $this->auditLog?->record($this->auth->username(), $action, $detail);
    }

    /** @return array{status: int, body: string, redirect: null} */
    private function htmlResponse(int $status, string $body): array
    {
        return ['status' => $status, 'body' => $body, 'redirect' => null];
    }
}
