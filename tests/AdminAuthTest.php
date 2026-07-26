<?php

declare(strict_types=1);

namespace formflow\Tests;

use formflow\AdminAuth;
use formflow\SqliteRateLimiter;
use PHPUnit\Framework\TestCase;

final class AdminAuthTest extends TestCase
{
    private function makeAuth(
        string $passwordHash,
        int $maxAttempts = 5,
        int $windowMinutes = 15,
        ?SqliteRateLimiter $rateLimiter = null,
        string $totpSecret = ''
    ): AdminAuth {
        return new AdminAuth(
            'admin',
            $passwordHash,
            $rateLimiter ?? new SqliteRateLimiter(':memory:'),
            $maxAttempts,
            $windowMinutes,
            null,
            $totpSecret
        );
    }

    public function testCorrectCredentialsReturnOk(): void
    {
        $hash = password_hash('correct-password', PASSWORD_DEFAULT);
        $auth = $this->makeAuth($hash);

        $this->assertSame('ok', $auth->attemptLogin('admin', 'correct-password', 'ip-hash-1'));
    }

    public function testIncorrectPasswordReturnsInvalid(): void
    {
        $hash = password_hash('correct-password', PASSWORD_DEFAULT);
        $auth = $this->makeAuth($hash);

        $this->assertSame('invalid', $auth->attemptLogin('admin', 'wrong-password', 'ip-hash-1'));
    }

    public function testIncorrectUsernameReturnsInvalid(): void
    {
        $hash = password_hash('correct-password', PASSWORD_DEFAULT);
        $auth = $this->makeAuth($hash);

        $this->assertSame('invalid', $auth->attemptLogin('someone-else', 'correct-password', 'ip-hash-1'));
    }

    public function testEmptyPasswordHashAlwaysRejects(): void
    {
        $auth = $this->makeAuth('');

        $this->assertSame('invalid', $auth->attemptLogin('admin', 'anything', 'ip-hash-1'));
    }

    public function testTotpSecretRequiresCode(): void
    {
        $auth = $this->makeAuth(password_hash('correct-password', PASSWORD_DEFAULT), totpSecret: 'JBSWY3DPEHPK3PXP');

        $this->assertSame('invalid', $auth->attemptLogin('admin', 'correct-password', 'ip-hash-1'));
        $this->assertSame('invalid', $auth->attemptLogin('admin', 'correct-password', 'ip-hash-1', '000000'));
    }

    public function testLockoutAfterMaxAttempts(): void
    {
        $hash = password_hash('correct-password', PASSWORD_DEFAULT);
        $rateLimiter = new SqliteRateLimiter(':memory:');
        $auth = $this->makeAuth($hash, 2, 15, $rateLimiter);

        $auth->attemptLogin('admin', 'wrong-password', 'ip-hash-1');
        $auth->attemptLogin('admin', 'wrong-password', 'ip-hash-1');
        $result = $auth->attemptLogin('admin', 'correct-password', 'ip-hash-1');

        $this->assertSame('locked', $result);
    }

    public function testLockoutIsScopedPerIpHash(): void
    {
        $hash = password_hash('correct-password', PASSWORD_DEFAULT);
        $rateLimiter = new SqliteRateLimiter(':memory:');
        $auth = $this->makeAuth($hash, 2, 15, $rateLimiter);

        $auth->attemptLogin('admin', 'wrong-password', 'ip-hash-1');
        $auth->attemptLogin('admin', 'wrong-password', 'ip-hash-1');

        $result = $auth->attemptLogin('admin', 'correct-password', 'ip-hash-2');

        $this->assertSame('ok', $result);
    }

    public function testLoginSetsSessionFlagAndIsLoggedInReturnsTrue(): void
    {
        $auth = $this->makeAuth(password_hash('x', PASSWORD_DEFAULT));

        $_SESSION = [];
        $auth->login();

        $this->assertTrue($auth->isLoggedIn());
    }

    public function testLogoutClearsSessionFlag(): void
    {
        $auth = $this->makeAuth(password_hash('x', PASSWORD_DEFAULT));

        $_SESSION = ['admin_logged_in' => true];
        $auth->logout();

        $this->assertFalse($auth->isLoggedIn());
    }

    public function testIsLoggedInReturnsFalseWhenSessionEmpty(): void
    {
        $auth = $this->makeAuth(password_hash('x', PASSWORD_DEFAULT));

        $_SESSION = [];

        $this->assertFalse($auth->isLoggedIn());
    }
}
