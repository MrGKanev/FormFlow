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
        private readonly ?AdminUserRepositoryInterface $users = null,
        private readonly string $bootstrapTotpSecret = ''
    ) {
    }

    /** @return 'ok'|'invalid'|'locked' */
    public function attemptLogin(string $username, string $password, ?string $ipHash, ?string $totpCode = null): string
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
            if (!password_verify($password, (string) $storedUser['password_hash'])) {
                return 'invalid';
            }

            $secret = (string) ($storedUser['totp_secret'] ?? '');

            if ($secret !== '' && !Totp::verify($secret, (string) $totpCode)) {
                return 'invalid';
            }

            return 'ok';
        }

        if (
            $this->adminPasswordHash === ''
            || !hash_equals($this->adminUsername, $username)
            || !password_verify($password, $this->adminPasswordHash)
        ) {
            return 'invalid';
        }

        if ($this->bootstrapTotpSecret !== '' && !Totp::verify($this->bootstrapTotpSecret, (string) $totpCode)) {
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
