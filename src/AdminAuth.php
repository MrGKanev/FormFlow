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
        private readonly int $windowMinutes
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

        if ($this->adminPasswordHash === '') {
            return 'invalid';
        }

        if (!hash_equals($this->adminUsername, $username)) {
            return 'invalid';
        }

        if (!password_verify($password, $this->adminPasswordHash)) {
            return 'invalid';
        }

        return 'ok';
    }

    public function login(): void
    {
        $_SESSION['admin_logged_in'] = true;
    }

    public function isLoggedIn(): bool
    {
        return ($_SESSION['admin_logged_in'] ?? false) === true;
    }

    public function logout(): void
    {
        unset($_SESSION['admin_logged_in']);
    }
}
