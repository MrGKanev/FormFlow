<?php

declare(strict_types=1);

namespace formflow;

use InvalidArgumentException;
use Throwable;

final class AppRouter
{
    /** @param array<string, mixed> $security */
    public function __construct(
        private readonly AppFactory $factory,
        private readonly array $security,
        private readonly string $root
    ) {
    }

    public function dispatch(): void
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $formId = trim((string) $path, '/');
        $envExists = is_file($this->root . '/.env');

        if ($formId === 'install') {
            $this->dispatchInstall($envExists);

            return;
        }

        if (!$envExists && $formId !== 'health') {
            header('Location: /install', true, 302);

            return;
        }

        if ($formId === '') {
            $this->dispatchHome();

            return;
        }

        if ($formId === 'health') {
            $this->dispatchHealth();

            return;
        }

        $forms = $this->factory->forms();
        $clientIp = $this->factory->clientIp($this->security);

        if ($formId === 'admin' || str_starts_with($formId, 'admin/')) {
            $this->dispatchAdmin($formId, $forms, $clientIp);

            return;
        }

        $this->dispatchForm($formId, $forms, $clientIp);
    }

    private function dispatchInstall(bool $envExists): void
    {
        if ($envExists) {
            http_response_code(403);
            header('Content-Type: text/html; charset=utf-8');
            echo '<h1>Already installed.</h1>';

            return;
        }

        $result = $this->factory->installController()->handle();

        http_response_code($result['status']);

        if (!empty($result['redirect'])) {
            header('Location: ' . $result['redirect'], true, 302);

            return;
        }

        header('Content-Type: text/html; charset=utf-8');
        echo $result['body'];
    }

    private function dispatchHome(): void
    {
        $isLocalhost = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);

        header('Content-Type: text/html; charset=utf-8');
        echo <<<'HTML'
        <!DOCTYPE html>
        <html lang="en" data-theme="light">
        <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>formflow</title>
        <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'%3E%3Ctext y='.9em' font-size='90'%3E%F0%9F%93%A8%3C/text%3E%3C/svg%3E">
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
    }

    private function dispatchHealth(): void
    {
        $health = [
            'status' => 'ok',
            'service' => 'formflow',
            'time' => Clock::nowIso(),
        ];

        if ($this->wantsJsonResponse()) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($health, JSON_UNESCAPED_SLASHES);

            return;
        }

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderHealthPage($health);
    }

    /** @param array<string, array<string, mixed>> $forms */
    private function dispatchAdmin(string $formId, array $forms, ?string $clientIp): void
    {
        $result = $this->factory->adminController($forms, $clientIp)->handle($formId);

        http_response_code($result['status']);

        if (!empty($result['redirect'])) {
            header('Location: ' . $result['redirect'], true, 302);

            return;
        }

        foreach (($result['headers'] ?? []) as $header => $value) {
            header($header . ': ' . $value);
        }

        if (empty($result['headers'])) {
            header('Content-Type: text/html; charset=utf-8');
        }

        echo $result['body'];
    }

    /** @param array<string, array<string, mixed>> $forms */
    private function dispatchForm(string $formId, array $forms, ?string $clientIp): void
    {
        $blocklist = new IpBlocklist($this->security['blocked_ips'] ?? []);

        if (is_string($clientIp) && $blocklist->isBlocked($clientIp)) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => 'Forbidden.'], JSON_UNESCAPED_SLASHES);

            return;
        }

        try {
            $result = $this->factory->formHandler($forms, $clientIp)->handle($formId);

            http_response_code($result['status']);

            $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
            $expectsJson = str_contains($accept, 'application/json');

            if (!$expectsJson && !empty($result['redirect']) && ($result['body']['success'] ?? false) === true) {
                header('Location: ' . $result['redirect'], true, 303);

                return;
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
    }

    private function wantsJsonResponse(): bool
    {
        if (($_GET['format'] ?? null) === 'json') {
            return true;
        }

        $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));

        return str_contains($accept, 'application/json') && !str_contains($accept, 'text/html');
    }

    /** @param array{status: string, service: string, time: string} $health */
    private function renderHealthPage(array $health): string
    {
        return <<<HTML
        <!DOCTYPE html>
        <html lang="en" data-theme="light">
        <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>formflow health</title>
        <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'%3E%3Ctext y='.9em' font-size='90'%3E%F0%9F%93%A8%3C/text%3E%3C/svg%3E">
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
}
