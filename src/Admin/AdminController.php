<?php

declare(strict_types=1);

namespace formflow\Admin;

use formflow\AdminAuth;
use formflow\AdminIpWhitelistInterface;
use formflow\AdminUserRepositoryInterface;
use formflow\AdminWhitelistRepositoryInterface;
use formflow\AuditLogRepositoryInterface;
use formflow\Clock;
use formflow\FormApiKeyRepositoryInterface;
use formflow\FormConfigRepositoryInterface;
use formflow\HttpResponse;
use formflow\MailSenderInterface;
use formflow\SubmissionRepositoryInterface;
use formflow\WebhookDeliveryRepositoryInterface;
use InvalidArgumentException;
use Throwable;

final class AdminController
{
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
        private readonly ?string $root = null,
        ?AdminSettingsService $settingsService = null,
        private readonly bool $databaseExistedAtRequestStart = true
    ) {
        $this->settingsService = $settingsService ?? new AdminSettingsService(
            $this->configuredIps,
            $this->envPath,
            $this->adminConfigPath,
            $this->securityConfigPath
        );
    }

    public function handle(string $path): HttpResponse
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        if (!$this->databaseExistedAtRequestStart) {
            $_SESSION['database_was_recreated'] = true;
        }

        $clientIp = $this->clientIp();

        if ($clientIp === null || !$this->ipWhitelist->isAllowed($clientIp)) {
            return HttpResponse::fromArray($this->htmlResponse(403, '<h1>Forbidden</h1>'));
        }

        if ($path === 'admin/logout') {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                return HttpResponse::redirect('/admin/login');
            }

            if (!$this->verifyCsrfToken()) {
                return HttpResponse::fromArray($this->htmlResponse(419, '<h1>Invalid CSRF token.</h1>'));
            }

            $this->auth->logout();

            return HttpResponse::redirect('/admin/login');
        }

        if ($path === 'admin/login') {
            return HttpResponse::fromArray($this->handleLogin());
        }

        if ($path === 'admin/recovery') {
            return HttpResponse::fromArray($this->handleRecovery());
        }

        if (!$this->auth->isLoggedIn()) {
            return HttpResponse::redirect('/admin/login');
        }

        return HttpResponse::fromArray($this->dispatchAuthenticatedRoute($path));
    }

    /** @return array{status: int, body: mixed, redirect?: string|null, headers?: array<string, string>} */
    private function dispatchAuthenticatedRoute(string $path): array
    {
        $exactRoutes = [
            'admin' => fn (): array => $this->submissionController()->dashboard(),
            'admin/analytics' => fn (): array => $this->submissionController()->analytics(),
            'admin/export' => fn (): array => $this->submissionController()->export(),
            'admin/submissions/bulk' => fn (): array => $this->submissionController()->bulkAction(),
            'admin/delivery' => fn (): array => $this->submissionController()->delivery(),
            'admin/system' => $this->handleSystem(...),
            'admin/whitelist' => $this->handleWhitelist(...),
            'admin/forms' => fn (): array => $this->formController()->index(),
            'admin/forms/new' => fn (): array => $this->formController()->create(),
            'admin/settings' => fn (): array => $this->settingsController()->settings(),
            'admin/integrations' => fn (): array => $this->settingsController()->integrations(),
            'admin/users' => $this->handleUsers(...),
            'admin/audit' => $this->handleAudit(...),
            'admin/backup' => $this->handleBackup(...),
            'admin/config/export' => $this->handleConfigExport(...),
            'admin/config/import' => $this->handleConfigImport(...),
        ];

        if (isset($exactRoutes[$path])) {
            return $exactRoutes[$path]();
        }

        $patternRoutes = [
            '#^admin/submissions/(\d+)$#' => fn (array $matches): array => $this->submissionController()->detail((int) $matches[1]),
            '#^admin/submissions/(\d+)/uploads/([^/]+)$#' => fn (array $matches): array => $this->submissionController()->download((int) $matches[1], rawurldecode((string) $matches[2])),
            '#^admin/submissions/(\d+)/action$#' => fn (array $matches): array => $this->submissionController()->action((int) $matches[1]),
            '#^admin/delivery/(\d+)/replay$#' => fn (array $matches): array => $this->submissionController()->replayWebhook((int) $matches[1]),
            '#^admin/forms/([^/]+)/edit$#' => fn (array $matches): array => $this->formController()->edit((string) $matches[1]),
            '#^admin/forms/([^/]+)/delete$#' => fn (array $matches): array => $this->formController()->delete((string) $matches[1]),
        ];

        foreach ($patternRoutes as $pattern => $handler) {
            if (preg_match($pattern, $path, $matches) === 1) {
                return $handler($matches);
            }
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

    private function render(string $view, array $data, string $title, bool $withNav = true): string
    {
        return $this->renderer()->render($view, $data, $title, $withNav);
    }

    private function configTransferService(): AdminConfigTransferService
    {
        return new AdminConfigTransferService($this->settingsService);
    }

    private function submissionController(): AdminSubmissionController
    {
        return new AdminSubmissionController(
            $this->submissions,
            $this->mailSender,
            $this->forms,
            $this->webhookDeliveries,
            $this->resolvedUploadDirectory(),
            $this->auditLog,
            $this->auth,
            $this->renderer()
        );
    }

    private function formController(): AdminFormController
    {
        return new AdminFormController(
            $this->forms,
            $this->apiKeys,
            $this->formRepository,
            $this->settingsService,
            $this->auditLog,
            $this->auth,
            $this->renderer()
        );
    }

    private function settingsController(): AdminSettingsController
    {
        return new AdminSettingsController(
            $this->settingsService,
            $this->submissions,
            $this->mailSender,
            count($this->forms),
            $this->root(),
            $this->auditLog,
            $this->auth,
            $this->renderer()
        );
    }

    private function renderer(): AdminViewRenderer
    {
        return new AdminViewRenderer();
    }

    /** @return array<string, mixed> */
    private function currentSettings(): array
    {
        return $this->settingsService->currentSettings();
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
        $system = new AdminSystemService(
            $this->settingsService,
            $this->submissions,
            $this->webhookDeliveries,
            count($this->forms),
            $this->resolvedUploadDirectory(),
            $this->root(),
            $this->databaseWasRecreatedDuringAdminSession()
        );

        return $this->htmlResponse(200, $this->render('system', [
            'status' => $system->status(),
            'warnings' => $system->warnings(),
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
        $backup = new AdminBackupService($this->root());
        $path = $backup->databasePath($path);

        if ($path === null) {
            return $this->htmlResponse(403, '<h1>Backup path is not allowed.</h1>');
        }

        if (!is_file($path)) {
            return $this->htmlResponse(404, '<h1>Database not found.</h1>');
        }

        if (!$backup->isSqliteDatabase($path)) {
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

        return $expiresTimestamp === false || $expiresTimestamp < Clock::nowTimestamp();
    }

    private function resolvedUploadDirectory(): string
    {
        return $this->uploadDirectory !== ''
            ? $this->uploadDirectory
            : $this->root() . '/storage/uploads';
    }

    private function root(): string
    {
        return $this->root ?? dirname(__DIR__, 2);
    }

    private function handleConfigExport(): array
    {
        if (!$this->verifyCsrfToken()) {
            return $this->htmlResponse(403, '<h1>Forbidden</h1>');
        }

        $data = $this->configTransferService()->exportData(
            $this->currentSettings(),
            $this->forms,
            $this->securityConfig()
        );
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

        try {
            $prepared = $this->configTransferService()->prepareImport($data, $this->currentSettings(), $this->securityConfig());
        } catch (InvalidArgumentException $exception) {
            return $this->htmlResponse(422, '<h1>' . htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8') . '</h1>');
        }

        $rollback = [
            'settings' => $this->currentSettings(),
            'security' => $this->securityConfig(),
            'forms' => $this->formRepository->all(),
        ];

        try {
            if ($prepared['settings'] !== null) {
                $this->settingsService->writeSettings($prepared['settings']);
                $this->recordAudit('settings.update', 'Updated global settings.');
            }

            if ($prepared['security'] !== null) {
                $this->writeSecurityConfig(
                    $prepared['security']['blocked_ips'],
                    $prepared['security']['trusted_proxies'],
                    $prepared['security']['trusted_ip_headers']
                );
            }

            foreach ($prepared['forms'] as $formId => $config) {
                $this->formRepository->update($formId, $config);
            }
        } catch (Throwable $exception) {
            $this->restoreImportedConfig($rollback);

            return $this->htmlResponse(
                422,
                '<h1>' . htmlspecialchars('Config import failed and was rolled back: ' . $exception->getMessage(), ENT_QUOTES, 'UTF-8') . '</h1>'
            );
        }

        $this->recordAudit('config.import', 'Imported configuration.');

        return ['status' => 302, 'body' => '', 'redirect' => '/admin/settings?saved=1'];
    }

    /**
     * @param array{
     *     settings: array<string, mixed>,
     *     security: array<string, mixed>,
     *     forms: array<string, array<string, mixed>>
     * } $snapshot
     */
    private function restoreImportedConfig(array $snapshot): void
    {
        try {
            $settings = $this->settingsService->settingsFromInput($snapshot['settings']);
            $this->settingsService->writeSettings($settings);
            $security = $this->settingsService->securityFromConfig($snapshot['security']);
            $this->writeSecurityConfig(
                $security['blocked_ips'],
                $security['trusted_proxies'],
                $security['trusted_ip_headers']
            );

            $currentForms = $this->formRepository->all();

            foreach (array_keys($currentForms) as $formId) {
                if (!array_key_exists($formId, $snapshot['forms'])) {
                    $this->formRepository->delete((string) $formId);
                }
            }

            foreach ($snapshot['forms'] as $formId => $config) {
                $this->formRepository->update($formId, $config);
            }
        } catch (Throwable $rollbackException) {
            error_log('Config import rollback failed: ' . $rollbackException->getMessage());
        }
    }

    private function recordAudit(string $action, string $detail): void
    {
        $this->auditLog?->record($this->auth->username(), $action, $detail);
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
        // setup in this project) makes every request look loopback unless real_ip
        // is configured. The PHP built-in server is an explicit local runtime,
        // while every other SAPI still requires the non-production opt-in.
        if (!$this->devLoginEnabled && PHP_SAPI !== 'cli-server') {
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

    private function databaseWasRecreatedDuringAdminSession(): bool
    {
        return !empty($_SESSION['database_was_recreated']);
    }

    private function ipHash(?string $ip): ?string
    {
        if ($ip === null) {
            return null;
        }

        return hash_hmac('sha256', $ip . '|' . Clock::currentMonth(), $this->ipHashSecret);
    }

    private function htmlResponse(int $status, string $body): array
    {
        return ['status' => $status, 'body' => $body, 'redirect' => null];
    }
}
