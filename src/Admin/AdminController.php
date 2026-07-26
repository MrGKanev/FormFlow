<?php

declare(strict_types=1);

namespace formflow\Admin;

use formflow\AdminAuth;
use formflow\AdminIpWhitelistInterface;
use formflow\AdminWhitelistRepositoryInterface;
use formflow\FormApiKeyRepositoryInterface;
use formflow\FormConfigRepositoryInterface;
use formflow\SubmissionRepositoryInterface;
use InvalidArgumentException;

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
        private readonly ?string $securityConfigPath = null
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

        if (!$this->auth->isLoggedIn()) {
            return ['status' => 302, 'body' => '', 'redirect' => '/admin/login'];
        }

        if ($path === 'admin') {
            return $this->handleDashboard();
        }

        if (preg_match('#^admin/submissions/(\d+)$#', $path, $matches) === 1) {
            return $this->handleSubmissionDetail((int) $matches[1]);
        }

        if ($path === 'admin/whitelist') {
            return $this->handleWhitelist();
        }

        if ($path === 'admin/api-keys') {
            return $this->handleApiKeys();
        }

        if ($path === 'admin/forms') {
            return $this->handleForms();
        }

        if ($path === 'admin/settings') {
            return $this->handleSettings();
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
            $this->auth->login();

            return ['status' => 302, 'body' => '', 'redirect' => '/admin'];
        }

        $username = (string) ($_POST['username'] ?? '');
        $password = (string) ($_POST['password'] ?? '');
        $ipHash = $this->ipHash($this->clientIp());

        $result = $this->auth->attemptLogin($username, $password, $ipHash);

        if ($result === 'locked') {
            return $this->htmlResponse(429, $this->renderLogin('Too many attempts. Try again later.'));
        }

        if ($result === 'invalid') {
            return $this->htmlResponse(401, $this->renderLogin('Invalid username or password.'));
        }

        session_regenerate_id(true);
        $this->auth->login();

        return ['status' => 302, 'body' => '', 'redirect' => '/admin'];
    }

    private function handleDashboard(): array
    {
        $formId = isset($_GET['form_id']) && $_GET['form_id'] !== '' ? (string) $_GET['form_id'] : null;
        $status = isset($_GET['status']) && $_GET['status'] !== '' ? (string) $_GET['status'] : null;
        $page = max(1, (int) ($_GET['page'] ?? 1));

        $submissions = $this->submissions->findPaginated($formId, $status, $page, self::PER_PAGE);
        $total = $this->submissions->count($formId, $status);

        return $this->htmlResponse(200, $this->render('dashboard', [
            'submissions' => $submissions,
            'total' => $total,
            'page' => $page,
            'perPage' => self::PER_PAGE,
            'formId' => $formId,
            'status' => $status,
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

    private function handleApiKeys(): array
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!$this->verifyCsrfToken()) {
                return $this->htmlResponse(419, '<h1>Invalid CSRF token.</h1>');
            }

            $formId = (string) ($_POST['form_id'] ?? '');

            if (!in_array($formId, array_keys($this->forms), true)) {
                return $this->htmlResponse(422, '<h1>Unknown form id.</h1>');
            }

            $this->apiKeys->regenerate($formId);

            return ['status' => 302, 'body' => '', 'redirect' => '/admin/api-keys'];
        }

        return $this->htmlResponse(200, $this->renderApiKeys());
    }

    private function renderLogin(?string $error): string
    {
        return $this->render('login', [
            'error' => $error,
            'csrfToken' => $_SESSION['csrf_token'],
            'isLocal' => $this->canUseDevBypass(),
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

    private function renderApiKeys(): string
    {
        $generated = $this->apiKeys->all();

        $keys = [];

        foreach (array_keys($this->forms) as $formId) {
            $keys[$formId] = $generated[$formId] ?? null;
        }

        return $this->render('api-keys', [
            'keys' => $keys,
            'csrfToken' => $_SESSION['csrf_token'],
        ], 'API keys');
    }

    private function handleForms(): array
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
            } catch (InvalidArgumentException $exception) {
                return $this->htmlResponse(422, $this->renderForms($exception->getMessage(), $_POST));
            }

            return ['status' => 302, 'body' => '', 'redirect' => '/admin/forms'];
        }

        return $this->htmlResponse(200, $this->renderForms(null, []));
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
        return $this->render('forms', [
            'error' => $error,
            'forms' => $this->forms,
            'csrfToken' => $_SESSION['csrf_token'],
            'values' => $values,
        ], 'Forms');
    }

    private function handleSettings(): array
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!$this->verifyCsrfToken()) {
                return $this->htmlResponse(419, '<h1>Invalid CSRF token.</h1>');
            }

            try {
                $settings = $this->settingsFromPost($_POST);
                $this->writeSettings($settings);
            } catch (InvalidArgumentException $exception) {
                return $this->htmlResponse(422, $this->renderSettings($exception->getMessage(), $_POST, false));
            }

            return ['status' => 302, 'body' => '', 'redirect' => '/admin/settings?saved=1'];
        }

        return $this->htmlResponse(200, $this->renderSettings(null, [], ($_GET['saved'] ?? null) === '1'));
    }

    private function renderSettings(?string $error, array $values, bool $saved): string
    {
        return $this->render('settings', [
            'error' => $error,
            'saved' => $saved,
            'settings' => $values !== [] ? $values : $this->currentSettings(),
            'csrfToken' => $_SESSION['csrf_token'],
        ], 'Settings');
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
            'database_path' => $env['DATABASE_PATH'] ?? (getenv('DATABASE_PATH') ?: 'storage/submissions.sqlite'),
            'ip_hash_secret' => $env['IP_HASH_SECRET'] ?? (getenv('IP_HASH_SECRET') ?: ''),
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
            'database_path',
            'ip_hash_secret',
            'admin_username',
        ];

        foreach ($rawFields as $field) {
            $this->assertSafeEnvValue($field, (string) ($input[$field] ?? ''));
        }

        $appUrl = trim((string) ($input['app_url'] ?? ''));

        if ($appUrl !== '' && !$this->isHttpUrl($appUrl)) {
            throw new InvalidArgumentException('App URL must be a valid http or https URL.');
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
                'DATABASE_PATH' => $databasePath,
                'IP_HASH_SECRET' => $ipHashSecret,
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

    /**
     * @param array<string, mixed> $input
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function formConfigFromPost(array $input): array
    {
        $formId = trim((string) ($input['form_id'] ?? ''));

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

        $config = [
            'recipient' => $recipient,
            'allowed_origins' => $allowedOrigins,
            'subject' => $subject !== '' ? $subject : 'New form submission',
            'turnstile' => isset($input['turnstile']),
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

        if ($blockedPatterns !== []) {
            $config['blocked_patterns'] = $blockedPatterns;
        }

        return [$formId, $config];
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
