<?php

declare(strict_types=1);

namespace formflow\Tests\Admin;

use formflow\Admin\AdminController;
use formflow\AdminAuth;
use formflow\AdminIpWhitelist;
use formflow\FormApiKeyRepositoryInterface;
use formflow\SqliteAdminWhitelistRepository;
use formflow\SqliteFormApiKeyRepository;
use formflow\SqliteFormRepository;
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
            session_destroy();
        }

        $_SESSION = [];

        unset($_SERVER['REQUEST_METHOD'], $_SERVER['REMOTE_ADDR']);
    }

    /**
     * @param list<string> $allowedIps
     * @param array<string, array<string, mixed>>|null $forms
     */
    private function makeController(
        array $allowedIps = ['203.0.113.10'],
        ?SqliteSubmissionRepository $submissions = null,
        ?SqliteAdminWhitelistRepository $whitelistRepository = null,
        ?FormApiKeyRepositoryInterface $apiKeys = null,
        ?array $forms = null,
        ?SqliteFormRepository $formRepository = null,
        bool $devLoginEnabled = false
    ): AdminController {
        $whitelistRepository ??= new SqliteAdminWhitelistRepository(':memory:');
        $forms ??= [
            'contact' => ['recipient' => 'hello@example.com'],
            'support' => ['recipient' => 'support@example.com'],
        ];

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
            'test-ip-hash-secret',
            $apiKeys ?? new SqliteFormApiKeyRepository(':memory:'),
            $forms,
            $formRepository ?? new SqliteFormRepository(':memory:'),
            $devLoginEnabled
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

    public function testNonWhitelistedIpReturns403EvenForLoginRoute(): void
    {
        $controller = $this->makeController(['198.51.100.1']);

        $result = $controller->handle('admin/login');

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

    public function testLoginPageShowsDevBypassButtonOnlyWhenLocalAndEnabled(): void
    {
        $controller = $this->makeController(['203.0.113.10']);
        $result = $controller->handle('admin/login');
        $this->assertStringNotContainsString('dev_bypass', $result['body']);

        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $localController = $this->makeController(['127.0.0.1'], devLoginEnabled: true);
        $localResult = $localController->handle('admin/login');
        $this->assertStringContainsString('dev_bypass', $localResult['body']);
    }

    public function testDevBypassButtonHiddenWhenLoopbackButNotEnabled(): void
    {
        // Simulates a reverse proxy talking to PHP-FPM over 127.0.0.1: the
        // request looks loopback, but devLoginEnabled defaults to false in
        // production, so the button must not appear.
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $controller = $this->makeController(['127.0.0.1'], devLoginEnabled: false);

        $result = $controller->handle('admin/login');

        $this->assertStringNotContainsString('dev_bypass', $result['body']);
    }

    public function testDevBypassLoginSucceedsFromLoopbackWhenEnabled(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $controller = $this->makeController(['127.0.0.1'], devLoginEnabled: true);

        $controller->handle('admin/login');
        $token = $this->csrfToken();

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['dev_bypass' => '1', 'csrf_token' => $token];

        $result = $controller->handle('admin/login');

        $this->assertSame(302, $result['status']);
        $this->assertSame('/admin', $result['redirect']);
    }

    public function testDevBypassLoginFailsWhenNotLoopbackEvenIfEnabled(): void
    {
        // Whitelisted but not a loopback address: the outer IP-whitelist gate
        // passes, isolating the loopback-only check inside handleLogin().
        $controller = $this->makeController(['203.0.113.10'], devLoginEnabled: true);

        $controller->handle('admin/login');
        $token = $this->csrfToken();

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['dev_bypass' => '1', 'csrf_token' => $token];

        $result = $controller->handle('admin/login');

        $this->assertSame(401, $result['status']);
    }

    public function testDevBypassLoginFailsFromLoopbackWhenNotEnabled(): void
    {
        // The critical regression case: REMOTE_ADDR looks loopback (e.g. Nginx
        // proxying to PHP-FPM over 127.0.0.1 without real_ip configured), but
        // devLoginEnabled is false (the production default) - must not bypass.
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $controller = $this->makeController(['127.0.0.1'], devLoginEnabled: false);

        $controller->handle('admin/login');
        $token = $this->csrfToken();

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['dev_bypass' => '1', 'csrf_token' => $token];

        $result = $controller->handle('admin/login');

        $this->assertSame(401, $result['status']);
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

    public function testApiKeysPageListsConfiguredFormsWithoutGeneratedKeys(): void
    {
        $controller = $this->makeController();
        $this->login($controller);

        $result = $controller->handle('admin/api-keys');

        $this->assertSame(200, $result['status']);
        $this->assertStringContainsString('contact', $result['body']);
        $this->assertStringContainsString('support', $result['body']);
        $this->assertStringContainsString('not generated', $result['body']);
    }

    public function testApiKeysPagePostGeneratesKeyAndRedirects(): void
    {
        $apiKeys = new SqliteFormApiKeyRepository(':memory:');
        $controller = $this->makeController(apiKeys: $apiKeys);
        $this->login($controller);

        $controller->handle('admin/api-keys');
        $token = $this->csrfToken();

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['form_id' => 'contact', 'csrf_token' => $token];

        $result = $controller->handle('admin/api-keys');

        $this->assertSame(302, $result['status']);
        $this->assertSame('/admin/api-keys', $result['redirect']);
        $this->assertNotNull($apiKeys->get('contact'));
    }

    public function testApiKeysPagePostRegeneratesReplacingPreviousKey(): void
    {
        $apiKeys = new SqliteFormApiKeyRepository(':memory:');
        $firstKey = $apiKeys->regenerate('contact');

        $controller = $this->makeController(apiKeys: $apiKeys);
        $this->login($controller);

        $controller->handle('admin/api-keys');
        $token = $this->csrfToken();

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['form_id' => 'contact', 'csrf_token' => $token];
        $controller->handle('admin/api-keys');

        $this->assertNotSame($firstKey, $apiKeys->get('contact'));
    }

    public function testApiKeysPagePostWithUnknownFormIdReturns422(): void
    {
        $controller = $this->makeController();
        $this->login($controller);

        $controller->handle('admin/api-keys');
        $token = $this->csrfToken();

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['form_id' => 'unknown-form', 'csrf_token' => $token];

        $result = $controller->handle('admin/api-keys');

        $this->assertSame(422, $result['status']);
    }

    public function testApiKeysPagePostWithoutCsrfTokenReturns419(): void
    {
        $apiKeys = new SqliteFormApiKeyRepository(':memory:');
        $controller = $this->makeController(apiKeys: $apiKeys);
        $this->login($controller);

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['form_id' => 'contact'];

        $result = $controller->handle('admin/api-keys');

        $this->assertSame(419, $result['status']);
        $this->assertNull($apiKeys->get('contact'));
    }

    public function testFormsPageListsConfiguredForms(): void
    {
        $controller = $this->makeController();
        $this->login($controller);

        $result = $controller->handle('admin/forms');

        $this->assertSame(200, $result['status']);
        $this->assertStringContainsString('contact', $result['body']);
        $this->assertStringContainsString('support@example.com', $result['body']);
        $this->assertStringNotContainsString('name="allowed_fields[]"', $result['body']);
        $this->assertStringNotContainsString('name="custom_allowed_fields"', $result['body']);
        $this->assertStringNotContainsString('name="required_fields[]"', $result['body']);
        $this->assertStringNotContainsString('name="custom_required_fields"', $result['body']);
    }

    public function testFormsPagePostCreatesFormAndRedirects(): void
    {
        $formRepository = new SqliteFormRepository(':memory:');
        $controller = $this->makeController(formRepository: $formRepository);
        $this->login($controller);

        $controller->handle('admin/forms');
        $token = $this->csrfToken();

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'form_id' => 'newsletter',
            'recipient' => 'news@example.com',
            'allowed_origins' => "https://example.com\nhttps://www.example.com",
            'subject' => 'New newsletter signup',
            'success_redirect' => 'https://example.com/thanks',
            'rate_limit_max' => '3',
            'rate_limit_window' => '15',
            'daily_limit' => '50',
            'turnstile' => '1',
            'blocked_patterns' => "viagra\n<a href=",
            'csrf_token' => $token,
        ];

        $result = $controller->handle('admin/forms');

        $this->assertSame(302, $result['status']);
        $this->assertSame('/admin/forms', $result['redirect']);

        $forms = $formRepository->all();
        $this->assertArrayHasKey('newsletter', $forms);
        $this->assertSame('news@example.com', $forms['newsletter']['recipient']);
        $this->assertArrayNotHasKey('allowed_fields', $forms['newsletter']);
        $this->assertArrayNotHasKey('required_fields', $forms['newsletter']);
        $this->assertSame(['max' => 3, 'window_minutes' => 15], $forms['newsletter']['rate_limit_per_ip']);
    }

    public function testFormsPagePostRejectsDuplicateFormId(): void
    {
        $formRepository = new SqliteFormRepository(':memory:');
        $controller = $this->makeController(formRepository: $formRepository);
        $this->login($controller);

        $controller->handle('admin/forms');
        $token = $this->csrfToken();

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'form_id' => 'contact',
            'recipient' => 'hello@example.com',
            'allowed_origins' => 'https://example.com',
            'csrf_token' => $token,
        ];

        $result = $controller->handle('admin/forms');

        $this->assertSame(422, $result['status']);
        $this->assertSame([], $formRepository->all());
    }

    public function testFormsPagePostWithoutCsrfTokenReturns419(): void
    {
        $formRepository = new SqliteFormRepository(':memory:');
        $controller = $this->makeController(formRepository: $formRepository);
        $this->login($controller);

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'form_id' => 'newsletter',
            'recipient' => 'news@example.com',
            'allowed_origins' => 'https://example.com',
        ];

        $result = $controller->handle('admin/forms');

        $this->assertSame(419, $result['status']);
        $this->assertSame([], $formRepository->all());
    }
}
