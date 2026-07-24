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
        private readonly bool $devLoginEnabled = false
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
