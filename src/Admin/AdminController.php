<?php

declare(strict_types=1);

namespace formflow\Admin;

use formflow\AdminAuth;
use formflow\AdminIpWhitelistInterface;
use formflow\AdminWhitelistRepositoryInterface;
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
        private readonly string $ipHashSecret
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
        ]));
    }

    private function handleSubmissionDetail(int $id): array
    {
        $submission = $this->submissions->find($id);

        if ($submission === null) {
            return $this->htmlResponse(404, '<h1>Submission not found</h1>');
        }

        return $this->htmlResponse(200, $this->render('submission', ['submission' => $submission]));
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
        ]);
    }

    private function renderWhitelist(?string $error): string
    {
        return $this->render('whitelist', [
            'error' => $error,
            'entries' => $this->whitelistRepository->list(),
            'configuredIps' => $this->configuredIps,
            'csrfToken' => $_SESSION['csrf_token'],
        ]);
    }

    private function render(string $view, array $data): string
    {
        extract($data, EXTR_SKIP);

        ob_start();
        require __DIR__ . '/views/' . $view . '.php';

        return (string) ob_get_clean();
    }

    private function verifyCsrfToken(): bool
    {
        $token = (string) ($_POST['csrf_token'] ?? '');

        return hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $token);
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
