<?php

declare(strict_types=1);

namespace formflow\Install;

final class InstallController
{
    private const REQUIRED_FIELDS = [
        'admin_username' => 'Admin username',
    ];

    private const RAW_STRING_FIELDS = [
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
        'admin_username',
    ];

    private const REQUIRED_EXTENSIONS = ['curl', 'mbstring', 'pdo_sqlite'];

    public function __construct(
        private readonly string $envPath,
        private readonly string $adminConfigPath,
        private readonly ?string $clientIp
    ) {
    }

    public function handle(): array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        $checks = $this->preflightChecks();
        $checksPass = !in_array(false, array_column($checks, 'ok'), true);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return $this->htmlResponse(
                $checksPass ? 200 : 503,
                $this->renderForm(null, ['app_url' => $this->detectAppUrl()], $checks, $checksPass)
            );
        }

        if (!$checksPass) {
            return $this->htmlResponse(503, $this->renderForm(null, $_POST, $checks, false));
        }

        if (!$this->verifyCsrfToken()) {
            return $this->htmlResponse(419, $this->renderForm('Invalid CSRF token.', $_POST, $checks, true));
        }

        $error = $this->validate($_POST);

        if ($error !== null) {
            return $this->htmlResponse(422, $this->renderForm($error, $_POST, $checks, true));
        }

        $this->writeEnv($_POST);
        $this->addAllowedIp();

        return ['status' => 302, 'body' => '', 'redirect' => '/admin/login'];
    }

    private function validate(array $input): ?string
    {
        foreach (self::REQUIRED_FIELDS as $field => $label) {
            if (trim((string) ($input[$field] ?? '')) === '') {
                return "\"{$label}\" is required.";
            }
        }

        if (strlen((string) ($input['admin_password'] ?? '')) < 8) {
            return 'Admin password must be at least 8 characters.';
        }

        foreach (self::RAW_STRING_FIELDS as $field) {
            $value = (string) ($input[$field] ?? '');

            if (str_contains($value, "'") || str_contains($value, "\n") || str_contains($value, "\r")) {
                return "\"{$field}\" cannot contain a single-quote or a line break.";
            }
        }

        $smtpPort = trim((string) ($input['smtp_port'] ?? ''));

        if ($smtpPort !== '' && (!ctype_digit($smtpPort) || (int) $smtpPort < 1 || (int) $smtpPort > 65535)) {
            return 'SMTP port must be between 1 and 65535.';
        }

        $smtpEncryption = trim((string) ($input['smtp_encryption'] ?? 'tls'));

        if (!in_array($smtpEncryption, ['tls', 'ssl', 'none'], true)) {
            return 'SMTP encryption must be TLS, SSL, or None.';
        }

        return null;
    }

    private function writeEnv(array $input): void
    {
        $appUrl = trim((string) ($input['app_url'] ?? ''));
        $mailerDsn = trim((string) ($input['mailer_dsn'] ?? ''));
        $smtpHost = trim((string) ($input['smtp_host'] ?? ''));
        $smtpPort = trim((string) ($input['smtp_port'] ?? '587'));
        $smtpEncryption = trim((string) ($input['smtp_encryption'] ?? 'tls'));
        $smtpUsername = trim((string) ($input['smtp_username'] ?? ''));
        $smtpPassword = trim((string) ($input['smtp_password'] ?? ''));
        $mailFrom = trim((string) ($input['mail_from'] ?? ''));

        $mailFromName = trim((string) ($input['mail_from_name'] ?? ''));
        $mailFromName = $mailFromName !== '' ? $mailFromName : 'formflow';

        $turnstileSecret = trim((string) ($input['turnstile_secret'] ?? ''));
        $passwordHash = password_hash((string) $input['admin_password'], PASSWORD_DEFAULT);
        $ipHashSecret = bin2hex(random_bytes(32));

        $lines = [
            "APP_ENV='production'",
            "APP_URL='" . $appUrl . "'",
            '',
            "MAILER_DSN='" . $mailerDsn . "'",
            "SMTP_HOST='" . $smtpHost . "'",
            "SMTP_PORT='" . $smtpPort . "'",
            "SMTP_ENCRYPTION='" . $smtpEncryption . "'",
            "SMTP_USERNAME='" . $smtpUsername . "'",
            "SMTP_PASSWORD='" . $smtpPassword . "'",
            "MAIL_FROM='" . $mailFrom . "'",
            "MAIL_FROM_NAME='" . $mailFromName . "'",
            '',
            "TURNSTILE_SECRET='" . $turnstileSecret . "'",
            "TURNSTILE_SITE_KEY=''",
            "DISCORD_WEBHOOK_URL=''",
            "SLACK_WEBHOOK_URL=''",
            '',
            "DATABASE_PATH='storage/submissions.sqlite'",
            "IP_HASH_SECRET='" . $ipHashSecret . "'",
            "RETENTION_DAYS='180'",
            '',
            "ADMIN_USERNAME='" . $input['admin_username'] . "'",
            "ADMIN_PASSWORD_HASH='" . $passwordHash . "'",
            "ADMIN_TOTP_SECRET=''",
            "RECOVERY_TOKEN_HASH=''",
            '',
        ];

        file_put_contents($this->envPath, implode(PHP_EOL, $lines));
    }

    private function addAllowedIp(): void
    {
        $config = is_file($this->adminConfigPath) ? require $this->adminConfigPath : [];
        $allowedIps = $config['allowed_ips'] ?? [];

        if ($this->clientIp !== null && !in_array($this->clientIp, $allowedIps, true)) {
            $allowedIps[] = $this->clientIp;
        }

        $loginMax = (int) ($config['login_rate_limit']['max'] ?? 5);
        $loginWindow = (int) ($config['login_rate_limit']['window_minutes'] ?? 15);

        $ipLines = implode(PHP_EOL, array_map(
            static fn (string $ip): string => "        '" . addslashes($ip) . "',",
            $allowedIps
        ));

        $content = <<<PHP
        <?php

        declare(strict_types=1);

        return [
            'allowed_ips' => [
        {$ipLines}
            ],

            'login_rate_limit' => [
                'max' => {$loginMax},
                'window_minutes' => {$loginWindow},
            ],
        ];

        PHP;

        file_put_contents($this->adminConfigPath, $content);

        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($this->adminConfigPath, true);
        }
    }

    /** @return list<array{label: string, detail: string, ok: bool}> */
    private function preflightChecks(): array
    {
        $checks = [];

        foreach (self::REQUIRED_EXTENSIONS as $extension) {
            $checks[] = [
                'label' => "PHP extension: {$extension}",
                'detail' => extension_loaded($extension) ? 'loaded' : 'not loaded',
                'ok' => extension_loaded($extension),
            ];
        }

        $checks[] = [
            'label' => 'Write .env',
            'detail' => $this->envPath,
            'ok' => $this->isWritableTarget($this->envPath),
        ];

        $checks[] = [
            'label' => 'Write config/admin.php',
            'detail' => $this->adminConfigPath,
            'ok' => $this->isWritableTarget($this->adminConfigPath),
        ];

        return $checks;
    }

    private function isWritableTarget(string $path): bool
    {
        return is_writable(is_file($path) ? $path : dirname($path));
    }

    private function detectAppUrl(): string
    {
        $forwardedProto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? null;
        $isHttps = $forwardedProto !== null
            ? $forwardedProto === 'https'
            : (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

        return ($isHttps ? 'https' : 'http') . '://' . $host;
    }

    private function renderForm(?string $error, array $old, array $checks, bool $checksPass): string
    {
        return $this->render('install', [
            'error' => $error,
            'old' => $old,
            'checks' => $checks,
            'checksPass' => $checksPass,
            'csrfToken' => $_SESSION['csrf_token'],
            'containerClass' => 'setup',
        ], 'Setup', withNav: false);
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

    private function verifyCsrfToken(): bool
    {
        $token = (string) ($_POST['csrf_token'] ?? '');

        return hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $token);
    }

    private function htmlResponse(int $status, string $body): array
    {
        return ['status' => $status, 'body' => $body, 'redirect' => null];
    }
}
