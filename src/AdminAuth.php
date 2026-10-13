<?php

declare(strict_types=1);

namespace formflow;

final class AdminAuth
{
    private const RATE_LIMIT_FORM_ID = 'admin_login';
    /** @var array{type: string, id: int|string, username: string, fingerprint: string}|null */
    private ?array $authenticatedIdentity = null;

    private const DUMMY_PASSWORD_HASH = '$2y$10$A0C6YT61Drgppvd0cfLsNeL6.tT2E5C8d29cIppAnPkf.sMWNL7CW';

    public function __construct(
        private readonly string $adminUsername,
        private readonly string $adminPasswordHash,
        private readonly RateLimiterInterface $rateLimiter,
        private readonly int $maxAttempts,
        private readonly int $windowMinutes,
        private readonly ?AdminUserRepositoryInterface $users = null,
        private readonly string $bootstrapTotpSecret = '',
        private readonly ?TotpReplayGuardInterface $totpReplayGuard = null
    ) {
    }

    /** @return 'ok'|'invalid'|'locked' */
    public function attemptLogin(string $username, string $password, ?string $ipHash, ?string $totpCode = null): string
    {
        $this->authenticatedIdentity = null;
        $exceededLimit = $this->rateLimiter->consume(
            self::RATE_LIMIT_FORM_ID,
            $ipHash,
            $this->windowMinutes,
            $this->maxAttempts,
            PHP_INT_MAX
        );

        if ($exceededLimit !== null) {
            return 'locked';
        }

        $storedUser = $this->users?->findByUsername($username);

        if ($storedUser !== null) {
            if (!password_verify($password, (string) $storedUser['password_hash'])) {
                return 'invalid';
            }

            $secret = (string) ($storedUser['totp_secret'] ?? '');

            if ($secret !== '' && !$this->verifyTotpOnce($secret, (string) $totpCode, 'user:' . $storedUser['id'])) {
                return 'invalid';
            }

            $this->authenticatedIdentity = [
                'type' => 'stored',
                'id' => $storedUser['id'],
                'username' => $storedUser['username'],
                'fingerprint' => $this->credentialFingerprint((string) $storedUser['password_hash'], $secret),
            ];

            return 'ok';
        }

        $passwordMatches = password_verify($password, $this->adminPasswordHash !== '' ? $this->adminPasswordHash : self::DUMMY_PASSWORD_HASH);

        if ($this->adminPasswordHash === '' || !hash_equals($this->adminUsername, $username) || !$passwordMatches) {
            return 'invalid';
        }

        if (
            $this->bootstrapTotpSecret !== ''
            && !$this->verifyTotpOnce($this->bootstrapTotpSecret, (string) $totpCode, 'bootstrap:' . $this->adminUsername)
        ) {
            return 'invalid';
        }

        $this->authenticatedIdentity = $this->bootstrapIdentity();

        return 'ok';
    }

    /** Verifies the TOTP code and, if a replay guard is configured, rejects a code already used for this identity. */
    private function verifyTotpOnce(string $secret, string $code, string $identity): bool
    {
        $step = Totp::matchedStep($secret, $code);

        if ($step === null) {
            return false;
        }

        return $this->totpReplayGuard?->consume($identity, $step) ?? true;
    }

    public function login(?string $username = null): void
    {
        $identity = $this->authenticatedIdentity
            ?? ($username === null && $this->adminPasswordHash !== '' ? $this->bootstrapIdentity() : null);
        $this->authenticatedIdentity = null;
        $this->logout();

        if ($identity === null || ($username !== null && $identity['username'] !== $username)) {
            return;
        }

        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_username'] = $identity['username'];
        $_SESSION['admin_identity'] = $identity;
    }

    /** Only the controller may enable this context after checking the local runtime and client IP. */
    public function loginDevelopment(): void
    {
        $this->logout();
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_username'] = 'dev-localhost';
        $_SESSION['admin_identity'] = ['type' => 'development'];
    }

    public function isLoggedIn(bool $allowDevelopment = false): bool
    {
        $identity = $_SESSION['admin_identity'] ?? null;
        $valid = false;

        if (($_SESSION['admin_logged_in'] ?? false) === true && is_array($identity)) {
            if (($identity['type'] ?? null) === 'development') {
                $valid = $allowDevelopment;
            } elseif (($identity['type'] ?? null) === 'bootstrap') {
                $current = $this->bootstrapIdentity();
                $valid = $this->adminPasswordHash !== ''
                    && ($identity['id'] ?? null) === $current['id']
                    && ($identity['username'] ?? null) === $current['username']
                    && is_string($identity['fingerprint'] ?? null)
                    && hash_equals($current['fingerprint'], $identity['fingerprint']);
            } elseif (($identity['type'] ?? null) === 'stored' && is_string($identity['username'] ?? null)) {
                $user = $this->users?->findByUsername($identity['username']);
                $valid = $user !== null && ($identity['id'] ?? null) === $user['id']
                    && is_string($identity['fingerprint'] ?? null)
                    && hash_equals($this->credentialFingerprint($user['password_hash'], (string) ($user['totp_secret'] ?? '')), $identity['fingerprint']);
            }
        }

        if (!$valid) {
            $this->logout();
        }

        return $valid;
    }

    /** @return array{type: string, id: string, username: string, fingerprint: string} */
    private function bootstrapIdentity(): array
    {
        return ['type' => 'bootstrap', 'id' => $this->adminUsername, 'username' => $this->adminUsername,
            'fingerprint' => $this->credentialFingerprint($this->adminPasswordHash, $this->bootstrapTotpSecret)];
    }

    private function credentialFingerprint(string $passwordHash, string $totpSecret): string
    {
        return hash('sha256', json_encode([$passwordHash, $totpSecret], JSON_THROW_ON_ERROR));
    }

    public function logout(): void
    {
        $this->authenticatedIdentity = null;
        unset($_SESSION['admin_logged_in'], $_SESSION['admin_username'], $_SESSION['admin_identity']);
    }

    public function username(): ?string
    {
        $username = $_SESSION['admin_username'] ?? null;

        return is_string($username) && $username !== '' ? $username : null;
    }
}
