<?php

declare(strict_types=1);

use formflow\Admin\AdminController;
use formflow\AdminAuth;
use formflow\AdminIpWhitelist;
use formflow\FormHandler;
use formflow\IpBlocklist;
use formflow\MailService;
use formflow\SqliteAdminWhitelistRepository;
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
        $ipHashSecret
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

$forms = require $root . '/config/forms.php';

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
        $ipHashSecret
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
