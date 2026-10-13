<?php

declare(strict_types=1);

namespace formflow\Tests;

use formflow\AdminAuth;
use formflow\SqliteRateLimiter;
use formflow\SqliteAdminUserRepository;
use formflow\AdminUserRepositoryInterface;
use formflow\SqliteTotpReplayGuard;
use formflow\Totp;
use formflow\TotpReplayGuardInterface;
use PHPUnit\Framework\TestCase;

final class AdminAuthTest extends TestCase
{
    private function makeAuth(
        string $passwordHash,
        int $maxAttempts = 5,
        int $windowMinutes = 15,
        ?SqliteRateLimiter $rateLimiter = null,
        string $totpSecret = '',
        ?TotpReplayGuardInterface $totpReplayGuard = null
    ): AdminAuth {
        return new AdminAuth(
            'admin',
            $passwordHash,
            $rateLimiter ?? new SqliteRateLimiter(':memory:'),
            $maxAttempts,
            $windowMinutes,
            null,
            $totpSecret,
            $totpReplayGuard
        );
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    private function storedAuth(AdminUserRepositoryInterface $users, string $bootstrapHash = ''): AdminAuth
    {
        return new AdminAuth('admin', $bootstrapHash, new SqliteRateLimiter(':memory:'), 5, 15, $users);
    }

    public function testDeletingAndRecreatingAccountWithSameCredentialsRevokesSession(): void
    {
        $_SESSION = [];
        $users = new SqliteAdminUserRepository(':memory:');
        $hash = password_hash('password', PASSWORD_BCRYPT, ['cost' => 4]);
        $users->create('teammate', $hash);
        $auth = $this->storedAuth($users);
        $this->assertSame('ok', $auth->attemptLogin('teammate', 'password', 'ip'));
        $auth->login('teammate');
        $this->assertTrue($auth->isLoggedIn());
        $id = $users->findByUsername('teammate')['id'];
        $users->delete($id);
        $users->create('teammate', $hash);

        $this->assertFalse($auth->isLoggedIn());
        $this->assertArrayNotHasKey('admin_identity', $_SESSION);
    }

    public function testDeletedAccountCannotFallBackToSameNamedBootstrapIdentity(): void
    {
        $_SESSION = [];
        $users = new SqliteAdminUserRepository(':memory:');
        $hash = password_hash('password', PASSWORD_BCRYPT, ['cost' => 4]);
        $users->create('admin', $hash);
        $auth = $this->storedAuth($users, $hash);
        $this->assertSame('ok', $auth->attemptLogin('admin', 'password', 'ip'));
        $auth->login('admin');
        $users->delete($users->findByUsername('admin')['id']);
        $this->assertFalse($auth->isLoggedIn());
    }

    public function testBootstrapSessionRemainsSeparateFromSameNamedStoredAccount(): void
    {
        $_SESSION = [];
        $users = new SqliteAdminUserRepository(':memory:');
        $hash = password_hash('password', PASSWORD_BCRYPT, ['cost' => 4]);
        $auth = $this->storedAuth($users, $hash);
        $this->assertSame('ok', $auth->attemptLogin('admin', 'password', 'ip'));
        $auth->login('admin');
        $users->create('admin', password_hash('different', PASSWORD_BCRYPT, ['cost' => 4]));
        $this->assertTrue($auth->isLoggedIn());
        $users->delete($users->findByUsername('admin')['id']);
        $this->assertTrue($auth->isLoggedIn());
    }

    public function testBootstrapCredentialChangesRevokeExistingSession(): void
    {
        $_SESSION = [];
        $hash = password_hash('password', PASSWORD_BCRYPT, ['cost' => 4]);
        $auth = $this->makeAuth($hash);
        $auth->login();
        $this->assertTrue($auth->isLoggedIn());
        $this->assertFalse($this->makeAuth($hash, totpSecret: 'NEWSECRET')->isLoggedIn());
        $auth->login();
        $this->assertFalse($this->makeAuth(password_hash('new', PASSWORD_BCRYPT, ['cost' => 4]))->isLoggedIn());
    }

    public function testStoredCredentialChangeRevokesSession(): void
    {
        $_SESSION = [];
        $row = ['id' => 1, 'username' => 'teammate', 'password_hash' => password_hash('password', PASSWORD_BCRYPT, ['cost' => 4]), 'totp_secret' => '', 'created_at' => 'now'];
        $users = $this->createMock(AdminUserRepositoryInterface::class);
        $users->method('findByUsername')->willReturnCallback(static function () use (&$row) { return $row; });
        $auth = $this->storedAuth($users);
        $this->assertSame('ok', $auth->attemptLogin('teammate', 'password', 'ip'));
        $auth->login('teammate');
        $row['totp_secret'] = 'NEWSECRET';
        $this->assertFalse($auth->isLoggedIn());
        $row['totp_secret'] = '';
        $this->assertSame('ok', $auth->attemptLogin('teammate', 'password', 'ip'));
        $auth->login('teammate');
        $row['password_hash'] = password_hash('new-password', PASSWORD_BCRYPT, ['cost' => 4]);
        $this->assertFalse($auth->isLoggedIn());
    }

    public function testAccountReplacementBetweenVerificationAndSessionCreationIsRejected(): void
    {
        $_SESSION = [];
        $users = new SqliteAdminUserRepository(':memory:');
        $hash = password_hash('password', PASSWORD_BCRYPT, ['cost' => 4]);
        $users->create('teammate', $hash);
        $auth = $this->storedAuth($users);
        $this->assertSame('ok', $auth->attemptLogin('teammate', 'password', 'ip'));
        $users->delete($users->findByUsername('teammate')['id']);
        $users->create('teammate', $hash);
        $auth->login('teammate');
        $this->assertFalse($auth->isLoggedIn());
    }

    public function testLegacySessionAndFailedLoginCannotAuthorize(): void
    {
        $auth = $this->makeAuth(password_hash('password', PASSWORD_BCRYPT, ['cost' => 4]));
        $_SESSION = ['admin_logged_in' => true, 'admin_username' => 'admin'];
        $this->assertFalse($auth->isLoggedIn());
        $this->assertSame('ok', $auth->attemptLogin('admin', 'password', 'ip'));
        $this->assertSame('invalid', $auth->attemptLogin('admin', 'wrong', 'ip'));
        $auth->login('admin');
        $this->assertFalse($auth->isLoggedIn());
    }

    public function testDevelopmentSessionsRequireExplicitAuthorizationAndLogoutClearsMetadata(): void
    {
        $_SESSION = [];
        $auth = $this->makeAuth('');
        $auth->loginDevelopment();
        $this->assertTrue($auth->isLoggedIn(true));
        $this->assertFalse($auth->isLoggedIn());
        $auth->loginDevelopment();
        $auth->logout();
        $this->assertSame([], $_SESSION);
        $auth->login();
        $this->assertFalse($auth->isLoggedIn(true));
    }

    public function testStoredUserNamedDevLocalhostUsesNormalAccountRevocation(): void
    {
        $_SESSION = [];
        $users = new SqliteAdminUserRepository(':memory:');
        $users->create('dev-localhost', password_hash('password', PASSWORD_BCRYPT, ['cost' => 4]));
        $auth = $this->storedAuth($users);
        $this->assertSame('ok', $auth->attemptLogin('dev-localhost', 'password', 'ip'));
        $auth->login('dev-localhost');
        $this->assertTrue($auth->isLoggedIn());
        $users->delete($users->findByUsername('dev-localhost')['id']);
        $this->assertFalse($auth->isLoggedIn(true));
    }

    public function testLoginWithoutUsernameBindsPreviouslyVerifiedStoredAccount(): void
    {
        $_SESSION = [];
        $users = new SqliteAdminUserRepository(':memory:');
        $hash = password_hash('password', PASSWORD_BCRYPT, ['cost' => 4]);
        $users->create('alice', $hash);
        $auth = $this->storedAuth($users, $hash);
        $this->assertSame('ok', $auth->attemptLogin('alice', 'password', 'ip'));
        $auth->login();
        $this->assertSame('alice', $auth->username());
        $this->assertTrue($auth->isLoggedIn());
        $users->delete($users->findByUsername('alice')['id']);
        $this->assertFalse($auth->isLoggedIn());
    }

    public function testSupplyingUsernameWithoutVerifiedAttemptCannotCreateSession(): void
    {
        $_SESSION = [];
        $auth = $this->makeAuth(password_hash('password', PASSWORD_BCRYPT, ['cost' => 4]));
        $auth->login('admin');
        $this->assertFalse($auth->isLoggedIn());
        $this->assertSame([], $_SESSION);
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

    public function testReplayedTotpCodeIsRejected(): void
    {
        $hash = password_hash('correct-password', PASSWORD_DEFAULT);
        $secret = 'JBSWY3DPEHPK3PXP';
        $code = $this->currentTotpCode($secret);
        $auth = $this->makeAuth($hash, totpSecret: $secret, totpReplayGuard: new SqliteTotpReplayGuard(':memory:'));

        $this->assertSame('ok', $auth->attemptLogin('admin', 'correct-password', 'ip-hash-1', $code));
        $this->assertSame('invalid', $auth->attemptLogin('admin', 'correct-password', 'ip-hash-1', $code));
    }

    private function currentTotpCode(string $secret): string
    {
        $reflection = new \ReflectionMethod(Totp::class, 'code');

        return $reflection->invoke(null, $secret, time());
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

    public function testUnknownIpStillAllowsExactlyTheConfiguredNumberOfAttempts(): void
    {
        $hash = password_hash('correct-password', PASSWORD_BCRYPT, ['cost' => 4]);
        $rateLimiter = new SqliteRateLimiter(':memory:');
        $auth = $this->makeAuth($hash, 2, 15, $rateLimiter);

        $this->assertSame('ok', $auth->attemptLogin('admin', 'correct-password', null));
        $this->assertSame('ok', $auth->attemptLogin('admin', 'correct-password', null));
        $this->assertSame('locked', $auth->attemptLogin('admin', 'correct-password', null));
        $this->assertSame(3, $rateLimiter->countRecentHitsByIp('admin_login', null, 15));
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
