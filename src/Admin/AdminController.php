<?php

declare(strict_types=1);

namespace formflow\Admin;

use formflow\AdminAuth;
use formflow\AdminIpWhitelistInterface;
use formflow\AdminUserRepositoryInterface;
use formflow\AdminWhitelistRepositoryInterface;
use formflow\AuditLogRepositoryInterface;
use formflow\FormApiKeyRepositoryInterface;
use formflow\FormConfigValidator;
use formflow\FormConfigRepositoryInterface;
use formflow\MailSenderInterface;
use formflow\SubmissionRepositoryInterface;
use formflow\SubmissionPayloadFormatter;
use formflow\Totp;
use formflow\WebhookDeliveryRepositoryInterface;
use InvalidArgumentException;
use Throwable;

final class AdminController
{
    private const PER_PAGE = 20;

    private readonly AdminSettingsService $settingsService;

    public function __construct(
        private readonly AdminAuth $auth,
        private readonly AdminIpWhitelistInterface $ipWhitelist,
        private readonly SubmissionRepositoryInterface $submissions,
        private readonly AdminWhitelistRepositoryInterface $whitelistRepository,
        private readonly array $configuredIps,
        private readonly string $ipHashSecret,
        private readonly FormApiKeyRepositoryInterface $apiKeys,
        private readonly array $forms,
        private readonly FormConfigRepositoryInterface $formRepository,
        private readonly bool $devLoginEnabled = false,
        private readonly ?string $envPath = null,
        private readonly ?string $adminConfigPath = null,
        private readonly ?string $securityConfigPath = null,
        private readonly ?AdminUserRepositoryInterface $adminUsers = null,
        private readonly ?AuditLogRepositoryInterface $auditLog = null,
        private readonly ?MailSenderInterface $mailSender = null,
        private readonly ?WebhookDeliveryRepositoryInterface $webhookDeliveries = null,
        private readonly ?string $clientIp = null,
        private readonly string $uploadDirectory = '',
        ?AdminSettingsService $settingsService = null
    ) {
        $this->settingsService = $settingsService ?? new AdminSettingsService(
            $this->configuredIps,
            $this->envPath,
            $this->adminConfigPath,
            $this->securityConfigPath
        );
    }

    public function handle(string $path): array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        $clientIp = $this->clientIp();

        if ($clientIp === null || !$this->ipWhitelist->isAllowed($clientIp)) {
            return $this->htmlResponse(403, '<h1>Forbidden</h1>');
        }

        if ($path === 'admin/logout') {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                return ['status' => 302, 'body' => '', 'redirect' => '/admin/login'];
            }

            if (!$this->verifyCsrfToken()) {
                return $this->htmlResponse(419, '<h1>Invalid CSRF token.</h1>');
            }

            $this->auth->logout();

            return ['status' => 302, 'body' => '', 'redirect' => '/admin/login'];
        }

        if ($path === 'admin/login') {
            return $this->handleLogin();
        }

        if ($path === 'admin/recovery') {
            return $this->handleRecovery();
        }

        if (!$this->auth->isLoggedIn()) {
            return ['status' => 302, 'body' => '', 'redirect' => '/admin/login'];
        }

        if ($path === 'admin') {
            return $this->handleDashboard();
        }

        if ($path === 'admin/export') {
            return $this->handleExport();
        }

        if ($path === 'admin/submissions/bulk') {
            return $this->handleSubmissionBulkAction();
        }

        if (preg_match('#^admin/submissions/(\d+)$#', $path, $matches) === 1) {
            return $this->handleSubmissionDetail((int) $matches[1]);
        }

        if (preg_match('#^admin/submissions/(\d+)/uploads/([^/]+)$#', $path, $matches) === 1) {
            return $this->handleUploadDownload((int) $matches[1], rawurldecode((string) $matches[2]));
        }

        if (preg_match('#^admin/submissions/(\d+)/action$#', $path, $matches) === 1) {
            return $this->handleSubmissionAction((int) $matches[1]);
        }

        if ($path === 'admin/delivery') {
            return $this->handleDelivery();
        }

        if ($path === 'admin/system') {
            return $this->handleSystem();
        }

        if ($path === 'admin/whitelist') {
            return $this->handleWhitelist();
        }

        if ($path === 'admin/forms') {
            return $this->handleForms();
        }

        if ($path === 'admin/forms/new') {
            return $this->handleFormCreate();
        }

        if (preg_match('#^admin/forms/([^/]+)/edit$#', $path, $matches) === 1) {
            return $this->handleFormEdit((string) $matches[1]);
        }

        if (preg_match('#^admin/forms/([^/]+)/delete$#', $path, $matches) === 1) {
            return $this->handleFormDelete((string) $matches[1]);
        }

        if ($path === 'admin/settings') {
            return $this->handleSettings();
        }

        if ($path === 'admin/integrations') {
            return $this->handleIntegrations();
        }

        if ($path === 'admin/users') {
            return $this->handleUsers();
        }

        if ($path === 'admin/audit') {
            return $this->handleAudit();
        }

        if ($path === 'admin/backup') {
            return $this->handleBackup();
        }

        if ($path === 'admin/config/export') {
            return $this->handleConfigExport();
        }

        if ($path === 'admin/config/import') {
            return $this->handleConfigImport();
        }

        return $this->htmlResponse(404, '<h1>Not found</h1>');
    }

    private function handleLogin(): array
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            if ($this->auth->isLoggedIn()) {
                return ['status' => 302, 'body' => '', 'redirect' => '/admin'];
            }

            return $this->htmlResponse(200, $this->renderLogin(null));
        }

        if (!$this->verifyCsrfToken()) {
            return $this->htmlResponse(419, $this->renderLogin('Invalid CSRF token.'));
        }

        if (($_POST['dev_bypass'] ?? null) && $this->canUseDevBypass()) {
            session_regenerate_id(true);
            $this->auth->login('dev-localhost');

            return ['status' => 302, 'body' => '', 'redirect' => '/admin'];
        }

        $username = (string) ($_POST['username'] ?? '');
        $password = (string) ($_POST['password'] ?? '');
        $totpCode = isset($_POST['totp_code']) ? (string) $_POST['totp_code'] : null;
        $ipHash = $this->ipHash($this->clientIp());

        $result = $this->auth->attemptLogin($username, $password, $ipHash, $totpCode);

        if ($result === 'locked') {
            return $this->htmlResponse(429, $this->renderLogin('Too many attempts. Try again later.'));
        }

        if ($result === 'invalid') {
            return $this->htmlResponse(401, $this->renderLogin('Invalid username or password.'));
        }

        session_regenerate_id(true);
        $this->auth->login($username);
        $this->recordAudit('login', 'Signed in.');

        return ['status' => 302, 'body' => '', 'redirect' => '/admin'];
    }

    private function handleDashboard(): array
    {
        [$formId, $status, $search, $dateFrom, $dateTo, $perPage] = $this->submissionFilters($_GET);
        $page = max(1, (int) ($_GET['page'] ?? 1));

        $submissions = $this->submissions->findPaginated($formId, $status, $page, $perPage, $search, $dateFrom, $dateTo);
        $total = $this->submissions->count($formId, $status, $search, $dateFrom, $dateTo);

        return $this->htmlResponse(200, $this->render('dashboard', [
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

    private function handleSubmissionDetail(int $id): array
    {
        $submission = $this->submissions->find($id);

        if ($submission === null) {
            return $this->htmlResponse(404, '<h1>Submission not found</h1>');
        }

        return $this->htmlResponse(200, $this->render(
            'submission',
            ['submission' => $submission],
            'Submission #' . $id
        ));
    }

    private function handleUploadDownload(int $id, string $field): array
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

    private function handleSubmissionAction(int $id): array
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
            $error = $this->resendSubmission($submission);

            if ($error !== null) {
                return $this->htmlResponse(422, $this->render(
                    'submission',
                    ['submission' => $this->submissions->find($id) ?? $submission, 'error' => $error],
                    'Submission #' . $id
                ));
            }

            return ['status' => 302, 'body' => '', 'redirect' => '/admin/submissions/' . $id];
        }

        return $this->htmlResponse(422, '<h1>Unknown submission action.</h1>');
    }

    private function resendSubmission(array $submission): ?string
    {
        if ($this->mailSender === null) {
            return 'Mail service is not available.';
        }

        $formId = (string) $submission['form_id'];
        $config = $this->forms[$formId] ?? null;

        if (!is_array($config)) {
            return 'Form configuration was not found.';
        }

        $payload = json_decode((string) $submission['payload'], true);

        if (!is_array($payload)) {
            return 'Submission payload is invalid.';
        }

        try {
            $this->mailSender->send(
                (string) ($config['recipient'] ?? ''),
                (string) ($config['subject'] ?? 'New form submission'),
                SubmissionPayloadFormatter::displayFields($payload)
            );

            $this->submissions->markSent((int) $submission['id']);
            $this->recordAudit('submission.resend', 'Resent submission #' . (int) $submission['id'] . '.');

            return null;
        } catch (Throwable $exception) {
            $this->submissions->markFailed((int) $submission['id'], $exception->getMessage());
            $this->recordAudit('submission.resend_failed', 'Resend failed for submission #' . (int) $submission['id'] . '.');

            return 'Unable to resend email: ' . $exception->getMessage();
        }
    }

    private function handleExport(): array
    {
        [$formId, $status, $search, $dateFrom, $dateTo] = $this->submissionFilters($_GET);
        $rows = $this->submissions->findForExport($formId, $status, $search, $dateFrom, $dateTo);
        $this->recordAudit('submissions.export', 'Exported ' . count($rows) . ' submissions.');

        return $this->csvResponse($rows);
    }

    private function handleSubmissionBulkAction(): array
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return $this->htmlResponse(405, '<h1>Method not allowed</h1>');
        }

        if (!$this->verifyCsrfToken()) {
            return $this->htmlResponse(419, '<h1>Invalid CSRF token.</h1>');
        }

        $ids = $this->selectedSubmissionIds($_POST['submission_ids'] ?? []);
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
                $this->resendSubmission($submission);
            }
        }

        $this->recordAudit('submissions.bulk_' . $action, 'Ran bulk action on ' . count($ids) . ' submissions.');

        return ['status' => 302, 'body' => '', 'redirect' => '/admin'];
    }

    /** Neutralizes CSV formula injection (CWE-1236) by prefixing cells that spreadsheet apps treat as formulas. */
    private function csvSafeCell(mixed $value): mixed
    {
        if (!is_string($value) || $value === '') {
            return $value;
        }

        return str_contains("=+-@\t\r", $value[0]) ? "'" . $value : $value;
    }

    /** @param list<array<string, mixed>> $rows */
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

    /** @param mixed $value @return list<int> */
    private function selectedSubmissionIds(mixed $value): array
    {
        $ids = is_array($value) ? $value : [$value];

        return array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, $ids),
            static fn (int $id): bool => $id > 0
        )));
    }

    private function handleDelivery(): array
    {
        return $this->htmlResponse(200, $this->render('delivery', [
            'entries' => $this->submissions->deliveryLog(),
            'webhookEntries' => $this->webhookDeliveries?->deliveryLog() ?? [],
        ], 'Delivery log'));
    }

    private function handleWhitelist(): array
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!$this->verifyCsrfToken()) {
                return $this->htmlResponse(419, '<h1>Invalid CSRF token.</h1>');
            }

            $action = (string) ($_POST['action'] ?? '');

            if ($action === 'add') {
                $ipOrCidr = trim((string) ($_POST['ip_or_cidr'] ?? ''));
                $note = trim((string) ($_POST['note'] ?? ''));
                $note = $note === '' ? null : $note;

                try {
                    $this->whitelistRepository->add($ipOrCidr, $note);
                } catch (InvalidArgumentException $exception) {
                    return $this->htmlResponse(422, $this->renderWhitelist($exception->getMessage()));
                }
            }

            if ($action === 'remove') {
                $this->whitelistRepository->remove((int) ($_POST['id'] ?? 0));
            }

            return ['status' => 302, 'body' => '', 'redirect' => '/admin/whitelist'];
        }

        return $this->htmlResponse(200, $this->renderWhitelist(null));
    }

    private function renderLogin(?string $error): string
    {
        return $this->render('login', [
            'error' => $error,
            'csrfToken' => $_SESSION['csrf_token'],
            'isLocal' => $this->canUseDevBypass(),
            'containerClass' => 'auth-shell',
        ], 'Log in', withNav: false);
    }

    private function renderWhitelist(?string $error): string
    {
        return $this->render('whitelist', [
            'error' => $error,
            'entries' => $this->whitelistRepository->list(),
            'configuredIps' => $this->configuredIps,
            'csrfToken' => $_SESSION['csrf_token'],
        ], 'IP whitelist');
    }

    private function handleForms(): array
    {
        return $this->htmlResponse(200, $this->renderForms(null, []));
    }

    private function handleFormCreate(): array
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!$this->verifyCsrfToken()) {
                return $this->htmlResponse(419, '<h1>Invalid CSRF token.</h1>');
            }

            try {
                [$formId, $config] = FormConfigValidator::fromAdminInput($_POST);

                if (isset($this->forms[$formId]) || $this->formRepository->exists($formId)) {
                    throw new InvalidArgumentException('A form with this ID already exists.');
                }

                $this->formRepository->create($formId, $config);
                $this->apiKeys->regenerate($formId);
                $this->recordAudit('form.create', 'Created form "' . $formId . '" with an API key.');
            } catch (InvalidArgumentException $exception) {
                return $this->htmlResponse(422, $this->renderFormCreator($exception->getMessage(), $_POST));
            }

            return ['status' => 302, 'body' => '', 'redirect' => '/admin/forms'];
        }

        return $this->htmlResponse(200, $this->renderFormCreator(null, []));
    }

    private function handleFormEdit(string $formId): array
    {
        $formId = rawurldecode($formId);

        if (!isset($this->forms[$formId])) {
            return $this->htmlResponse(404, '<h1>Form not found</h1>');
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!$this->verifyCsrfToken()) {
                return $this->htmlResponse(419, '<h1>Invalid CSRF token.</h1>');
            }

            try {
                [, $config] = FormConfigValidator::fromAdminInput($_POST, $formId);
                $this->formRepository->update($formId, $config);
                $this->recordAudit('form.update', 'Updated form "' . $formId . '".');
            } catch (InvalidArgumentException $exception) {
                return $this->htmlResponse(422, $this->renderFormEditor($formId, $exception->getMessage(), $_POST));
            }

            return ['status' => 302, 'body' => '', 'redirect' => '/admin/forms'];
        }

        return $this->htmlResponse(200, $this->renderFormEditor(
            $formId,
            null,
            $this->formValuesFromConfig($formId, $this->forms[$formId])
        ));
    }

    private function handleFormDelete(string $formId): array
    {
        $formId = rawurldecode($formId);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return $this->htmlResponse(405, '<h1>Method not allowed</h1>');
        }

        if (!$this->verifyCsrfToken()) {
            return $this->htmlResponse(419, '<h1>Invalid CSRF token.</h1>');
        }

        $this->formRepository->delete($formId);
        $this->recordAudit('form.delete', 'Deleted dynamic form "' . $formId . '".');

        return ['status' => 302, 'body' => '', 'redirect' => '/admin/forms'];
    }

    private function render(string $view, array $data, string $title, bool $withNav = true): string
    {
        extract($data, EXTR_SKIP);

        ob_start();
        require __DIR__ . '/views/' . $view . '.php';
        $content = (string) ob_get_clean();

        ob_start();
        require __DIR__ . '/../views/_layout.php';

        return (string) ob_get_clean();
    }

    private function renderForms(?string $error, array $values): string
    {
        $settings = $this->currentSettings();

        return $this->render('forms', [
            'error' => $error,
            'forms' => $this->forms,
            'dynamicFormIds' => array_keys($this->formRepository->all()),
            'apiKeys' => $this->apiKeys->all(),
            'appUrl' => trim((string) ($settings['app_url'] ?? '')),
            'captchaSiteKeys' => [
                'turnstile' => (string) ($settings['turnstile_site_key'] ?? ''),
                'hcaptcha' => (string) ($settings['hcaptcha_site_key'] ?? ''),
                'recaptcha' => (string) ($settings['recaptcha_site_key'] ?? ''),
                'friendlycaptcha' => (string) ($settings['friendly_captcha_site_key'] ?? ''),
            ],
            'csrfToken' => $_SESSION['csrf_token'],
            'values' => $values,
        ], 'Forms');
    }

    private function renderFormEditor(string $formId, ?string $error, array $values): string
    {
        return $this->render('form-edit', [
            'error' => $error,
            'formId' => $formId,
            'csrfToken' => $_SESSION['csrf_token'],
            'values' => $values,
            'integrationSettings' => $this->currentSettings(),
        ], 'Edit form');
    }

    private function renderFormCreator(?string $error, array $values): string
    {
        return $this->render('form-new', [
            'error' => $error,
            'csrfToken' => $_SESSION['csrf_token'],
            'values' => $values,
            'integrationSettings' => $this->currentSettings(),
        ], 'New form');
    }

    private function handleSettings(): array
    {
        $tab = $this->settingsTab((string) ($_POST['tab'] ?? $_GET['tab'] ?? 'general'));

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!$this->verifyCsrfToken()) {
                return $this->htmlResponse(419, '<h1>Invalid CSRF token.</h1>');
            }

            $action = (string) ($_POST['action'] ?? 'save');

            if ($action === 'cleanup') {
                $days = max(1, (int) ($_POST['retention_days'] ?? $this->currentSettings()['retention_days'] ?? 180));
                $deleted = $this->submissions->deleteOlderThan($days);
                $this->recordAudit('retention.cleanup', 'Deleted ' . $deleted . ' submissions older than ' . $days . ' days.');

                return $this->htmlResponse(200, $this->renderSettings(null, array_merge($_POST, ['tab' => $tab === 'general' ? 'maintenance' : $tab]), false, 'Deleted ' . $deleted . ' old submissions.'));
            }

            if ($action === 'generate_recovery') {
                $token = bin2hex(random_bytes(24));
                $expiresAt = gmdate('c', time() + 3600);
                $this->writeEnvFile([
                    'RECOVERY_TOKEN_HASH' => password_hash($token, PASSWORD_DEFAULT),
                    'RECOVERY_TOKEN_EXPIRES_AT' => $expiresAt,
                ]);
                $this->recordAudit('settings.recovery_token', 'Generated a recovery token.');

                return $this->htmlResponse(200, $this->renderSettings(
                    null,
                    array_merge($_POST, ['tab' => $tab === 'general' ? 'admin' : $tab]),
                    false,
                    'Recovery token generated. It expires at ' . $expiresAt . '.',
                    $token,
                    $expiresAt
                ));
            }

            if ($action === 'generate_totp') {
                $secret = Totp::generateSecret();
                $this->writeEnvFile(['ADMIN_TOTP_SECRET' => $secret]);
                $this->recordAudit('settings.totp', 'Generated bootstrap TOTP secret.');

                return $this->htmlResponse(200, $this->renderSettings(null, array_merge($_POST, ['admin_totp_secret' => $secret, 'tab' => $tab === 'general' ? 'admin' : $tab]), false, 'TOTP secret generated.'));
            }

            if ($action === 'test_email') {
                $message = $this->sendTestEmail((string) ($_POST['test_email_to'] ?? ''));

                if (str_starts_with($message, 'Unable')) {
                    return $this->htmlResponse(422, $this->renderSettings($message, array_merge($_POST, ['tab' => $tab === 'general' ? 'delivery' : $tab]), false));
                }

                return $this->htmlResponse(200, $this->renderSettings(null, array_merge($_POST, ['tab' => $tab === 'general' ? 'delivery' : $tab]), false, $message));
            }

            try {
                $settings = $this->settingsFromPost(array_merge($this->currentSettings(), $_POST));
                $this->writeSettings($settings);
            } catch (InvalidArgumentException $exception) {
                return $this->htmlResponse(422, $this->renderSettings($exception->getMessage(), $_POST, false));
            }

            return ['status' => 302, 'body' => '', 'redirect' => '/admin/settings?tab=' . rawurlencode($tab) . '&saved=1'];
        }

        return $this->htmlResponse(200, $this->renderSettings(null, [], ($_GET['saved'] ?? null) === '1'));
    }

    private function renderSettings(
        ?string $error,
        array $values,
        bool $saved,
        ?string $notice = null,
        ?string $recoveryToken = null,
        ?string $recoveryTokenExpiresAt = null
    ): string {
        $settings = $values !== [] ? array_merge($this->currentSettings(), $values) : $this->currentSettings();
        $activeTab = $this->settingsTab((string) ($values['tab'] ?? $_GET['tab'] ?? 'general'));
        $totpSecret = trim((string) ($settings['admin_totp_secret'] ?? ''));
        $totpUri = $totpSecret !== ''
            ? Totp::provisioningUri($totpSecret, (string) ($settings['admin_username'] ?? 'admin'))
            : '';

        return $this->render('settings', [
            'error' => $error,
            'saved' => $saved,
            'notice' => $notice,
            'settings' => $settings,
            'activeTab' => $activeTab,
            'totpQrSvg' => $totpUri !== '' ? Totp::qrSvg($totpUri) : null,
            'totpProvisioningUri' => $totpUri,
            'recoveryToken' => $recoveryToken,
            'recoveryTokenExpiresAt' => $recoveryTokenExpiresAt,
            'setupStatus' => $this->setupStatus(),
            'csrfToken' => $_SESSION['csrf_token'],
        ], 'Settings');
    }

    private function handleIntegrations(): array
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!$this->verifyCsrfToken()) {
                return $this->htmlResponse(419, '<h1>Invalid CSRF token.</h1>');
            }

            try {
                $this->settingsService->writeEnvFile($this->settingsService->integrationSettingsFromInput($_POST));
                $this->recordAudit('integrations.update', 'Updated notification integrations.');
            } catch (InvalidArgumentException $exception) {
                return $this->htmlResponse(422, $this->renderIntegrations($exception->getMessage(), $_POST, false));
            }

            return ['status' => 302, 'body' => '', 'redirect' => '/admin/integrations?saved=1'];
        }

        return $this->htmlResponse(200, $this->renderIntegrations(null, [], ($_GET['saved'] ?? null) === '1'));
    }

    private function renderIntegrations(?string $error, array $values, bool $saved): string
    {
        return $this->render('integrations', [
            'error' => $error,
            'saved' => $saved,
            'settings' => $values !== [] ? array_merge($this->currentSettings(), $values) : $this->currentSettings(),
            'csrfToken' => $_SESSION['csrf_token'],
        ], 'Integrations');
    }

    /** @return 'general'|'delivery'|'protection'|'admin'|'maintenance' */
    private function settingsTab(string $tab): string
    {
        return in_array($tab, ['general', 'delivery', 'protection', 'admin', 'maintenance'], true)
            ? $tab
            : 'general';
    }

    /** @return array<string, mixed> */
    private function currentSettings(): array
    {
        return $this->settingsService->currentSettings();
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    private function settingsFromPost(array $input): array
    {
        return $this->settingsService->settingsFromInput($input);
    }

    /** @param array<string, mixed> $settings */
    private function writeSettings(array $settings): void
    {
        $this->settingsService->writeSettings($settings);
        $this->recordAudit('settings.update', 'Updated global settings.');
    }

    private function sendTestEmail(string $recipient): string
    {
        if ($this->mailSender === null) {
            return 'Unable to send test email: mail service is not available.';
        }

        $recipient = trim($recipient);

        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            return 'Unable to send test email: enter a valid recipient.';
        }

        try {
            $this->mailSender->send($recipient, 'formflow test email', [
                'message' => 'SMTP settings are working.',
                'sent_at' => gmdate('c'),
            ]);
            $this->recordAudit('settings.test_email', 'Sent test email to ' . $recipient . '.');

            return 'Test email sent to ' . $recipient . '.';
        } catch (Throwable $exception) {
            return 'Unable to send test email: ' . $exception->getMessage();
        }
    }

    /** @param array<string, string> $updates */
    private function writeEnvFile(array $updates): void
    {
        $this->settingsService->writeEnvFile($updates);
    }

    /** @return array<string, mixed> */
    private function securityConfig(): array
    {
        return $this->settingsService->securityConfig();
    }

    /**
     * @param list<string> $blockedIps
     * @param list<string>|null $trustedProxies
     * @param list<string>|null $trustedHeaders
     */
    private function writeSecurityConfig(array $blockedIps, ?array $trustedProxies = null, ?array $trustedHeaders = null): void
    {
        $this->settingsService->writeSecurityConfig($blockedIps, $trustedProxies, $trustedHeaders);
    }

    private function handleUsers(): array
    {
        if ($this->adminUsers === null) {
            return $this->htmlResponse(503, '<h1>Admin users are not available.</h1>');
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!$this->verifyCsrfToken()) {
                return $this->htmlResponse(419, '<h1>Invalid CSRF token.</h1>');
            }

            $action = (string) ($_POST['action'] ?? 'create');

            try {
                if ($action === 'create') {
                    $username = trim((string) ($_POST['username'] ?? ''));
                    $password = (string) ($_POST['password'] ?? '');
                    $totpSecret = trim((string) ($_POST['totp_secret'] ?? ''));

                    if (!preg_match('/^[A-Za-z0-9_.@-]{3,80}$/', $username)) {
                        throw new InvalidArgumentException('Username must be 3-80 characters: letters, numbers, dot, dash, underscore, or @.');
                    }

                    if (strlen($password) < 8) {
                        throw new InvalidArgumentException('Password must be at least 8 characters.');
                    }

                    $this->adminUsers->create($username, password_hash($password, PASSWORD_DEFAULT), $totpSecret !== '' ? $totpSecret : null);
                    $this->recordAudit('admin_user.create', 'Created admin user "' . $username . '".');
                }

                if ($action === 'delete') {
                    $id = (int) ($_POST['id'] ?? 0);
                    $this->adminUsers->delete($id);
                    $this->recordAudit('admin_user.delete', 'Deleted admin user #' . $id . '.');
                }
            } catch (InvalidArgumentException $exception) {
                return $this->htmlResponse(422, $this->renderUsers($exception->getMessage()));
            }

            return ['status' => 302, 'body' => '', 'redirect' => '/admin/users'];
        }

        return $this->htmlResponse(200, $this->renderUsers(null));
    }

    private function renderUsers(?string $error): string
    {
        return $this->render('users', [
            'error' => $error,
            'users' => $this->adminUsers?->list() ?? [],
            'csrfToken' => $_SESSION['csrf_token'],
            'bootstrapUsername' => $this->currentSettings()['admin_username'] ?? 'admin',
        ], 'Admin users');
    }

    private function handleAudit(): array
    {
        return $this->htmlResponse(200, $this->render('audit', [
            'entries' => $this->auditLog?->list() ?? [],
        ], 'Audit log'));
    }

    private function handleSystem(): array
    {
        return $this->htmlResponse(200, $this->render('system', [
            'status' => $this->systemStatus(),
            'warnings' => $this->systemWarnings(),
        ], 'System status'));
    }

    private function handleRecovery(): array
    {
        // A GET with ?token= only ever happens once (the emailed/CLI link). Move it into the
        // session and redirect to a clean URL so it doesn't linger in browser history, access
        // logs of later requests on this page, or a Referer header.
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' && isset($_GET['token'])) {
            $_SESSION['recovery_token'] = (string) $_GET['token'];

            return ['status' => 302, 'body' => '', 'redirect' => '/admin/recovery'];
        }

        $token = (string) ($_POST['token'] ?? $_SESSION['recovery_token'] ?? '');
        $settings = $this->currentSettings();
        $hash = (string) ($settings['recovery_token_hash'] ?? '');
        $expiresAt = (string) ($settings['recovery_token_expires_at'] ?? '');

        if (
            $token === ''
            || $hash === ''
            || $this->recoveryTokenExpired($expiresAt)
            || !password_verify($token, $hash)
        ) {
            unset($_SESSION['recovery_token']);

            return $this->htmlResponse(403, '<h1>Invalid recovery token.</h1>');
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!$this->verifyCsrfToken()) {
                return $this->htmlResponse(419, '<h1>Invalid CSRF token.</h1>');
            }

            $password = (string) ($_POST['password'] ?? '');

            if (strlen($password) < 8) {
                return $this->htmlResponse(422, $this->render('recovery', [
                    'token' => $token,
                    'error' => 'Password must be at least 8 characters.',
                    'csrfToken' => $_SESSION['csrf_token'],
                ], 'Recovery', withNav: false));
            }

            $this->writeEnvFile([
                'ADMIN_PASSWORD_HASH' => password_hash($password, PASSWORD_DEFAULT),
                'RECOVERY_TOKEN_HASH' => '',
                'RECOVERY_TOKEN_EXPIRES_AT' => '',
            ]);
            $this->recordAudit('recovery.password_reset', 'Bootstrap password reset with recovery token.');
            unset($_SESSION['recovery_token']);

            return ['status' => 302, 'body' => '', 'redirect' => '/admin/login'];
        }

        return $this->htmlResponse(200, $this->render('recovery', [
            'token' => $token,
            'error' => null,
            'csrfToken' => $_SESSION['csrf_token'],
        ], 'Recovery', withNav: false));
    }

    private function handleBackup(): array
    {
        if (!$this->verifyCsrfToken()) {
            return $this->htmlResponse(403, '<h1>Forbidden</h1>');
        }

        $settings = $this->currentSettings();
        $path = (string) ($settings['database_path'] ?? 'storage/submissions.sqlite');
        $path = $this->backupDatabasePath($path);

        if ($path === null) {
            return $this->htmlResponse(403, '<h1>Backup path is not allowed.</h1>');
        }

        if (!is_file($path)) {
            return $this->htmlResponse(404, '<h1>Database not found.</h1>');
        }

        if (!$this->isSqliteDatabase($path)) {
            return $this->htmlResponse(422, '<h1>Backup file is not a SQLite database.</h1>');
        }

        $this->recordAudit('backup.download', 'Downloaded SQLite backup.');

        return [
            'status' => 200,
            'body' => (string) file_get_contents($path),
            'redirect' => null,
            'headers' => [
                'Content-Type' => 'application/octet-stream',
                'Content-Disposition' => 'attachment; filename="formflow-submissions.sqlite"',
            ],
        ];
    }

    private function recoveryTokenExpired(string $expiresAt): bool
    {
        if ($expiresAt === '') {
            return false;
        }

        $expiresTimestamp = strtotime($expiresAt);

        return $expiresTimestamp === false || $expiresTimestamp < time();
    }

    private function backupDatabasePath(string $databasePath): ?string
    {
        $root = dirname(__DIR__, 2);
        $storageRoot = realpath($root . '/storage');

        if ($storageRoot === false) {
            return null;
        }

        $path = str_starts_with($databasePath, '/')
            ? $databasePath
            : $root . '/' . ltrim($databasePath, '/');
        $directory = realpath(dirname($path));

        if ($directory === false || !$this->pathIsWithin($directory, $storageRoot)) {
            return null;
        }

        $realPath = realpath($path);

        if ($realPath !== false && !$this->pathIsWithin($realPath, $storageRoot)) {
            return null;
        }

        return $realPath !== false ? $realPath : $path;
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
        $uploadRoot = $this->resolvedUploadDirectory();
        $path = $uploadRoot . DIRECTORY_SEPARATOR . $storedName;
        $realPath = realpath($path);

        if ($realPath === false || !$this->pathIsWithin($realPath, $uploadRoot)) {
            return null;
        }

        return $realPath;
    }

    private function resolvedUploadDirectory(): string
    {
        return $this->uploadDirectory !== ''
            ? $this->uploadDirectory
            : dirname(__DIR__, 2) . '/storage/uploads';
    }

    private function pathIsWithin(string $path, string $root): bool
    {
        $realRoot = realpath($root) ?: $root;
        $path = rtrim($path, DIRECTORY_SEPARATOR);
        $root = rtrim($realRoot, DIRECTORY_SEPARATOR);

        return $path === $root || str_starts_with($path, $root . DIRECTORY_SEPARATOR);
    }

    private function isSqliteDatabase(string $path): bool
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return false;
        }

        $header = fread($handle, 16);
        fclose($handle);

        return $header === "SQLite format 3\0";
    }

    private function handleConfigExport(): array
    {
        if (!$this->verifyCsrfToken()) {
            return $this->htmlResponse(403, '<h1>Forbidden</h1>');
        }

        $data = [
            'settings' => $this->currentSettings(),
            'forms' => $this->forms,
            'security' => $this->securityConfig(),
        ];
        $this->recordAudit('config.export', 'Exported configuration.');

        return [
            'status' => 200,
            'body' => json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'redirect' => null,
            'headers' => [
                'Content-Type' => 'application/json; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="formflow-config.json"',
            ],
        ];
    }

    private function handleConfigImport(): array
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return $this->htmlResponse(405, '<h1>Method not allowed</h1>');
        }

        if (!$this->verifyCsrfToken()) {
            return $this->htmlResponse(419, '<h1>Invalid CSRF token.</h1>');
        }

        $json = trim((string) ($_POST['config_json'] ?? ''));
        $data = json_decode($json, true);

        if (!is_array($data)) {
            return $this->htmlResponse(422, '<h1>Invalid config JSON.</h1>');
        }

        $settingsToWrite = null;
        $securityToWrite = null;
        $formsToWrite = [];

        try {
            if (isset($data['settings']) && is_array($data['settings'])) {
                $settingsToWrite = $this->settingsFromPost(array_merge($this->currentSettings(), $data['settings']));
            }

            if (isset($data['security']) && is_array($data['security'])) {
                $securityToWrite = $this->settingsService->securityFromConfig(array_merge($this->securityConfig(), $data['security']));
            }

            if (isset($data['forms']) && is_array($data['forms'])) {
                foreach ($data['forms'] as $formId => $config) {
                    if (is_string($formId) && is_array($config)) {
                        $formsToWrite[$formId] = FormConfigValidator::normalize($formId, $config);
                        continue;
                    }

                    throw new InvalidArgumentException('Imported forms must be keyed by valid form IDs.');
                }
            }
        } catch (InvalidArgumentException $exception) {
            return $this->htmlResponse(422, '<h1>' . htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8') . '</h1>');
        }

        if ($settingsToWrite !== null) {
            $this->writeSettings($settingsToWrite);
        }

        if ($securityToWrite !== null) {
            $this->writeSecurityConfig(
                $securityToWrite['blocked_ips'],
                $securityToWrite['trusted_proxies'],
                $securityToWrite['trusted_ip_headers']
            );
        }

        foreach ($formsToWrite as $formId => $config) {
            $this->formRepository->update($formId, $config);
        }

        $this->recordAudit('config.import', 'Imported configuration.');

        return ['status' => 302, 'body' => '', 'redirect' => '/admin/settings?saved=1'];
    }

    private function setupStatus(): array
    {
        $settings = $this->currentSettings();
        $databasePath = (string) ($settings['database_path'] ?? 'storage/submissions.sqlite');
        $databaseDirectory = dirname(str_starts_with($databasePath, '/') ? $databasePath : dirname(__DIR__, 2) . '/' . $databasePath);
        $mailReady = (string) ($settings['mail_from'] ?? '') !== ''
            && ((string) ($settings['mailer_dsn'] ?? '') !== '' || (string) ($settings['smtp_host'] ?? '') !== '');
        $captchaReady = (
            (string) ($settings['turnstile_secret'] ?? '') !== '' && (string) ($settings['turnstile_site_key'] ?? '') !== ''
        ) || (
            (string) ($settings['hcaptcha_secret'] ?? '') !== '' && (string) ($settings['hcaptcha_site_key'] ?? '') !== ''
        ) || (
            (string) ($settings['recaptcha_secret'] ?? '') !== '' && (string) ($settings['recaptcha_site_key'] ?? '') !== ''
        ) || (
            (string) ($settings['friendly_captcha_api_key'] ?? '') !== '' && (string) ($settings['friendly_captcha_site_key'] ?? '') !== ''
        );

        return [
            'mail' => $mailReady ? 'Configured' : 'Needs SMTP',
            'captcha' => $captchaReady ? 'Configured' : 'Optional',
            'storage' => is_dir($databaseDirectory) && is_writable($databaseDirectory) ? 'Writable' : 'Check storage',
            'forms' => (string) count($this->forms),
        ];
    }

    /** @return array<string, string|int> */
    private function systemStatus(): array
    {
        $settings = $this->currentSettings();
        $databasePath = (string) ($settings['database_path'] ?? 'storage/submissions.sqlite');
        $absoluteDatabasePath = str_starts_with($databasePath, '/')
            ? $databasePath
            : dirname(__DIR__, 2) . '/' . ltrim($databasePath, '/');
        $storagePath = dirname($absoluteDatabasePath);
        $uploadPath = $this->resolvedUploadDirectory();
        $failedMail = $this->submissions->count(null, 'failed');
        $pendingMail = $this->submissions->count(null, 'pending_mail');
        $pendingWebhooks = $this->webhookDeliveries?->countByStatus('pending') ?? 0;

        return [
            'php_version' => PHP_VERSION,
            'app_env' => (string) ($settings['app_env'] ?? 'production'),
            'database_path' => $absoluteDatabasePath,
            'database_status' => is_file($absoluteDatabasePath) ? 'Present' : 'Missing',
            'database_size' => is_file($absoluteDatabasePath) ? $this->humanBytes(filesize($absoluteDatabasePath) ?: 0) : '0 B',
            'storage_writable' => is_dir($storagePath) && is_writable($storagePath) ? 'Writable' : 'Check storage',
            'uploads_writable' => is_dir($uploadPath) && is_writable($uploadPath) ? 'Writable' : 'Check uploads',
            'upload_serving_policy' => $this->uploadServingPolicy($uploadPath),
            'missing_vendor_packages' => count($this->missingComposerPackages()),
            'mail_delivery_mode' => (string) ($settings['mail_delivery_mode'] ?? 'sync'),
            'webhook_delivery_mode' => (string) ($settings['webhook_delivery_mode'] ?? 'sync'),
            'pending_mail' => $pendingMail,
            'mail_queue_lag' => $this->queueLag($this->submissions->oldestCreatedAtByStatus('pending_mail')),
            'mail_worker_last_run' => $this->workerLastRun('mail'),
            'failed_mail' => $failedMail,
            'pending_webhooks' => $pendingWebhooks,
            'webhook_queue_lag' => $this->queueLag($this->webhookDeliveries?->oldestCreatedAtByStatus('pending')),
            'webhook_worker_last_run' => $this->workerLastRun('webhooks'),
            'forms' => count($this->forms),
            'trusted_proxies' => count($this->lines((string) ($settings['trusted_proxies'] ?? ''))),
        ];
    }

    /** @return list<string> */
    private function systemWarnings(): array
    {
        $settings = $this->currentSettings();
        $warnings = [];
        $appEnv = (string) ($settings['app_env'] ?? 'production');

        if ($appEnv !== 'production') {
            $warnings[] = 'APP_ENV is "' . $appEnv . '"; use "production" for deployed instances.';
        }

        $missingPackages = $this->missingComposerPackages();

        if ($missingPackages !== []) {
            $warnings[] = 'Missing Composer packages in vendor/: ' . implode(', ', array_slice($missingPackages, 0, 8)) . (count($missingPackages) > 8 ? ', ...' : '') . '. Run composer install.';
        }

        $uploadPolicy = $this->uploadServingPolicy($this->resolvedUploadDirectory());

        if ($uploadPolicy === 'Missing deny rule') {
            $warnings[] = 'Uploads are writable and appear to be under the public web root without a local deny rule.';
        }

        if (($settings['mail_delivery_mode'] ?? 'sync') === 'queue' && $this->workerLastRun('mail') === 'Never') {
            $warnings[] = 'Mail queue mode is enabled, but no mail worker heartbeat has been recorded.';
        }

        if (($settings['webhook_delivery_mode'] ?? 'sync') === 'queue' && $this->workerLastRun('webhooks') === 'Never') {
            $warnings[] = 'Webhook queue mode is enabled, but no webhook worker heartbeat has been recorded.';
        }

        return $warnings;
    }

    /** @return list<string> */
    private function missingComposerPackages(): array
    {
        $root = dirname(__DIR__, 2);
        $lockPath = $root . '/composer.lock';

        if (!is_file($lockPath)) {
            return [];
        }

        $lock = json_decode((string) file_get_contents($lockPath), true);

        if (!is_array($lock)) {
            return [];
        }

        $packages = array_merge(
            is_array($lock['packages'] ?? null) ? $lock['packages'] : [],
            is_array($lock['packages-dev'] ?? null) ? $lock['packages-dev'] : []
        );
        $missing = [];

        foreach ($packages as $package) {
            $name = is_array($package) ? (string) ($package['name'] ?? '') : '';

            if ($name === '' || !str_contains($name, '/')) {
                continue;
            }

            [$vendor, $packageName] = explode('/', $name, 2);

            if (!is_dir($root . '/vendor/' . $vendor . '/' . $packageName)) {
                $missing[] = $name;
            }
        }

        sort($missing);

        return $missing;
    }

    private function uploadServingPolicy(string $uploadPath): string
    {
        $root = dirname(__DIR__, 2);
        $publicRoot = realpath($root . '/public');
        $uploadRoot = realpath($uploadPath) ?: $uploadPath;

        if (!is_dir($uploadPath)) {
            return 'Check uploads';
        }

        if ($publicRoot === false || !$this->pathIsWithin($uploadRoot, $publicRoot)) {
            return 'Outside public root';
        }

        return $this->uploadDenyRulePresent($uploadPath) ? 'Deny rule present' : 'Missing deny rule';
    }

    private function uploadDenyRulePresent(string $uploadPath): bool
    {
        $htaccess = rtrim($uploadPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.htaccess';

        if (!is_file($htaccess)) {
            return false;
        }

        $content = strtolower((string) file_get_contents($htaccess));

        return str_contains($content, 'deny from all') || str_contains($content, 'require all denied');
    }

    private function queueLag(?string $oldestCreatedAt): string
    {
        if ($oldestCreatedAt === null || $oldestCreatedAt === '') {
            return 'No pending work';
        }

        $timestamp = strtotime($oldestCreatedAt);

        if ($timestamp === false) {
            return 'Unknown';
        }

        return $this->humanDuration(max(0, time() - $timestamp));
    }

    private function workerLastRun(string $worker): string
    {
        $path = dirname(__DIR__, 2) . '/storage/runtime/' . $worker . '-last-run.txt';

        if (!is_file($path)) {
            return 'Never';
        }

        $timestamp = trim((string) file_get_contents($path));

        return $timestamp !== '' ? $timestamp : 'Never';
    }

    private function humanDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . 's';
        }

        if ($seconds < 3600) {
            return (int) floor($seconds / 60) . 'm';
        }

        if ($seconds < 86400) {
            return (int) floor($seconds / 3600) . 'h';
        }

        return (int) floor($seconds / 86400) . 'd';
    }

    private function humanBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $value = (float) $bytes;
        $unit = 'B';

        foreach ($units as $unit) {
            if ($value < 1024 || $unit === 'GB') {
                break;
            }

            $value /= 1024;
        }

        return $unit === 'B'
            ? (string) $bytes . ' B'
            : number_format($value, 1) . ' ' . $unit;
    }

    /**
     * @param array<string, mixed> $source
     * @return array{0: string|null, 1: string|null, 2: string|null, 3: string|null, 4: string|null, 5: int}
     */
    private function submissionFilters(array $source): array
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

    private function recordAudit(string $action, string $detail): void
    {
        $this->auditLog?->record($this->auth->username(), $action, $detail);
    }

    /** @param array<string, mixed> $config @return array<string, mixed> */
    private function formValuesFromConfig(string $formId, array $config): array
    {
        return [
            'form_id' => $formId,
            'recipient' => (string) ($config['recipient'] ?? ''),
            'allowed_origins' => implode(PHP_EOL, $config['allowed_origins'] ?? []),
            'subject' => (string) ($config['subject'] ?? ''),
            'success_redirect' => (string) ($config['success_redirect'] ?? ''),
            'rate_limit_max' => (string) (int) (($config['rate_limit_per_ip']['max'] ?? 5)),
            'rate_limit_window' => (string) (int) (($config['rate_limit_per_ip']['window_minutes'] ?? 10)),
            'daily_limit' => (string) (int) ($config['daily_limit'] ?? 200),
            'captcha_provider' => (string) ($config['captcha_provider'] ?? (!empty($config['turnstile']) ? 'turnstile' : 'none')),
            'turnstile' => !empty($config['turnstile']) ? '1' : '',
            'require_api_key' => !empty($config['require_api_key']) ? '1' : '',
            'blocked_patterns' => implode(PHP_EOL, $config['blocked_patterns'] ?? []),
            'upload_max_file_size_mb' => (string) (int) ($config['uploads']['max_file_size_mb'] ?? 10),
            'upload_max_files' => (string) (int) ($config['uploads']['max_files'] ?? 3),
            'upload_allowed_extensions' => implode(PHP_EOL, $config['uploads']['allowed_extensions'] ?? []),
            'notification_channels' => is_array($config['notification_channels'] ?? null)
                ? $config['notification_channels']
                : [],
            'discord_webhook_url' => (string) ($config['notification_overrides']['discord_webhook_url'] ?? ''),
            'slack_webhook_url' => (string) ($config['notification_overrides']['slack_webhook_url'] ?? ''),
            'generic_webhook_url' => (string) ($config['notification_overrides']['generic_webhook_url'] ?? ''),
            'telegram_bot_token' => (string) ($config['notification_overrides']['telegram_bot_token'] ?? ''),
            'telegram_chat_id' => (string) ($config['notification_overrides']['telegram_chat_id'] ?? ''),
        ];
    }

    /** @return list<string> */
    private function lines(string $value): array
    {
        $lines = preg_split('/\R/', $value) ?: [];
        $result = [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            if (!in_array($line, $result, true)) {
                $result[] = $line;
            }
        }

        return $result;
    }

    private function verifyCsrfToken(): bool
    {
        $token = (string) ($_POST['csrf_token'] ?? '');

        return hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $token);
    }

    private function canUseDevBypass(): bool
    {
        // REMOTE_ADDR alone cannot prove the request is local: a reverse proxy
        // talking to PHP-FPM over 127.0.0.1/a unix socket (the documented Nginx
        // setup in this project) makes every request look loopback unless
        // real_ip is configured. Require an explicit non-production opt-in too.
        if (!$this->devLoginEnabled) {
            return false;
        }

        return in_array($this->clientIp(), ['127.0.0.1', '::1'], true);
    }

    private function clientIp(): ?string
    {
        if ($this->clientIp !== null && $this->clientIp !== '') {
            return $this->clientIp;
        }

        $ip = $_SERVER['REMOTE_ADDR'] ?? null;

        return is_string($ip) && $ip !== '' ? $ip : null;
    }

    private function ipHash(?string $ip): ?string
    {
        if ($ip === null) {
            return null;
        }

        return hash_hmac('sha256', $ip . '|' . date('Y-m'), $this->ipHashSecret);
    }

    private function htmlResponse(int $status, string $body): array
    {
        return ['status' => $status, 'body' => $body, 'redirect' => null];
    }
}
