<?php

declare(strict_types=1);

use formflow\Admin\AdminController;
use formflow\AdminAuth;
use formflow\AdminIpWhitelist;
use formflow\CurlCaptchaVerifier;
use formflow\CurlWebhookNotifier;
use formflow\ClientIpResolver;
use formflow\SqliteWebhookDeliveryRepository;
use formflow\FormHandler;
use formflow\HttpSecurity;
use formflow\Install\InstallController;
use formflow\IpBlocklist;
use formflow\MailService;
use formflow\SqliteAdminWhitelistRepository;
use formflow\SqliteAdminUserRepository;
use formflow\SqliteAuditLogRepository;
use formflow\SqliteFormApiKeyRepository;
use formflow\SqliteFormRepository;
use formflow\SqliteRateLimiter;
use formflow\SqliteSubmissionRepository;
use formflow\SqliteTotpReplayGuard;
use formflow\Turnstile;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);

if (is_file($root . '/.env')) {
    (new Dotenv())->usePutenv()->load($root . '/.env');
}

function requestIsHttps(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? null) === 'https');
}

function clientIpResolver(array $security): ClientIpResolver
{
    $trustedHeaders = $security['trusted_ip_headers'] ?? [
        'HTTP_CF_CONNECTING_IP',
        'HTTP_X_FORWARDED_FOR',
        'HTTP_X_REAL_IP',
    ];

    return new ClientIpResolver(
        array_values(array_filter(array_map('strval', $security['trusted_proxies'] ?? []))),
        array_values(array_filter(array_map('strval', $trustedHeaders)))
    );
}

HttpSecurity::hardenSessionCookies(requestIsHttps());
HttpSecurity::sendHeaders(requestIsHttps());

function wantsJsonResponse(): bool
{
    if (($_GET['format'] ?? null) === 'json') {
        return true;
    }

    $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));

    return str_contains($accept, 'application/json') && !str_contains($accept, 'text/html');
}

function databasePath(string $root): string
{
    $databasePath = getenv('DATABASE_PATH') ?: 'storage/submissions.sqlite';

    if (!str_starts_with($databasePath, '/')) {
        $databasePath = $root . '/' . ltrim($databasePath, '/');
    }

    return $databasePath;
}

function mailerDsnFromEnv(): string
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

function renderHealthPage(array $health): string
{
    return <<<HTML
    <!DOCTYPE html>
    <html lang="en" data-theme="light">
    <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>formflow health</title>
    <script>
    try {
        document.documentElement.dataset.theme = localStorage.getItem('formflow-theme') === 'dark' ? 'dark' : 'light';
    } catch (error) {
        document.documentElement.dataset.theme = 'light';
    }
    </script>
    <link rel="stylesheet" href="/assets/style.css">
    <script src="/assets/theme.js" defer></script>
    </head>
    <body>
    <div class="theme-corner">
        <button type="button" class="theme-toggle" data-theme-toggle aria-label="Switch to dark theme" aria-pressed="false">
            <span class="theme-toggle-icon" aria-hidden="true"></span>
            <span data-theme-label>Dark</span>
        </button>
    </div>
    <main class="container">
        <div class="page-header">
            <div>
                <p class="page-kicker">Public status</p>
                <h1>Health check</h1>
                <p class="page-meta">A public liveness check for formflow.</p>
            </div>
        </div>

        <section class="panel health-status">
            <div>
                <h2>Service status</h2>
                <p class="page-meta">Checked at <time datetime="{$health['time']}">{$health['time']}</time></p>
            </div>
            <span class="badge good">Available</span>
        </section>
    </main>
    </body>
    </html>
    HTML;
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$formId = trim((string) $path, '/');

$envExists = is_file($root . '/.env');

if ($formId === 'install') {
    if ($envExists) {
        http_response_code(403);
        header('Content-Type: text/html; charset=utf-8');
        echo '<h1>Already installed.</h1>';
        exit;
    }

    $installController = new InstallController(
        $root . '/.env',
        $root . '/config/admin.php',
        $_SERVER['REMOTE_ADDR'] ?? null
    );

    $result = $installController->handle();

    http_response_code($result['status']);

    if (!empty($result['redirect'])) {
        header('Location: ' . $result['redirect'], true, 302);
        exit;
    }

    header('Content-Type: text/html; charset=utf-8');
    echo $result['body'];
    exit;
}

if (!$envExists && $formId !== 'health') {
    header('Location: /install', true, 302);
    exit;
}

if ($formId === '') {
    $isLocalhost = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);

    header('Content-Type: text/html; charset=utf-8');
    echo <<<'HTML'
    <!DOCTYPE html>
    <html lang="en" data-theme="light">
    <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>formflow</title>
    <script>
    try {
        document.documentElement.dataset.theme = localStorage.getItem('formflow-theme') === 'dark' ? 'dark' : 'light';
    } catch (error) {
        document.documentElement.dataset.theme = 'light';
    }
    </script>
    <link rel="stylesheet" href="/assets/style.css">
    <script src="/assets/theme.js" defer></script>
    </head>
    <body>
    <div class="theme-corner">
        <button type="button" class="theme-toggle" data-theme-toggle aria-label="Switch to dark theme" aria-pressed="false">
            <span class="theme-toggle-icon" aria-hidden="true"></span>
            <span data-theme-label>Dark</span>
        </button>
    </div>
    <main class="container home-shell">
        <section class="home-hero">
            <p class="home-kicker">Self-hosted form backend</p>
            <h1>formflow</h1>
            <p class="tagline">A clean endpoint for static-site forms with selectable CAPTCHA providers, per-form integrations, delivery logs, upload rules, and admin controls.</p>
            <div class="home-actions">
    HTML;

    if ($isLocalhost) {
        echo '<a href="/admin" class="button">Admin panel</a>';
    }

    echo <<<'HTML'
                <a href="https://github.com/MrGKanev/FormFlow" class="button secondary" target="_blank" rel="noopener noreferrer">GitHub</a>
                <a href="/health" class="button secondary">Health check</a>
            </div>
        </section>
        <ul class="features">
            <li><strong>Selectable CAPTCHA</strong>Use Turnstile, hCaptcha, reCAPTCHA v2, Friendly Captcha, or no CAPTCHA per form.</li>
            <li><strong>Per-form delivery</strong>Route submissions to email, Discord, Slack, Telegram, or custom webhooks with form-specific overrides.</li>
            <li><strong>Spam controls</strong>Honeypot checks, keyword filtering, allowed origins, daily caps, and a global IP blocklist.</li>
            <li><strong>Rate limits</strong>Per-IP and per-form limits keep noisy endpoints contained without extra services.</li>
            <li><strong>Admin workflow</strong>Review, resend, delete, export, and copy each form's ready-to-use integration snippet.</li>
            <li><strong>Operational extras</strong>Delivery logs, audit log, backups, config import/export, admin users, and IP whitelist controls.</li>
        </ul>
    </main>
    </body>
    </html>
    HTML;

    exit;
}

if ($formId === 'health') {
    $health = [
        'status' => 'ok',
        'service' => 'formflow',
        'time' => gmdate('c'),
    ];

    if (wantsJsonResponse()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($health, JSON_UNESCAPED_SLASHES);
        exit;
    }

    header('Content-Type: text/html; charset=utf-8');
    echo renderHealthPage($health);

    exit;
}

$databasePath = databasePath($root);

// Never fall back to a hardcoded/guessable secret: derive one from the per-install admin
// password hash so IP hashes stay unguessable even if IP_HASH_SECRET wasn't set in .env.
$ipHashSecret = getenv('IP_HASH_SECRET') ?: hash('sha256', getenv('ADMIN_PASSWORD_HASH') ?: bin2hex(random_bytes(32)));
$configuredForms = require $root . '/config/forms.php';
$formRepository = new SqliteFormRepository($databasePath);
$forms = array_merge($configuredForms, $formRepository->all());
$security = require $root . '/config/security.php';
$clientIp = clientIpResolver($security)->resolve($_SERVER);

if ($formId === 'admin' || str_starts_with($formId, 'admin/')) {
    $adminConfig = require $root . '/config/admin.php';
    $allowedIps = $adminConfig['allowed_ips'] ?? [];
    $whitelistRepository = new SqliteAdminWhitelistRepository($databasePath);
    $adminUsers = new SqliteAdminUserRepository($databasePath);
    $auditLog = new SqliteAuditLogRepository($databasePath);
    $webhookDeliveries = new SqliteWebhookDeliveryRepository($databasePath);
    $mailService = new MailService(
        mailerDsnFromEnv(),
        getenv('MAIL_FROM') ?: '',
        getenv('MAIL_FROM_NAME') ?: 'formflow'
    );

    $adminController = new AdminController(
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
        new SqliteSubmissionRepository($databasePath),
        $whitelistRepository,
        $allowedIps,
        $ipHashSecret,
        new SqliteFormApiKeyRepository($databasePath),
        $forms,
        $formRepository,
        (getenv('APP_ENV') ?: 'production') !== 'production',
        $root . '/.env',
        $root . '/config/admin.php',
        $root . '/config/security.php',
        $adminUsers,
        $auditLog,
        $mailService,
        $webhookDeliveries,
        $clientIp
    );

    $result = $adminController->handle($formId);

    http_response_code($result['status']);

    if (!empty($result['redirect'])) {
        header('Location: ' . $result['redirect'], true, 302);
        exit;
    }

    foreach (($result['headers'] ?? []) as $header => $value) {
        header($header . ': ' . $value);
    }

    if (empty($result['headers'])) {
        header('Content-Type: text/html; charset=utf-8');
    }

    echo $result['body'];
    exit;
}

$blocklist = new IpBlocklist($security['blocked_ips'] ?? []);

if (is_string($clientIp) && $blocklist->isBlocked($clientIp)) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');

    echo json_encode(['success' => false, 'message' => 'Forbidden.'], JSON_UNESCAPED_SLASHES);

    exit;
}

try {
    $handler = new FormHandler(
        $forms,
        new MailService(
            mailerDsnFromEnv(),
            getenv('MAIL_FROM') ?: '',
            getenv('MAIL_FROM_NAME') ?: 'formflow'
        ),
        new SqliteSubmissionRepository($databasePath),
        new Turnstile(getenv('TURNSTILE_SECRET') ?: ''),
        new SqliteRateLimiter($databasePath),
        $ipHashSecret,
        new SqliteFormApiKeyRepository($databasePath),
        new CurlWebhookNotifier(
            getenv('DISCORD_WEBHOOK_URL') ?: null,
            getenv('SLACK_WEBHOOK_URL') ?: null,
            getenv('GENERIC_WEBHOOK_URL') ?: null,
            getenv('TELEGRAM_BOT_TOKEN') ?: null,
            getenv('TELEGRAM_CHAT_ID') ?: null,
            new SqliteWebhookDeliveryRepository($databasePath),
            null,
            (getenv('WEBHOOK_DELIVERY_MODE') ?: 'sync') === 'queue'
        ),
        $root . '/storage/uploads',
        new CurlCaptchaVerifier([
            'hcaptcha_secret' => getenv('HCAPTCHA_SECRET') ?: '',
            'recaptcha_secret' => getenv('RECAPTCHA_SECRET') ?: '',
            'friendly_captcha_api_key' => getenv('FRIENDLY_CAPTCHA_API_KEY') ?: '',
            'friendly_captcha_site_key' => getenv('FRIENDLY_CAPTCHA_SITE_KEY') ?: '',
        ]),
        $clientIp,
        (getenv('MAIL_DELIVERY_MODE') ?: 'sync') === 'queue'
    );

    $result = $handler->handle($formId);

    http_response_code($result['status']);

    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    $expectsJson = str_contains($accept, 'application/json');

    if (
        !$expectsJson
        && !empty($result['redirect'])
        && ($result['body']['success'] ?? false) === true
    ) {
        header('Location: ' . $result['redirect'], true, 303);
        exit;
    }

    header('Content-Type: application/json; charset=utf-8');

    echo json_encode(
        $result['body'],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    );
} catch (InvalidArgumentException $exception) {
    http_response_code(422);
    header('Content-Type: application/json; charset=utf-8');

    echo json_encode([
        'success' => false,
        'message' => $exception->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    error_log($exception->__toString());

    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');

    echo json_encode([
        'success' => false,
        'message' => 'Internal server error.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
