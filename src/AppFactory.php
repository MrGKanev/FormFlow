<?php

declare(strict_types=1);

namespace formflow;

use formflow\Admin\AdminController;
use formflow\Admin\AdminSettingsService;
use formflow\Install\InstallController;

final class AppFactory
{
    public function __construct(private readonly string $root)
    {
    }

    public function installController(): InstallController
    {
        return new InstallController(
            $this->root . '/.env',
            $this->root . '/config/admin.php',
            $_SERVER['REMOTE_ADDR'] ?? null
        );
    }

    /** @param array<string, mixed> $security */
    public function clientIp(array $security): ?string
    {
        $trustedHeaders = $security['trusted_ip_headers'] ?? [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
        ];

        return (new ClientIpResolver(
            array_values(array_filter(array_map('strval', $security['trusted_proxies'] ?? []))),
            array_values(array_filter(array_map('strval', $trustedHeaders)))
        ))->resolve($_SERVER);
    }

    public function databasePath(): string
    {
        $databasePath = getenv('DATABASE_PATH') ?: 'storage/submissions.sqlite';

        if (!str_starts_with($databasePath, '/')) {
            $databasePath = $this->root . '/' . ltrim($databasePath, '/');
        }

        return $databasePath;
    }

    public function databaseFileExists(): bool
    {
        $databasePath = $this->databasePath();

        return $databasePath === ':memory:' || is_file($databasePath);
    }

    public function ipHashSecret(): string
    {
        return getenv('IP_HASH_SECRET') ?: hash('sha256', getenv('ADMIN_PASSWORD_HASH') ?: bin2hex(random_bytes(32)));
    }

    /** @return array<string, mixed> */
    public function security(): array
    {
        $security = require $this->root . '/config/security.php';

        return is_array($security) ? $security : [];
    }

    /** @return array<string, array<string, mixed>> */
    public function forms(): array
    {
        $configuredForms = require $this->root . '/config/forms.php';
        $configuredForms = is_array($configuredForms) ? $configuredForms : [];

        return array_merge($configuredForms, $this->formRepository()->all());
    }

    public function adminController(array $forms, ?string $clientIp, bool $databaseExistedAtRequestStart = true): AdminController
    {
        $databasePath = $this->databasePath();
        $adminConfig = require $this->root . '/config/admin.php';
        $adminConfig = is_array($adminConfig) ? $adminConfig : [];
        $allowedIps = is_array($adminConfig['allowed_ips'] ?? null) ? $adminConfig['allowed_ips'] : [];
        $whitelistRepository = new SqliteAdminWhitelistRepository($databasePath);
        $adminUsers = new SqliteAdminUserRepository($databasePath);
        $auditLog = new SqliteAuditLogRepository($databasePath);

        return new AdminController(
            new AdminAuth(
                getenv('ADMIN_USERNAME') ?: 'admin',
                getenv('ADMIN_PASSWORD_HASH') ?: '',
                new SqliteRateLimiter($databasePath),
                (int) ($adminConfig['login_rate_limit']['max'] ?? 5),
                (int) ($adminConfig['login_rate_limit']['window_minutes'] ?? 15),
                $adminUsers,
                getenv('ADMIN_TOTP_SECRET') ?: '',
                new SqliteTotpReplayGuard($databasePath)
            ),
            new AdminIpWhitelist($allowedIps, $whitelistRepository),
            new SqliteSubmissionRepository($databasePath, $this->uploadDirectory()),
            $whitelistRepository,
            array_values(array_map('strval', $allowedIps)),
            $this->ipHashSecret(),
            new SqliteFormApiKeyRepository($databasePath),
            $forms,
            $this->formRepository(),
            (getenv('APP_ENV') ?: 'production') !== 'production',
            $this->root . '/.env',
            $this->root . '/config/admin.php',
            $this->root . '/config/security.php',
            $adminUsers,
            $auditLog,
            $this->mailService(),
            new SqliteWebhookDeliveryRepository($databasePath),
            $clientIp,
            $this->uploadDirectory(),
            $this->root,
            new AdminSettingsService(
                array_values(array_map('strval', $allowedIps)),
                $this->root . '/.env',
                $this->root . '/config/admin.php',
                $this->root . '/config/security.php'
            ),
            $databaseExistedAtRequestStart
        );
    }

    public function formHandler(array $forms, ?string $clientIp): FormHandler
    {
        $databasePath = $this->databasePath();

        return new FormHandler(
            $forms,
            $this->mailService(),
            new SqliteSubmissionRepository($databasePath, $this->uploadDirectory()),
            new Turnstile(getenv('TURNSTILE_SECRET') ?: ''),
            new SqliteRateLimiter($databasePath),
            $this->ipHashSecret(),
            new SqliteFormApiKeyRepository($databasePath),
            $this->webhookNotifier(),
            $this->uploadDirectory(),
            new CurlCaptchaVerifier([
                'hcaptcha_secret' => getenv('HCAPTCHA_SECRET') ?: '',
                'recaptcha_secret' => getenv('RECAPTCHA_SECRET') ?: '',
                'friendly_captcha_api_key' => getenv('FRIENDLY_CAPTCHA_API_KEY') ?: '',
                'friendly_captcha_site_key' => getenv('FRIENDLY_CAPTCHA_SITE_KEY') ?: '',
            ]),
            $clientIp,
            (getenv('MAIL_DELIVERY_MODE') ?: 'sync') === 'queue'
        );
    }

    public function formRepository(): SqliteFormRepository
    {
        return new SqliteFormRepository($this->databasePath());
    }

    public function uploadDirectory(): string
    {
        return $this->root . '/storage/uploads';
    }

    private function mailService(): MailService
    {
        return new MailService(
            $this->mailerDsn(),
            getenv('MAIL_FROM') ?: '',
            getenv('MAIL_FROM_NAME') ?: 'formflow'
        );
    }

    private function webhookNotifier(): CurlWebhookNotifier
    {
        $databasePath = $this->databasePath();

        return new CurlWebhookNotifier(
            getenv('DISCORD_WEBHOOK_URL') ?: null,
            getenv('SLACK_WEBHOOK_URL') ?: null,
            getenv('GENERIC_WEBHOOK_URL') ?: null,
            getenv('TELEGRAM_BOT_TOKEN') ?: null,
            getenv('TELEGRAM_CHAT_ID') ?: null,
            new SqliteWebhookDeliveryRepository($databasePath),
            null,
            (getenv('WEBHOOK_DELIVERY_MODE') ?: 'sync') === 'queue'
        );
    }

    private function mailerDsn(): string
    {
        $advancedDsn = trim((string) (getenv('MAILER_DSN') ?: ''));

        if ($advancedDsn !== '') {
            return $advancedDsn;
        }

        $host = trim((string) (getenv('SMTP_HOST') ?: ''));

        if ($host === '') {
            return 'null://null';
        }

        $port = (int) (getenv('SMTP_PORT') ?: 587);
        $encryption = strtolower(trim((string) (getenv('SMTP_ENCRYPTION') ?: 'tls')));
        $scheme = $encryption === 'ssl' ? 'smtps' : 'smtp';
        $username = (string) (getenv('SMTP_USERNAME') ?: '');
        $password = (string) (getenv('SMTP_PASSWORD') ?: '');
        $auth = $username !== ''
            ? rawurlencode($username) . ':' . rawurlencode($password) . '@'
            : '';
        $query = $encryption === 'none' ? '?auto_tls=0' : ($encryption === 'tls' ? '?require_tls=1' : '');

        return sprintf('%s://%s%s:%d%s', $scheme, $auth, $host, $port, $query);
    }
}
