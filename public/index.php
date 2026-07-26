<?php

declare(strict_types=1);

use formflow\Admin\AdminController;
use formflow\AdminAuth;
use formflow\AdminIpWhitelist;
use formflow\CurlWebhookNotifier;
use formflow\FormHandler;
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
use formflow\Turnstile;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);

if (is_file($root . '/.env')) {
    (new Dotenv())->usePutenv()->load($root . '/.env');
}

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

function healthChecks(string $root, bool $envExists): array
{
    $databasePath = databasePath($root);
    $databaseDirectory = dirname($databasePath);
    $requiredExtensions = ['curl', 'mbstring', 'pdo_sqlite'];
    $checks = [];

    $checks[] = [
        'label' => 'Installation',
        'detail' => $envExists ? '.env found' : 'setup required',
        'ok' => $envExists,
        'status' => $envExists ? 'good' : 'warn',
    ];

    foreach ($requiredExtensions as $extension) {
        $loaded = extension_loaded($extension);
        $checks[] = [
            'label' => 'PHP extension: ' . $extension,
            'detail' => $loaded ? 'loaded' : 'not loaded',
            'ok' => $loaded,
            'status' => $loaded ? 'good' : 'danger',
        ];
    }

    $databaseReady = is_file($databasePath)
        ? is_readable($databasePath) && is_writable($databasePath)
        : is_dir($databaseDirectory) && is_writable($databaseDirectory);

    $checks[] = [
        'label' => 'Database',
        'detail' => $databaseReady ? 'storage ready' : 'database storage is not writable',
        'ok' => $databaseReady,
        'status' => $databaseReady ? 'good' : 'danger',
    ];

    $mailReady = ((getenv('MAILER_DSN') ?: '') !== '' || (getenv('SMTP_HOST') ?: '') !== '')
        && (getenv('MAIL_FROM') ?: '') !== '';
    $checks[] = [
        'label' => 'Mail delivery',
        'detail' => $mailReady ? 'configured' : 'missing SMTP settings or sender',
        'ok' => $mailReady,
        'status' => $mailReady ? 'good' : 'warn',
    ];

    $turnstileReady = (getenv('TURNSTILE_SECRET') ?: '') !== '';
    $checks[] = [
        'label' => 'Turnstile',
        'detail' => $turnstileReady ? 'configured' : 'not configured',
        'ok' => true,
        'status' => $turnstileReady ? 'good' : 'warn',
    ];

    return $checks;
}

function renderHealthPage(array $health): string
{
    $overallClass = $health['ok'] ? 'good' : 'danger';
    $overallText = $health['ok'] ? 'Operational' : 'Needs attention';
    $checksHtml = '';

    foreach ($health['checks'] as $check) {
        $badgeText = match ($check['status']) {
            'good' => 'OK',
            'warn' => 'Check',
            default => 'Fail',
        };

        $checksHtml .= sprintf(
            '<article class="health-card"><div class="section-heading"><h2>%s</h2><span class="badge %s">%s</span></div><p>%s</p></article>',
            htmlspecialchars((string) $check['label'], ENT_QUOTES, 'UTF-8'),
            htmlspecialchars((string) $check['status'], ENT_QUOTES, 'UTF-8'),
            $badgeText,
            htmlspecialchars((string) $check['detail'], ENT_QUOTES, 'UTF-8')
        );
    }

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
                <p class="page-meta">Current service readiness for formflow.</p>
            </div>
        </div>

        <section class="panel health-status">
            <div>
                <h2>Service status</h2>
                <p class="page-meta">Checked at <time datetime="{$health['time']}">{$health['time']}</time></p>
            </div>
            <span class="badge {$overallClass}">{$overallText}</span>
        </section>

        <div class="health-grid">
            {$checksHtml}
        </div>
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
            <p class="tagline">A clean endpoint for static-site forms with spam controls, rate limits, delivery logs, and per-form API keys.</p>
            <div class="home-actions">
    HTML;

    if ($isLocalhost) {
        echo '<a href="/admin" class="button">Admin panel</a>';
    }

    echo <<<'HTML'
                <a href="/health" class="button secondary">Health check</a>
            </div>
        </section>
        <ul class="features">
            <li><strong>Spam control</strong>Turnstile, honeypot checks, keyword filtering, and a global IP blocklist.</li>
            <li><strong>Rate limits</strong>Per-IP and per-form limits keep noisy forms contained.</li>
            <li><strong>Admin workflow</strong>Review submissions and rotate a <code>_key</code> for each configured form.</li>
        </ul>
    </main>
    </body>
    </html>
    HTML;

    exit;
}

if ($formId === 'health') {
    $checks = healthChecks($root, $envExists);
    $health = [
        'status' => in_array(false, array_column($checks, 'ok'), true) ? 'needs_attention' : 'ok',
        'ok' => !in_array(false, array_column($checks, 'ok'), true),
        'service' => 'formflow',
        'environment' => getenv('APP_ENV') ?: 'production',
        'time' => gmdate('c'),
        'checks' => $checks,
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

$ipHashSecret = getenv('IP_HASH_SECRET') ?: 'change-me';
$configuredForms = require $root . '/config/forms.php';
$formRepository = new SqliteFormRepository($databasePath);
$forms = array_merge($configuredForms, $formRepository->all());

if ($formId === 'admin' || str_starts_with($formId, 'admin/')) {
    $adminConfig = require $root . '/config/admin.php';
    $allowedIps = $adminConfig['allowed_ips'] ?? [];
    $whitelistRepository = new SqliteAdminWhitelistRepository($databasePath);
    $adminUsers = new SqliteAdminUserRepository($databasePath);
    $auditLog = new SqliteAuditLogRepository($databasePath);
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
            getenv('ADMIN_TOTP_SECRET') ?: ''
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
        $mailService
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

$security = require $root . '/config/security.php';
$blocklist = new IpBlocklist($security['blocked_ips'] ?? []);

$clientIp = $_SERVER['REMOTE_ADDR'] ?? null;

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
            getenv('SLACK_WEBHOOK_URL') ?: null
        ),
        $root . '/storage/uploads'
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
