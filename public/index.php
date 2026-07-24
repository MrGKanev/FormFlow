<?php

declare(strict_types=1);

use formflow\Admin\AdminController;
use formflow\AdminAuth;
use formflow\AdminIpWhitelist;
use formflow\FormHandler;
use formflow\Install\InstallController;
use formflow\IpBlocklist;
use formflow\MailService;
use formflow\SqliteAdminWhitelistRepository;
use formflow\SqliteFormApiKeyRepository;
use formflow\SqliteRateLimiter;
use formflow\SqliteSubmissionRepository;
use formflow\Turnstile;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);

if (is_file($root . '/.env')) {
    (new Dotenv())->usePutenv()->load($root . '/.env');
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
    <html lang="en">
    <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>formflow</title>
    <link rel="stylesheet" href="/assets/style.css">
    </head>
    <body>
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
    header('Content-Type: application/json; charset=utf-8');

    echo json_encode([
        'status' => 'ok',
        'service' => 'formflow',
        'time' => gmdate('c'),
    ], JSON_UNESCAPED_SLASHES);

    exit;
}

$databasePath = getenv('DATABASE_PATH') ?: 'storage/submissions.sqlite';

if (!str_starts_with($databasePath, '/')) {
    $databasePath = $root . '/' . ltrim($databasePath, '/');
}

$ipHashSecret = getenv('IP_HASH_SECRET') ?: 'change-me';
$forms = require $root . '/config/forms.php';

if ($formId === 'admin' || str_starts_with($formId, 'admin/')) {
    $adminConfig = require $root . '/config/admin.php';
    $allowedIps = $adminConfig['allowed_ips'] ?? [];
    $whitelistRepository = new SqliteAdminWhitelistRepository($databasePath);

    $adminController = new AdminController(
        new AdminAuth(
            getenv('ADMIN_USERNAME') ?: 'admin',
            getenv('ADMIN_PASSWORD_HASH') ?: '',
            new SqliteRateLimiter($databasePath),
            (int) ($adminConfig['login_rate_limit']['max'] ?? 5),
            (int) ($adminConfig['login_rate_limit']['window_minutes'] ?? 15)
        ),
        new AdminIpWhitelist($allowedIps, $whitelistRepository),
        new SqliteSubmissionRepository($databasePath),
        $whitelistRepository,
        $allowedIps,
        $ipHashSecret,
        new SqliteFormApiKeyRepository($databasePath),
        array_keys($forms),
        (getenv('APP_ENV') ?: 'production') !== 'production'
    );

    $result = $adminController->handle($formId);

    http_response_code($result['status']);

    if (!empty($result['redirect'])) {
        header('Location: ' . $result['redirect'], true, 302);
        exit;
    }

    header('Content-Type: text/html; charset=utf-8');
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
            getenv('MAILER_DSN') ?: '',
            getenv('MAIL_FROM') ?: '',
            getenv('MAIL_FROM_NAME') ?: 'formflow'
        ),
        new SqliteSubmissionRepository($databasePath),
        new Turnstile(getenv('TURNSTILE_SECRET') ?: ''),
        new SqliteRateLimiter($databasePath),
        $ipHashSecret,
        new SqliteFormApiKeyRepository($databasePath)
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
