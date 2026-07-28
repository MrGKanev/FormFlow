<?php

declare(strict_types=1);

namespace formflow\Admin;

use formflow\AdminAuth;
use formflow\AdminIpWhitelistInterface;
use formflow\AdminUserRepositoryInterface;
use formflow\AdminWhitelistRepositoryInterface;
use formflow\AuditLogRepositoryInterface;
use formflow\FormApiKeyRepositoryInterface;
use formflow\FormConfigRepositoryInterface;
use formflow\MailSenderInterface;
use formflow\SubmissionRepositoryInterface;
use formflow\Totp;
use formflow\WebhookDeliveryRepositoryInterface;
use InvalidArgumentException;
use Throwable;

final class AdminController
{
    private const PER_PAGE = 20;

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
        private readonly ?string $clientIp = null
    ) {
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

        if (preg_match('#^admin/submissions/(\d+)/action$#', $path, $matches) === 1) {
            return $this->handleSubmissionAction((int) $matches[1]);
        }

        if ($path === 'admin/delivery') {
            return $this->handleDelivery();
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
                $payload
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

    /** @param list<array<string, mixed>> $rows */
    private function csvResponse(array $rows, string $filename = 'formflow-submissions.csv'): array
    {
        $csv = fopen('php://temp', 'r+');

        if ($csv === false) {
            return $this->htmlResponse(500, '<h1>Unable to export CSV.</h1>');
        }

        fputcsv($csv, ['id', 'form_id', 'status', 'created_at', 'sent_at', 'reviewed_at', 'error_message', 'payload_json'], ',', '"', '');

        foreach ($rows as $row) {
            fputcsv($csv, [
                $row['id'] ?? '',
                $row['form_id'] ?? '',
                $row['status'] ?? '',
                $row['created_at'] ?? '',
                $row['sent_at'] ?? '',
                $row['reviewed_at'] ?? '',
                $row['error_message'] ?? '',
                $row['payload'] ?? '',
            ], ',', '"', '');
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
                [$formId, $config] = $this->formConfigFromPost($_POST);

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
                [, $config] = $this->formConfigFromPost($_POST, $formId);
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
                $this->writeEnvFile(['RECOVERY_TOKEN_HASH' => password_hash($token, PASSWORD_DEFAULT)]);
                $this->recordAudit('settings.recovery_token', 'Generated a recovery token.');

                return $this->htmlResponse(200, $this->renderSettings(null, array_merge($_POST, ['tab' => $tab === 'general' ? 'admin' : $tab]), false, 'Recovery token: ' . $token));
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

    private function renderSettings(?string $error, array $values, bool $saved, ?string $notice = null): string
    {
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
                $this->writeEnvFile($this->integrationSettingsFromPost($_POST));
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

    /** @param array<string, mixed> $input @return array<string, string> */
    private function integrationSettingsFromPost(array $input): array
    {
        $fields = [
            'discord_webhook_url' => 'DISCORD_WEBHOOK_URL',
            'slack_webhook_url' => 'SLACK_WEBHOOK_URL',
            'generic_webhook_url' => 'GENERIC_WEBHOOK_URL',
            'telegram_bot_token' => 'TELEGRAM_BOT_TOKEN',
            'telegram_chat_id' => 'TELEGRAM_CHAT_ID',
        ];

        $values = [];

        foreach ($fields as $field => $envKey) {
            $value = trim((string) ($input[$field] ?? ''));
            $this->assertSafeEnvValue($field, $value);
            $values[$envKey] = $value;
        }

        foreach (['discord_webhook_url', 'slack_webhook_url', 'generic_webhook_url'] as $field) {
            $url = $values[$fields[$field]];

            if ($url !== '' && !$this->isHttpUrl($url)) {
                throw new InvalidArgumentException('Webhook URLs must be valid http or https URLs.');
            }
        }

        return $values;
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
        $env = $this->readEnvFile();
        $securityConfig = $this->securityConfig();

        return array_merge([
            'app_env' => $env['APP_ENV'] ?? (getenv('APP_ENV') ?: 'production'),
            'app_url' => $env['APP_URL'] ?? (getenv('APP_URL') ?: ''),
            'mailer_dsn' => $env['MAILER_DSN'] ?? (getenv('MAILER_DSN') ?: ''),
            'smtp_host' => $env['SMTP_HOST'] ?? (getenv('SMTP_HOST') ?: ''),
            'smtp_port' => $env['SMTP_PORT'] ?? (getenv('SMTP_PORT') ?: '587'),
            'smtp_encryption' => $env['SMTP_ENCRYPTION'] ?? (getenv('SMTP_ENCRYPTION') ?: 'tls'),
            'smtp_username' => $env['SMTP_USERNAME'] ?? (getenv('SMTP_USERNAME') ?: ''),
            'smtp_password' => $env['SMTP_PASSWORD'] ?? (getenv('SMTP_PASSWORD') ?: ''),
            'mail_from' => $env['MAIL_FROM'] ?? (getenv('MAIL_FROM') ?: ''),
            'mail_from_name' => $env['MAIL_FROM_NAME'] ?? (getenv('MAIL_FROM_NAME') ?: 'formflow'),
            'turnstile_secret' => $env['TURNSTILE_SECRET'] ?? (getenv('TURNSTILE_SECRET') ?: ''),
            'turnstile_site_key' => $env['TURNSTILE_SITE_KEY'] ?? (getenv('TURNSTILE_SITE_KEY') ?: ''),
            'hcaptcha_secret' => $env['HCAPTCHA_SECRET'] ?? (getenv('HCAPTCHA_SECRET') ?: ''),
            'hcaptcha_site_key' => $env['HCAPTCHA_SITE_KEY'] ?? (getenv('HCAPTCHA_SITE_KEY') ?: ''),
            'recaptcha_secret' => $env['RECAPTCHA_SECRET'] ?? (getenv('RECAPTCHA_SECRET') ?: ''),
            'recaptcha_site_key' => $env['RECAPTCHA_SITE_KEY'] ?? (getenv('RECAPTCHA_SITE_KEY') ?: ''),
            'friendly_captcha_api_key' => $env['FRIENDLY_CAPTCHA_API_KEY'] ?? (getenv('FRIENDLY_CAPTCHA_API_KEY') ?: ''),
            'friendly_captcha_site_key' => $env['FRIENDLY_CAPTCHA_SITE_KEY'] ?? (getenv('FRIENDLY_CAPTCHA_SITE_KEY') ?: ''),
            'discord_webhook_url' => $env['DISCORD_WEBHOOK_URL'] ?? (getenv('DISCORD_WEBHOOK_URL') ?: ''),
            'slack_webhook_url' => $env['SLACK_WEBHOOK_URL'] ?? (getenv('SLACK_WEBHOOK_URL') ?: ''),
            'generic_webhook_url' => $env['GENERIC_WEBHOOK_URL'] ?? (getenv('GENERIC_WEBHOOK_URL') ?: ''),
            'telegram_bot_token' => $env['TELEGRAM_BOT_TOKEN'] ?? (getenv('TELEGRAM_BOT_TOKEN') ?: ''),
            'telegram_chat_id' => $env['TELEGRAM_CHAT_ID'] ?? (getenv('TELEGRAM_CHAT_ID') ?: ''),
            'database_path' => $env['DATABASE_PATH'] ?? (getenv('DATABASE_PATH') ?: 'storage/submissions.sqlite'),
            'ip_hash_secret' => $env['IP_HASH_SECRET'] ?? (getenv('IP_HASH_SECRET') ?: ''),
            'retention_days' => $env['RETENTION_DAYS'] ?? (getenv('RETENTION_DAYS') ?: '180'),
            'admin_totp_secret' => $env['ADMIN_TOTP_SECRET'] ?? (getenv('ADMIN_TOTP_SECRET') ?: ''),
            'recovery_token_hash' => $env['RECOVERY_TOKEN_HASH'] ?? (getenv('RECOVERY_TOKEN_HASH') ?: ''),
            'admin_username' => $env['ADMIN_USERNAME'] ?? (getenv('ADMIN_USERNAME') ?: 'admin'),
            'login_rate_limit_max' => (string) 5,
            'login_rate_limit_window' => (string) 15,
            'blocked_ips' => implode(PHP_EOL, $securityConfig['blocked_ips'] ?? []),
        ], $this->adminRateLimitSettings());
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    private function settingsFromPost(array $input): array
    {
        $appEnv = (string) ($input['app_env'] ?? 'production');

        if (!in_array($appEnv, ['production', 'local', 'development', 'testing'], true)) {
            throw new InvalidArgumentException('APP_ENV must be production, local, development, or testing.');
        }

        $rawFields = [
            'app_url',
            'mailer_dsn',
            'smtp_host',
            'smtp_port',
            'smtp_encryption',
            'smtp_username',
            'smtp_password',
            'mail_from',
            'mail_from_name',
            'turnstile_secret',
            'turnstile_site_key',
            'hcaptcha_secret',
            'hcaptcha_site_key',
            'recaptcha_secret',
            'recaptcha_site_key',
            'friendly_captcha_api_key',
            'friendly_captcha_site_key',
            'discord_webhook_url',
            'slack_webhook_url',
            'generic_webhook_url',
            'telegram_bot_token',
            'telegram_chat_id',
            'database_path',
            'ip_hash_secret',
            'retention_days',
            'admin_totp_secret',
            'admin_username',
        ];

        foreach ($rawFields as $field) {
            $this->assertSafeEnvValue($field, (string) ($input[$field] ?? ''));
        }

        $appUrl = trim((string) ($input['app_url'] ?? ''));

        if ($appUrl !== '' && !$this->isHttpUrl($appUrl)) {
            throw new InvalidArgumentException('App URL must be a valid http or https URL.');
        }

        foreach (['discord_webhook_url', 'slack_webhook_url', 'generic_webhook_url'] as $urlField) {
            $url = trim((string) ($input[$urlField] ?? ''));

            if ($url !== '' && !$this->isHttpUrl($url)) {
                throw new InvalidArgumentException('Webhook URLs must be valid http or https URLs.');
            }
        }

        $mailFrom = trim((string) ($input['mail_from'] ?? ''));

        if ($mailFrom !== '' && !filter_var($mailFrom, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('From address must be a valid email address.');
        }

        $smtpPortInput = trim((string) ($input['smtp_port'] ?? '587'));

        if ($smtpPortInput === '' || !ctype_digit($smtpPortInput) || (int) $smtpPortInput < 1 || (int) $smtpPortInput > 65535) {
            throw new InvalidArgumentException('SMTP port must be between 1 and 65535.');
        }

        $smtpPort = (int) $smtpPortInput;

        $smtpEncryption = strtolower(trim((string) ($input['smtp_encryption'] ?? 'tls')));

        if (!in_array($smtpEncryption, ['tls', 'ssl', 'none'], true)) {
            throw new InvalidArgumentException('SMTP encryption must be TLS, SSL, or None.');
        }

        $databasePath = trim((string) ($input['database_path'] ?? ''));

        if ($databasePath === '') {
            throw new InvalidArgumentException('Database path is required.');
        }

        $ipHashSecret = trim((string) ($input['ip_hash_secret'] ?? ''));

        if (strlen($ipHashSecret) < 16) {
            throw new InvalidArgumentException('IP hash secret must be at least 16 characters.');
        }

        $retentionDays = max(1, (int) ($input['retention_days'] ?? 180));

        $adminUsername = trim((string) ($input['admin_username'] ?? ''));

        if ($adminUsername === '') {
            throw new InvalidArgumentException('Admin username is required.');
        }

        $newPassword = (string) ($input['admin_password'] ?? '');

        if ($newPassword !== '' && strlen($newPassword) < 8) {
            throw new InvalidArgumentException('New admin password must be at least 8 characters.');
        }

        $loginMax = max(1, (int) ($input['login_rate_limit_max'] ?? 5));
        $loginWindow = max(1, (int) ($input['login_rate_limit_window'] ?? 15));
        $blockedIps = $this->validatedIpEntries((string) ($input['blocked_ips'] ?? ''));

        return [
            'env' => [
                'APP_ENV' => $appEnv,
                'APP_URL' => $appUrl,
                'MAILER_DSN' => trim((string) ($input['mailer_dsn'] ?? '')),
                'SMTP_HOST' => trim((string) ($input['smtp_host'] ?? '')),
                'SMTP_PORT' => (string) $smtpPort,
                'SMTP_ENCRYPTION' => $smtpEncryption,
                'SMTP_USERNAME' => trim((string) ($input['smtp_username'] ?? '')),
                'SMTP_PASSWORD' => trim((string) ($input['smtp_password'] ?? '')),
                'MAIL_FROM' => $mailFrom,
                'MAIL_FROM_NAME' => trim((string) ($input['mail_from_name'] ?? '')),
                'TURNSTILE_SECRET' => trim((string) ($input['turnstile_secret'] ?? '')),
                'TURNSTILE_SITE_KEY' => trim((string) ($input['turnstile_site_key'] ?? '')),
                'HCAPTCHA_SECRET' => trim((string) ($input['hcaptcha_secret'] ?? '')),
                'HCAPTCHA_SITE_KEY' => trim((string) ($input['hcaptcha_site_key'] ?? '')),
                'RECAPTCHA_SECRET' => trim((string) ($input['recaptcha_secret'] ?? '')),
                'RECAPTCHA_SITE_KEY' => trim((string) ($input['recaptcha_site_key'] ?? '')),
                'FRIENDLY_CAPTCHA_API_KEY' => trim((string) ($input['friendly_captcha_api_key'] ?? '')),
                'FRIENDLY_CAPTCHA_SITE_KEY' => trim((string) ($input['friendly_captcha_site_key'] ?? '')),
                'DISCORD_WEBHOOK_URL' => trim((string) ($input['discord_webhook_url'] ?? '')),
                'SLACK_WEBHOOK_URL' => trim((string) ($input['slack_webhook_url'] ?? '')),
                'GENERIC_WEBHOOK_URL' => trim((string) ($input['generic_webhook_url'] ?? '')),
                'TELEGRAM_BOT_TOKEN' => trim((string) ($input['telegram_bot_token'] ?? '')),
                'TELEGRAM_CHAT_ID' => trim((string) ($input['telegram_chat_id'] ?? '')),
                'DATABASE_PATH' => $databasePath,
                'IP_HASH_SECRET' => $ipHashSecret,
                'RETENTION_DAYS' => (string) $retentionDays,
                'ADMIN_TOTP_SECRET' => trim((string) ($input['admin_totp_secret'] ?? '')),
                'ADMIN_USERNAME' => $adminUsername,
            ],
            'new_admin_password' => $newPassword,
            'login_rate_limit' => [
                'max' => $loginMax,
                'window_minutes' => $loginWindow,
            ],
            'blocked_ips' => $blockedIps,
        ];
    }

    /** @param array<string, mixed> $settings */
    private function writeSettings(array $settings): void
    {
        $envUpdates = $settings['env'];

        if ($settings['new_admin_password'] !== '') {
            $envUpdates['ADMIN_PASSWORD_HASH'] = password_hash((string) $settings['new_admin_password'], PASSWORD_DEFAULT);
        }

        $this->writeEnvFile($envUpdates);
        $this->writeAdminConfig($settings['login_rate_limit']);
        $this->writeSecurityConfig($settings['blocked_ips']);
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

    private function assertSafeEnvValue(string $field, string $value): void
    {
        if (str_contains($value, "'") || str_contains($value, "\n") || str_contains($value, "\r")) {
            throw new InvalidArgumentException("\"{$field}\" cannot contain a single-quote or a line break.");
        }
    }

    /** @return list<string> */
    private function validatedIpEntries(string $value): array
    {
        $entries = $this->lines($value);

        foreach ($entries as $entry) {
            if (str_contains($entry, '/')) {
                [$subnet, $prefix] = explode('/', $entry, 2);

                if (
                    filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
                    || !ctype_digit($prefix)
                    || (int) $prefix > 32
                ) {
                    throw new InvalidArgumentException('Blocked IP entries must be exact IPs or IPv4 CIDR ranges.');
                }

                continue;
            }

            if (filter_var($entry, FILTER_VALIDATE_IP) === false) {
                throw new InvalidArgumentException('Blocked IP entries must be exact IPs or IPv4 CIDR ranges.');
            }
        }

        return $entries;
    }

    /** @return array<string, string> */
    private function readEnvFile(): array
    {
        if ($this->envPath === null || !is_file($this->envPath)) {
            return [];
        }

        $lines = file($this->envPath, FILE_IGNORE_NEW_LINES);
        $values = [];

        foreach ($lines === false ? [] : $lines as $line) {
            if (preg_match('/^\s*([A-Z0-9_]+)\s*=\s*(.*)\s*$/', $line, $matches) !== 1) {
                continue;
            }

            $value = trim($matches[2]);

            if (
                strlen($value) >= 2
                && (($value[0] === "'" && substr($value, -1) === "'") || ($value[0] === '"' && substr($value, -1) === '"'))
            ) {
                $value = substr($value, 1, -1);
            }

            $values[$matches[1]] = $value;
        }

        return $values;
    }

    /** @param array<string, string> $updates */
    private function writeEnvFile(array $updates): void
    {
        if ($this->envPath === null) {
            return;
        }

        $lines = is_file($this->envPath) ? file($this->envPath, FILE_IGNORE_NEW_LINES) : [];
        $lines = $lines === false ? [] : $lines;
        $written = [];

        foreach ($lines as $index => $line) {
            if (preg_match('/^\s*([A-Z0-9_]+)\s*=/', $line, $matches) !== 1) {
                continue;
            }

            $key = $matches[1];

            if (array_key_exists($key, $updates)) {
                $lines[$index] = $this->envLine($key, (string) $updates[$key]);
                $written[] = $key;
            }
        }

        foreach ($updates as $key => $value) {
            if (!in_array($key, $written, true)) {
                $lines[] = $this->envLine($key, (string) $value);
            }
        }

        file_put_contents($this->envPath, implode(PHP_EOL, $lines) . PHP_EOL);
    }

    private function envLine(string $key, string $value): string
    {
        return $key . "='" . $value . "'";
    }

    /** @return array<string, mixed> */
    private function adminConfig(): array
    {
        if ($this->adminConfigPath === null || !is_file($this->adminConfigPath)) {
            return ['allowed_ips' => $this->configuredIps, 'login_rate_limit' => ['max' => 5, 'window_minutes' => 15]];
        }

        $config = require $this->adminConfigPath;

        return is_array($config) ? $config : [];
    }

    /** @return array<string, string> */
    private function adminRateLimitSettings(): array
    {
        $config = $this->adminConfig();
        $loginRateLimit = $config['login_rate_limit'] ?? [];

        return [
            'login_rate_limit_max' => (string) (int) ($loginRateLimit['max'] ?? 5),
            'login_rate_limit_window' => (string) (int) ($loginRateLimit['window_minutes'] ?? 15),
        ];
    }

    /** @param array{max: int, window_minutes: int} $loginRateLimit */
    private function writeAdminConfig(array $loginRateLimit): void
    {
        if ($this->adminConfigPath === null) {
            return;
        }

        $config = $this->adminConfig();
        $allowedIps = $config['allowed_ips'] ?? $this->configuredIps;

        $content = "<?php\n\n";
        $content .= "declare(strict_types=1);\n\n";
        $content .= "return [\n";
        $content .= "    'allowed_ips' => [\n";

        foreach ($allowedIps as $ip) {
            $content .= "        '" . addslashes((string) $ip) . "',\n";
        }

        $content .= "    ],\n\n";
        $content .= "    'login_rate_limit' => [\n";
        $content .= "        'max' => " . (int) $loginRateLimit['max'] . ",\n";
        $content .= "        'window_minutes' => " . (int) $loginRateLimit['window_minutes'] . ",\n";
        $content .= "    ],\n";
        $content .= "];\n";

        file_put_contents($this->adminConfigPath, $content);

        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($this->adminConfigPath, true);
        }
    }

    /** @return array<string, mixed> */
    private function securityConfig(): array
    {
        if ($this->securityConfigPath === null || !is_file($this->securityConfigPath)) {
            return ['blocked_ips' => []];
        }

        $config = require $this->securityConfigPath;

        return is_array($config) ? $config : [];
    }

    /** @param list<string> $blockedIps */
    private function writeSecurityConfig(array $blockedIps): void
    {
        if ($this->securityConfigPath === null) {
            return;
        }

        $content = "<?php\n\n";
        $content .= "declare(strict_types=1);\n\n";
        $content .= "return [\n";
        $content .= "    'blocked_ips' => [\n";

        foreach ($blockedIps as $ip) {
            $content .= "        '" . addslashes($ip) . "',\n";
        }

        $content .= "    ],\n";
        $content .= "];\n";

        file_put_contents($this->securityConfigPath, $content);

        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($this->securityConfigPath, true);
        }
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

    private function handleRecovery(): array
    {
        $token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
        $settings = $this->currentSettings();
        $hash = (string) ($settings['recovery_token_hash'] ?? '');

        if ($token === '' || $hash === '' || !password_verify($token, $hash)) {
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
            ]);
            $this->recordAudit('recovery.password_reset', 'Bootstrap password reset with recovery token.');

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
        $settings = $this->currentSettings();
        $path = (string) ($settings['database_path'] ?? 'storage/submissions.sqlite');
        $path = str_starts_with($path, '/') ? $path : dirname(__DIR__, 2) . '/' . ltrim($path, '/');

        if (!is_file($path)) {
            return $this->htmlResponse(404, '<h1>Database not found.</h1>');
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

    private function handleConfigExport(): array
    {
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

        if (isset($data['settings']) && is_array($data['settings'])) {
            $settings = $this->settingsFromPost(array_merge($this->currentSettings(), $data['settings']));
            $this->writeSettings($settings);
        }

        if (isset($data['security']['blocked_ips']) && is_array($data['security']['blocked_ips'])) {
            $this->writeSecurityConfig(array_values(array_map('strval', $data['security']['blocked_ips'])));
        }

        if (isset($data['forms']) && is_array($data['forms'])) {
            foreach ($data['forms'] as $formId => $config) {
                if (is_string($formId) && is_array($config)) {
                    $this->formRepository->update($formId, $config);
                }
            }
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

    /**
     * @param array<string, mixed> $input
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function formConfigFromPost(array $input, ?string $fixedFormId = null): array
    {
        $formId = $fixedFormId ?? trim((string) ($input['form_id'] ?? ''));

        if (!preg_match('/^[a-z0-9][a-z0-9_-]{1,63}$/', $formId)) {
            throw new InvalidArgumentException('Form ID must be 2-64 lowercase letters, numbers, dashes, or underscores.');
        }

        $recipient = trim((string) ($input['recipient'] ?? ''));

        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Recipient must be a valid email address.');
        }

        $allowedOrigins = $this->lines((string) ($input['allowed_origins'] ?? ''));

        if ($allowedOrigins === []) {
            throw new InvalidArgumentException('Add at least one allowed origin.');
        }

        foreach ($allowedOrigins as $origin) {
            if (!$this->isHttpUrl($origin)) {
                throw new InvalidArgumentException('Allowed origins must be valid http or https URLs.');
            }
        }

        $subject = trim((string) ($input['subject'] ?? ''));
        $successRedirect = trim((string) ($input['success_redirect'] ?? ''));

        if ($successRedirect !== '' && !$this->isHttpUrl($successRedirect)) {
            throw new InvalidArgumentException('Success redirect must be a valid http or https URL.');
        }

        $rateLimitMax = max(1, (int) ($input['rate_limit_max'] ?? 5));
        $rateLimitWindow = max(1, (int) ($input['rate_limit_window'] ?? 10));
        $dailyLimit = max(1, (int) ($input['daily_limit'] ?? 200));
        $captchaProvider = (string) ($input['captcha_provider'] ?? 'none');

        if (!isset($input['captcha_provider']) && isset($input['turnstile'])) {
            $captchaProvider = 'turnstile';
        }

        if (!in_array($captchaProvider, ['none', 'turnstile', 'hcaptcha', 'recaptcha', 'friendlycaptcha'], true)) {
            throw new InvalidArgumentException('CAPTCHA provider must be a supported option.');
        }

        $config = [
            'recipient' => $recipient,
            'allowed_origins' => $allowedOrigins,
            'subject' => $subject !== '' ? $subject : 'New form submission',
            'captcha_provider' => $captchaProvider,
            'turnstile' => $captchaProvider === 'turnstile',
            'require_api_key' => isset($input['require_api_key']),
            'rate_limit_per_ip' => [
                'max' => $rateLimitMax,
                'window_minutes' => $rateLimitWindow,
            ],
            'daily_limit' => $dailyLimit,
        ];

        if ($successRedirect !== '') {
            $config['success_redirect'] = $successRedirect;
        }

        $blockedPatterns = $this->lines((string) ($input['blocked_patterns'] ?? ''));

        $allowedExtensions = array_map(
            static fn (string $extension): string => strtolower(ltrim($extension, '.')),
            $this->lines((string) ($input['upload_allowed_extensions'] ?? ''))
        );

        foreach ($allowedExtensions as $extension) {
            if (!preg_match('/^[a-z0-9]{1,16}$/', $extension)) {
                throw new InvalidArgumentException('Allowed file extensions must contain only letters and numbers.');
            }
        }

        $uploads = [
            'max_file_size_mb' => min(100, max(1, (int) ($input['upload_max_file_size_mb'] ?? 10))),
            'max_files' => min(20, max(1, (int) ($input['upload_max_files'] ?? 3))),
            'allowed_extensions' => array_values(array_unique($allowedExtensions)),
        ];

        $notificationChannels = is_array($input['notification_channels'] ?? null)
            ? $input['notification_channels']
            : [];
        $allowedChannels = ['discord', 'slack', 'telegram', 'generic'];
        $config['notification_channels'] = array_values(array_unique(array_filter(
            array_map(static fn (mixed $channel): string => (string) $channel, $notificationChannels),
            static fn (string $channel): bool => in_array($channel, $allowedChannels, true)
        )));

        $notificationOverrides = $this->notificationOverridesFromPost($input);

        if ($notificationOverrides !== []) {
            $config['notification_overrides'] = $notificationOverrides;
        }

        if ($blockedPatterns !== []) {
            $config['blocked_patterns'] = $blockedPatterns;
        }

        $config['uploads'] = $uploads;

        return [$formId, $config];
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

    /** @param array<string, mixed> $input @return array<string, string> */
    private function notificationOverridesFromPost(array $input): array
    {
        $fields = [
            'discord_webhook_url',
            'slack_webhook_url',
            'generic_webhook_url',
            'telegram_bot_token',
            'telegram_chat_id',
        ];
        $overrides = [];

        foreach ($fields as $field) {
            $value = trim((string) ($input[$field] ?? ''));
            $this->assertSafeEnvValue($field, $value);

            if ($value !== '') {
                $overrides[$field] = $value;
            }
        }

        foreach (['discord_webhook_url', 'slack_webhook_url', 'generic_webhook_url'] as $field) {
            if (isset($overrides[$field]) && !$this->isHttpUrl($overrides[$field])) {
                throw new InvalidArgumentException('Per-form webhook URLs must be valid http or https URLs.');
            }
        }

        return $overrides;
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

    private function isHttpUrl(string $value): bool
    {
        if (!filter_var($value, FILTER_VALIDATE_URL)) {
            return false;
        }

        $scheme = parse_url($value, PHP_URL_SCHEME);

        return $scheme === 'http' || $scheme === 'https';
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
