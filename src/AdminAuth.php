<?php

declare(strict_types=1);

namespace formflow;

final class AdminAuth
{
    private const RATE_LIMIT_FORM_ID = 'admin_login';

    public function __construct(
        private readonly string $adminUsername,
        private readonly string $adminPasswordHash,
        private readonly RateLimiterInterface $rateLimiter,
        private readonly int $maxAttempts,
        private readonly int $windowMinutes,
        private readonly ?AdminUserRepositoryInterface $users = null
    ) {
    }

    /** @return 'ok'|'invalid'|'locked' */
    public function attemptLogin(string $username, string $password, ?string $ipHash): string
    {
        $this->rateLimiter->hit(self::RATE_LIMIT_FORM_ID, $ipHash);

        $recentAttempts = $this->rateLimiter->countRecentHitsByIp(
            self::RATE_LIMIT_FORM_ID,
            $ipHash,
            $this->windowMinutes
        );

        if ($recentAttempts > $this->maxAttempts) {
            return 'locked';
        }

        $storedUser = $this->users?->findByUsername($username);

        if ($storedUser !== null) {
            return password_verify($password, (string) $storedUser['password_hash']) ? 'ok' : 'invalid';
        }

        if (
            $this->adminPasswordHash === ''
            || !hash_equals($this->adminUsername, $username)
            || !password_verify($password, $this->adminPasswordHash)
        ) {
            return 'invalid';
        }

        return 'ok';
    }

    public function login(?string $username = null): void
    {
        $_SESSION['admin_logged_in'] = true;

        if ($username !== null && $username !== '') {
            $_SESSION['admin_username'] = $username;
        }
    }

    public function isLoggedIn(): bool
    {
        return ($_SESSION['admin_logged_in'] ?? false) === true;
    }

    public function logout(): void
    {
        unset($_SESSION['admin_logged_in'], $_SESSION['admin_username']);
    }

    public function username(): ?string
    {
        $username = $_SESSION['admin_username'] ?? null;

        return is_string($username) && $username !== '' ? $username : null;
    }
}
