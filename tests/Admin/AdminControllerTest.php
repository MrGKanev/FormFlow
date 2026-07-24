<?php

declare(strict_types=1);

namespace formflow\Tests\Admin;

use formflow\Admin\AdminController;
use formflow\AdminAuth;
use formflow\AdminIpWhitelist;
use formflow\SqliteAdminWhitelistRepository;
use formflow\SqliteRateLimiter;
use formflow\SqliteSubmissionRepository;
use PHPUnit\Framework\TestCase;

final class AdminControllerTest extends TestCase
{
    private const PASSWORD = 'correct-password';

    protected function setUp(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REMOTE_ADDR'] = '203.0.113.10';
        $_GET = [];
        $_POST = [];
    }

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $_SESSION = [];

        unset($_SERVER['REQUEST_METHOD'], $_SERVER['REMOTE_ADDR']);
    }

    /** @param list<string> $allowedIps */
    private function makeController(
        array $allowedIps = ['203.0.113.10'],
        ?SqliteSubmissionRepository $submissions = null,
        ?SqliteAdminWhitelistRepository $whitelistRepository = null
    ): AdminController {
        $whitelistRepository ??= new SqliteAdminWhitelistRepository(':memory:');

        return new AdminController(
            new AdminAuth(
                'admin',
                password_hash(self::PASSWORD, PASSWORD_DEFAULT),
                new SqliteRateLimiter(':memory:'),
                5,
                15
            ),
            new AdminIpWhitelist($allowedIps, $whitelistRepository),
            $submissions ?? new SqliteSubmissionRepository(':memory:'),
            $whitelistRepository,
            $allowedIps,
            'test-ip-hash-secret'
        );
    }

    private function csrfToken(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        return $_SESSION['csrf_token'] ?? '';
    }

    public function testNonWhitelistedIpReturns403(): void
    {
        $controller = $this->makeController(['198.51.100.1']);

        $result = $controller->handle('admin');

        $this->assertSame(403, $result['status']);
    }

    public function testDashboardRedirectsToLoginWhenNotAuthenticated(): void
    {
        $controller = $this->makeController();

        $result = $controller->handle('admin');

        $this->assertSame(302, $result['status']);
        $this->assertSame('/admin/login', $result['redirect']);
    }

    public function testLoginGetRendersForm(): void
    {
        $controller = $this->makeController();

        $result = $controller->handle('admin/login');

        $this->assertSame(200, $result['status']);
        $this->assertStringContainsString('<form', $result['body']);
    }

    public function testLoginPostWithWrongPasswordReturns401(): void
    {
        $controller = $this->makeController();

        $controller->handle('admin/login');
        $token = $this->csrfToken();

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['username' => 'admin', 'password' => 'wrong', 'csrf_token' => $token];

        $result = $controller->handle('admin/login');

        $this->assertSame(401, $result['status']);
    }

    public function testLoginPostWithWrongCsrfTokenReturns419(): void
    {
        $controller = $this->makeController();

        $controller->handle('admin/login');

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['username' => 'admin', 'password' => self::PASSWORD, 'csrf_token' => 'wrong-token'];

        $result = $controller->handle('admin/login');

        $this->assertSame(419, $result['status']);
    }

    public function testLoginPostWithCorrectCredentialsRedirectsToDashboard(): void
    {
        $controller = $this->makeController();

        $controller->handle('admin/login');
        $token = $this->csrfToken();

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['username' => 'admin', 'password' => self::PASSWORD, 'csrf_token' => $token];

        $result = $controller->handle('admin/login');

        $this->assertSame(302, $result['status']);
        $this->assertSame('/admin', $result['redirect']);
    }

    public function testLoginLockoutReturns429AfterTooManyAttempts(): void
    {
        $controller = $this->makeController();

        $controller->handle('admin/login');
        $token = $this->csrfToken();

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['username' => 'admin', 'password' => 'wrong', 'csrf_token' => $token];

        for ($i = 0; $i < 5; $i++) {
            $controller->handle('admin/login');
        }

        $result = $controller->handle('admin/login');

        $this->assertSame(429, $result['status']);
    }

    private function login(AdminController $controller): void
    {
        $controller->handle('admin/login');
        $token = $this->csrfToken();

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['username' => 'admin', 'password' => self::PASSWORD, 'csrf_token' => $token];
        $controller->handle('admin/login');

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_POST = [];
    }

    public function testLogoutClearsSessionAndRedirectsToLogin(): void
    {
        $controller = $this->makeController();
        $this->login($controller);

        $result = $controller->handle('admin/logout');

        $this->assertSame(302, $result['status']);
        $this->assertSame('/admin/login', $result['redirect']);

        $dashboard = $controller->handle('admin');
        $this->assertSame(302, $dashboard['status']);
        $this->assertSame('/admin/login', $dashboard['redirect']);
    }

    public function testDashboardListsSubmissionsWhenAuthenticated(): void
    {
        $submissions = new SqliteSubmissionRepository(':memory:');
        $submissions->create('contact', ['name' => 'Ada'], null);

        $controller = $this->makeController(['203.0.113.10'], $submissions);
        $this->login($controller);

        $result = $controller->handle('admin');

        $this->assertSame(200, $result['status']);
        $this->assertStringContainsString('contact', $result['body']);
    }

    public function testSubmissionDetailReturns404ForUnknownId(): void
    {
        $controller = $this->makeController();
        $this->login($controller);

        $result = $controller->handle('admin/submissions/999');

        $this->assertSame(404, $result['status']);
    }

    public function testSubmissionDetailReturns200ForKnownId(): void
    {
        $submissions = new SqliteSubmissionRepository(':memory:');
        $id = $submissions->create('contact', ['name' => 'Ada'], null);

        $controller = $this->makeController(['203.0.113.10'], $submissions);
        $this->login($controller);

        $result = $controller->handle('admin/submissions/' . $id);

        $this->assertSame(200, $result['status']);
        $this->assertStringContainsString('Ada', $result['body']);
    }

    public function testWhitelistPostAddCreatesEntryAndRedirects(): void
    {
        $whitelistRepository = new SqliteAdminWhitelistRepository(':memory:');
        $controller = $this->makeController(['203.0.113.10'], null, $whitelistRepository);
        $this->login($controller);

        $controller->handle('admin/whitelist');
        $token = $this->csrfToken();

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'action' => 'add',
            'ip_or_cidr' => '198.51.100.5',
            'note' => 'test',
            'csrf_token' => $token,
        ];

        $result = $controller->handle('admin/whitelist');

        $this->assertSame(302, $result['status']);
        $this->assertCount(1, $whitelistRepository->list());
    }

    public function testWhitelistPostWithoutCsrfTokenReturns419(): void
    {
        $whitelistRepository = new SqliteAdminWhitelistRepository(':memory:');
        $controller = $this->makeController(['203.0.113.10'], null, $whitelistRepository);
        $this->login($controller);

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['action' => 'add', 'ip_or_cidr' => '198.51.100.5'];

        $result = $controller->handle('admin/whitelist');

        $this->assertSame(419, $result['status']);
        $this->assertSame([], $whitelistRepository->list());
    }

    public function testWhitelistPostRemoveDeletesEntry(): void
    {
        $whitelistRepository = new SqliteAdminWhitelistRepository(':memory:');
        $whitelistRepository->add('198.51.100.5', null);
        $id = $whitelistRepository->list()[0]['id'];

        $controller = $this->makeController(['203.0.113.10'], null, $whitelistRepository);
        $this->login($controller);

        $controller->handle('admin/whitelist');
        $token = $this->csrfToken();

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['action' => 'remove', 'id' => (string) $id, 'csrf_token' => $token];

        $result = $controller->handle('admin/whitelist');

        $this->assertSame(302, $result['status']);
        $this->assertSame([], $whitelistRepository->list());
    }
}
