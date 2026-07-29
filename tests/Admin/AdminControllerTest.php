<?php

declare(strict_types=1);

namespace formflow\Tests\Admin;

use formflow\Admin\AdminController;
use formflow\AdminAuth;
use formflow\AdminIpWhitelist;
use formflow\AdminUserRepositoryInterface;
use formflow\AuditLogRepositoryInterface;
use formflow\FormApiKeyRepositoryInterface;
use formflow\MailSenderInterface;
use formflow\SqliteAdminUserRepository;
use formflow\SqliteAdminWhitelistRepository;
use formflow\SqliteAuditLogRepository;
use formflow\SqliteFormApiKeyRepository;
use formflow\SqliteFormRepository;
use formflow\SqliteRateLimiter;
use formflow\SqliteSubmissionRepository;
use formflow\SqliteWebhookDeliveryRepository;
use formflow\Tests\Fakes\FakeMailSender;
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
        bool $devLoginEnabled = false,
        ?string $envPath = null,
        ?string $adminConfigPath = null,
        ?string $securityConfigPath = null,
        ?AdminUserRepositoryInterface $adminUsers = null,
        ?AuditLogRepositoryInterface $auditLog = null,
        ?MailSenderInterface $mailSender = null,
        ?SqliteWebhookDeliveryRepository $webhookDeliveries = null
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
                15,
                $adminUsers
            ),
            new AdminIpWhitelist($allowedIps, $whitelistRepository),
            $submissions ?? new SqliteSubmissionRepository(':memory:'),
            $whitelistRepository,
            $allowedIps,
            'test-ip-hash-secret',
            $apiKeys ?? new SqliteFormApiKeyRepository(':memory:'),
            $forms,
            $formRepository ?? new SqliteFormRepository(':memory:'),
            $devLoginEnabled,
            $envPath,
            $adminConfigPath,
            $securityConfigPath,
            $adminUsers,
            $auditLog,
            $mailSender,
            $webhookDeliveries
        );
    }

    /** @return array{dir: string, env: string, admin: string, security: string} */
    private function settingsFiles(): array
    {
        $dir = sys_get_temp_dir() . '/formflow-admin-settings-' . bin2hex(random_bytes(6));
        mkdir($dir);

        $envPath = $dir . '/.env';
        $adminConfigPath = $dir . '/admin.php';
        $securityConfigPath = $dir . '/security.php';

        file_put_contents($envPath, implode(PHP_EOL, [
            "APP_ENV='production'",
            "APP_URL='https://forms.example.com'",
            "MAILER_DSN=''",
            "SMTP_HOST='smtp.example.com'",
            "SMTP_PORT='587'",
            "SMTP_ENCRYPTION='tls'",
            "SMTP_USERNAME='user'",
            "SMTP_PASSWORD='pass'",
            "MAIL_FROM='forms@example.com'",
            "MAIL_FROM_NAME='formflow'",
            "TURNSTILE_SECRET='turnstile-secret'",
            "DATABASE_PATH='storage/submissions.sqlite'",
            "IP_HASH_SECRET='1234567890123456'",
            "RETENTION_DAYS='180'",
            "ADMIN_USERNAME='admin'",
            "ADMIN_PASSWORD_HASH='" . password_hash(self::PASSWORD, PASSWORD_DEFAULT) . "'",
            '',
        ]));

        file_put_contents($adminConfigPath, "<?php\n\ndeclare(strict_types=1);\n\nreturn ['allowed_ips' => ['203.0.113.10'], 'login_rate_limit' => ['max' => 5, 'window_minutes' => 15]];\n");
        file_put_contents($securityConfigPath, "<?php\n\ndeclare(strict_types=1);\n\nreturn ['blocked_ips' => ['203.0.113.5']];\n");

        return [
            'dir' => $dir,
            'env' => $envPath,
            'admin' => $adminConfigPath,
            'security' => $securityConfigPath,
        ];
    }

    private function removeSettingsFiles(array $files): void
    {
        foreach (['env', 'admin', 'security'] as $key) {
            if (isset($files[$key]) && is_file($files[$key])) {
                unlink($files[$key]);
            }
        }

        if (isset($files['dir']) && is_dir($files['dir'])) {
            rmdir($files['dir']);
        }
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
        $this->assertStringContainsString('href="/admin/submissions/1"', $result['body']);
        $this->assertStringContainsString('Open</a>', $result['body']);
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

    public function testFormsPageListsConfiguredForms(): void
    {
        $files = $this->settingsFiles();

        try {
            $controller = $this->makeController(
                envPath: $files['env'],
                adminConfigPath: $files['admin'],
                securityConfigPath: $files['security']
            );
            $this->login($controller);

            $result = $controller->handle('admin/forms');

            $this->assertSame(200, $result['status']);
            $this->assertStringContainsString('contact', $result['body']);
            $this->assertStringContainsString('support@example.com', $result['body']);
            $this->assertStringContainsString('action=&quot;https://forms.example.com/contact&quot;', $result['body']);
            $this->assertStringContainsString('href="/admin/forms/new"', $result['body']);
            $this->assertStringNotContainsString('href="/admin/api-keys"', $result['body']);
            $this->assertStringNotContainsString('name="form_id"', $result['body']);
            $this->assertStringNotContainsString('name="allowed_fields[]"', $result['body']);
            $this->assertStringNotContainsString('name="custom_allowed_fields"', $result['body']);
            $this->assertStringNotContainsString('name="required_fields[]"', $result['body']);
            $this->assertStringNotContainsString('name="custom_required_fields"', $result['body']);
        } finally {
            $this->removeSettingsFiles($files);
        }
    }

    public function testApiKeysRouteIsNotAvailable(): void
    {
        $controller = $this->makeController();
        $this->login($controller);

        $result = $controller->handle('admin/api-keys');

        $this->assertSame(404, $result['status']);
    }

    public function testNewFormPagePostCreatesFormAndRedirects(): void
    {
        $formRepository = new SqliteFormRepository(':memory:');
        $apiKeys = new SqliteFormApiKeyRepository(':memory:');
        $controller = $this->makeController(formRepository: $formRepository, apiKeys: $apiKeys);
        $this->login($controller);

        $newPage = $controller->handle('admin/forms/new');
        $this->assertSame(200, $newPage['status']);
        $this->assertStringContainsString('Create form', $newPage['body']);
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
            'upload_max_file_size_mb' => '6',
            'upload_max_files' => '2',
            'upload_allowed_extensions' => "pdf\nJPG",
            'notification_channels' => ['slack', 'generic'],
            'slack_webhook_url' => 'https://hooks.slack.com/services/form-specific',
            'csrf_token' => $token,
        ];

        $result = $controller->handle('admin/forms/new');

        $this->assertSame(302, $result['status']);
        $this->assertSame('/admin/forms', $result['redirect']);

        $forms = $formRepository->all();
        $this->assertArrayHasKey('newsletter', $forms);
        $this->assertSame('news@example.com', $forms['newsletter']['recipient']);
        $this->assertArrayNotHasKey('allowed_fields', $forms['newsletter']);
        $this->assertArrayNotHasKey('required_fields', $forms['newsletter']);
        $this->assertSame(['max' => 3, 'window_minutes' => 15], $forms['newsletter']['rate_limit_per_ip']);
        $this->assertSame('turnstile', $forms['newsletter']['captcha_provider']);
        $this->assertTrue($forms['newsletter']['turnstile']);
        $this->assertSame([
            'max_file_size_mb' => 6,
            'max_files' => 2,
            'allowed_extensions' => ['pdf', 'jpg'],
        ], $forms['newsletter']['uploads']);
        $this->assertSame(['slack', 'generic'], $forms['newsletter']['notification_channels']);
        $this->assertSame([
            'slack_webhook_url' => 'https://hooks.slack.com/services/form-specific',
        ], $forms['newsletter']['notification_overrides']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $apiKeys->get('newsletter'));
    }

    public function testNewFormPageDoesNotPreselectNotificationChannels(): void
    {
        $controller = $this->makeController();
        $this->login($controller);

        $page = $controller->handle('admin/forms/new');

        $this->assertSame(200, $page['status']);
        $this->assertStringNotContainsString('value="discord" checked', $page['body']);
        $this->assertStringNotContainsString('value="slack" checked', $page['body']);
        $this->assertStringNotContainsString('value="telegram" checked', $page['body']);
        $this->assertStringNotContainsString('value="generic" checked', $page['body']);
    }

    public function testNewFormPageSelectsNoCaptchaByDefaultWithoutSettings(): void
    {
        $controller = $this->makeController();
        $this->login($controller);

        $page = $controller->handle('admin/forms/new');

        $this->assertSame(200, $page['status']);
        $this->assertStringContainsString('<option value="none" selected', $page['body']);
    }

    public function testFormsPageIncludesSelectedCaptchaSnippet(): void
    {
        $files = $this->settingsFiles();
        file_put_contents($files['env'], "HCAPTCHA_SITE_KEY='hcaptcha-site-key'\n", FILE_APPEND);

        try {
            $controller = $this->makeController(
                forms: [
                    'newsletter' => [
                        'recipient' => 'news@example.com',
                        'captcha_provider' => 'hcaptcha',
                    ],
                ],
                envPath: $files['env'],
                adminConfigPath: $files['admin'],
                securityConfigPath: $files['security']
            );
            $this->login($controller);

            $page = $controller->handle('admin/forms');

            $this->assertSame(200, $page['status']);
            $this->assertStringContainsString('h-captcha', $page['body']);
            $this->assertStringContainsString('https://js.hcaptcha.com/1/api.js', $page['body']);
        } finally {
            $this->removeSettingsFiles($files);
        }
    }

    public function testNewFormPagePostRejectsDuplicateFormId(): void
    {
        $formRepository = new SqliteFormRepository(':memory:');
        $controller = $this->makeController(formRepository: $formRepository);
        $this->login($controller);

        $controller->handle('admin/forms/new');
        $token = $this->csrfToken();

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'form_id' => 'contact',
            'recipient' => 'hello@example.com',
            'allowed_origins' => 'https://example.com',
            'csrf_token' => $token,
        ];

        $result = $controller->handle('admin/forms/new');

        $this->assertSame(422, $result['status']);
        $this->assertSame([], $formRepository->all());
    }

    public function testNewFormPageRejectsInvalidUploadExtension(): void
    {
        $formRepository = new SqliteFormRepository(':memory:');
        $controller = $this->makeController(formRepository: $formRepository);
        $this->login($controller);
        $controller->handle('admin/forms/new');
        $token = $this->csrfToken();

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'form_id' => 'newsletter',
            'recipient' => 'news@example.com',
            'allowed_origins' => 'https://example.com',
            'upload_allowed_extensions' => 'tar.gz',
            'csrf_token' => $token,
        ];

        $result = $controller->handle('admin/forms/new');

        $this->assertSame(422, $result['status']);
        $this->assertStringContainsString('Allowed file extensions', $result['body']);
        $this->assertSame([], $formRepository->all());
    }

    public function testNewFormPagePostWithoutCsrfTokenReturns419(): void
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

        $result = $controller->handle('admin/forms/new');

        $this->assertSame(419, $result['status']);
        $this->assertSame([], $formRepository->all());
    }

    public function testSettingsPageRendersCurrentSettings(): void
    {
        $files = $this->settingsFiles();

        try {
            $controller = $this->makeController(
                envPath: $files['env'],
                adminConfigPath: $files['admin'],
                securityConfigPath: $files['security']
            );
            $this->login($controller);

            $result = $controller->handle('admin/settings');

            $this->assertSame(200, $result['status']);
            $this->assertStringContainsString('Runtime configuration', $result['body']);
            $this->assertStringContainsString('https://forms.example.com', $result['body']);
            $this->assertStringContainsString('href="/admin/whitelist"', $result['body']);
            $this->assertStringContainsString('href="/admin/users"', $result['body']);
            $this->assertStringContainsString('href="/admin/audit"', $result['body']);
            $this->assertStringContainsString('href="/admin/settings?tab=delivery"', $result['body']);
            $this->assertStringContainsString('href="/admin/settings?tab=maintenance"', $result['body']);
        } finally {
            $this->removeSettingsFiles($files);
        }
    }

    public function testSettingsProtectionTabRendersOnlyProtectionFields(): void
    {
        $files = $this->settingsFiles();

        try {
            $controller = $this->makeController(
                envPath: $files['env'],
                adminConfigPath: $files['admin'],
                securityConfigPath: $files['security']
            );
            $this->login($controller);
            $_GET = ['tab' => 'protection'];

            $result = $controller->handle('admin/settings');

            $this->assertSame(200, $result['status']);
            $this->assertStringContainsString('Global blocklist', $result['body']);
            $this->assertStringContainsString('203.0.113.5', $result['body']);
            $this->assertStringNotContainsString('SMTP host', $result['body']);
        } finally {
            $this->removeSettingsFiles($files);
        }
    }

    public function testSettingsPostUpdatesEnvAdminAndSecurityFiles(): void
    {
        $files = $this->settingsFiles();

        try {
            $controller = $this->makeController(
                envPath: $files['env'],
                adminConfigPath: $files['admin'],
                securityConfigPath: $files['security']
            );
            $this->login($controller);

            $controller->handle('admin/settings');
            $token = $this->csrfToken();

            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_POST = [
                'app_env' => 'local',
                'app_url' => 'https://forms.test',
                'mailer_dsn' => '',
                'smtp_host' => 'smtp.mail.test',
                'smtp_port' => '465',
                'smtp_encryption' => 'ssl',
                'smtp_username' => 'new-user',
                'smtp_password' => 'new-pass',
                'mail_from' => 'new@example.com',
                'mail_from_name' => 'New Formflow',
                'turnstile_secret' => 'new-turnstile',
                'turnstile_site_key' => 'turnstile-site',
                'hcaptcha_secret' => 'hcaptcha-secret',
                'hcaptcha_site_key' => 'hcaptcha-site',
                'recaptcha_secret' => 'recaptcha-secret',
                'recaptcha_site_key' => 'recaptcha-site',
                'friendly_captcha_api_key' => 'friendly-api-key',
                'friendly_captcha_site_key' => 'friendly-site',
                'database_path' => 'storage/new.sqlite',
                'ip_hash_secret' => 'abcdef1234567890',
                'retention_days' => '90',
                'admin_username' => 'owner',
                'admin_password' => 'new-password',
                'login_rate_limit_max' => '9',
                'login_rate_limit_window' => '30',
                'blocked_ips' => "198.51.100.5\n198.51.100.0/24",
                'csrf_token' => $token,
            ];

            $result = $controller->handle('admin/settings');

            $this->assertSame(302, $result['status']);
            $this->assertSame('/admin/settings?tab=general&saved=1', $result['redirect']);

            $env = file_get_contents($files['env']);
            $this->assertStringContainsString("APP_ENV='local'", (string) $env);
            $this->assertStringContainsString("APP_URL='https://forms.test'", (string) $env);
            $this->assertStringContainsString("SMTP_HOST='smtp.mail.test'", (string) $env);
            $this->assertStringContainsString("SMTP_PORT='465'", (string) $env);
            $this->assertStringContainsString("SMTP_ENCRYPTION='ssl'", (string) $env);
            $this->assertStringContainsString("SMTP_USERNAME='new-user'", (string) $env);
            $this->assertStringContainsString("SMTP_PASSWORD='new-pass'", (string) $env);
            $this->assertStringContainsString("HCAPTCHA_SECRET='hcaptcha-secret'", (string) $env);
            $this->assertStringContainsString("RECAPTCHA_SITE_KEY='recaptcha-site'", (string) $env);
            $this->assertStringContainsString("FRIENDLY_CAPTCHA_API_KEY='friendly-api-key'", (string) $env);
            $this->assertStringContainsString("RETENTION_DAYS='90'", (string) $env);
            $this->assertStringContainsString("ADMIN_USERNAME='owner'", (string) $env);
            $this->assertMatchesRegularExpression("/ADMIN_PASSWORD_HASH='\\\$2y\\\$/", (string) $env);

            $adminConfig = require $files['admin'];
            $this->assertSame(['203.0.113.10'], $adminConfig['allowed_ips']);
            $this->assertSame(9, $adminConfig['login_rate_limit']['max']);
            $this->assertSame(30, $adminConfig['login_rate_limit']['window_minutes']);

            $securityConfig = require $files['security'];
            $this->assertSame(['198.51.100.5', '198.51.100.0/24'], $securityConfig['blocked_ips']);
        } finally {
            $this->removeSettingsFiles($files);
        }
    }

    public function testSettingsTabSavePreservesValuesFromOtherTabs(): void
    {
        $files = $this->settingsFiles();

        try {
            $controller = $this->makeController(
                envPath: $files['env'],
                adminConfigPath: $files['admin'],
                securityConfigPath: $files['security']
            );
            $this->login($controller);
            $controller->handle('admin/settings');
            $token = $this->csrfToken();

            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_POST = [
                'action' => 'save',
                'tab' => 'delivery',
                'smtp_host' => 'smtp.changed.example',
                'csrf_token' => $token,
            ];

            $result = $controller->handle('admin/settings');

            $this->assertSame(302, $result['status']);
            $this->assertSame('/admin/settings?tab=delivery&saved=1', $result['redirect']);
            $env = (string) file_get_contents($files['env']);
            $this->assertStringContainsString("SMTP_HOST='smtp.changed.example'", $env);
            $this->assertStringContainsString("APP_URL='https://forms.example.com'", $env);
            $this->assertStringContainsString("IP_HASH_SECRET='1234567890123456'", $env);
        } finally {
            $this->removeSettingsFiles($files);
        }
    }

    public function testIntegrationsPageSavesNotificationSettings(): void
    {
        $files = $this->settingsFiles();

        try {
            $controller = $this->makeController(
                envPath: $files['env'],
                adminConfigPath: $files['admin'],
                securityConfigPath: $files['security']
            );
            $this->login($controller);

            $page = $controller->handle('admin/integrations');
            $this->assertSame(200, $page['status']);
            $this->assertStringContainsString('Telegram', $page['body']);
            $this->assertStringContainsString('Generic webhook', $page['body']);
            $token = $this->csrfToken();

            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_POST = [
                'discord_webhook_url' => 'https://discord.com/api/webhooks/example',
                'slack_webhook_url' => 'https://hooks.slack.com/services/example',
                'generic_webhook_url' => 'https://hooks.example.com/formflow',
                'telegram_bot_token' => '123456:telegram-token',
                'telegram_chat_id' => '-1001234567890',
                'csrf_token' => $token,
            ];

            $result = $controller->handle('admin/integrations');

            $this->assertSame(302, $result['status']);
            $this->assertSame('/admin/integrations?saved=1', $result['redirect']);
            $env = (string) file_get_contents($files['env']);
            $this->assertStringContainsString("DISCORD_WEBHOOK_URL='https://discord.com/api/webhooks/example'", $env);
            $this->assertStringContainsString("GENERIC_WEBHOOK_URL='https://hooks.example.com/formflow'", $env);
            $this->assertStringContainsString("TELEGRAM_CHAT_ID='-1001234567890'", $env);
        } finally {
            $this->removeSettingsFiles($files);
        }
    }

    public function testSettingsPostRejectsInvalidBlockedIp(): void
    {
        $files = $this->settingsFiles();

        try {
            $controller = $this->makeController(
                envPath: $files['env'],
                adminConfigPath: $files['admin'],
                securityConfigPath: $files['security']
            );
            $this->login($controller);

            $controller->handle('admin/settings');
            $token = $this->csrfToken();

            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_POST = [
                'app_env' => 'production',
                'app_url' => 'https://forms.example.com',
                'mailer_dsn' => '',
                'smtp_host' => '',
                'smtp_port' => '587',
                'smtp_encryption' => 'tls',
                'smtp_username' => '',
                'smtp_password' => '',
                'mail_from' => '',
                'mail_from_name' => 'formflow',
                'turnstile_secret' => '',
                'database_path' => 'storage/submissions.sqlite',
                'ip_hash_secret' => '1234567890123456',
                'retention_days' => '180',
                'admin_username' => 'admin',
                'login_rate_limit_max' => '5',
                'login_rate_limit_window' => '15',
                'blocked_ips' => 'not-an-ip',
                'csrf_token' => $token,
            ];

            $result = $controller->handle('admin/settings');

            $this->assertSame(422, $result['status']);
            $securityConfig = require $files['security'];
            $this->assertSame(['203.0.113.5'], $securityConfig['blocked_ips']);
        } finally {
            $this->removeSettingsFiles($files);
        }
    }

    public function testSettingsTotpGenerationRendersQrCode(): void
    {
        $files = $this->settingsFiles();

        try {
            $controller = $this->makeController(
                envPath: $files['env'],
                adminConfigPath: $files['admin'],
                securityConfigPath: $files['security']
            );
            $this->login($controller);

            $controller->handle('admin/settings');
            $token = $this->csrfToken();

            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_POST = [
                'action' => 'generate_totp',
                'csrf_token' => $token,
            ];

            $result = $controller->handle('admin/settings');

            $this->assertSame(200, $result['status']);
            $this->assertStringContainsString('class="qr-svg totp-qr"', $result['body']);
            $this->assertStringContainsString('otpauth://totp/FormFlow%3Aadmin', $result['body']);

            $env = file_get_contents($files['env']);
            $this->assertMatchesRegularExpression("/ADMIN_TOTP_SECRET='[A-Z2-7]+'/", (string) $env);
        } finally {
            $this->removeSettingsFiles($files);
        }
    }

    public function testDbAdminUserCanLogin(): void
    {
        $users = new SqliteAdminUserRepository(':memory:');
        $users->create('teammate', password_hash('team-password', PASSWORD_DEFAULT));
        $controller = $this->makeController(adminUsers: $users);

        $controller->handle('admin/login');
        $token = $this->csrfToken();

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['username' => 'teammate', 'password' => 'team-password', 'csrf_token' => $token];

        $result = $controller->handle('admin/login');

        $this->assertSame(302, $result['status']);
        $this->assertSame('/admin', $result['redirect']);
    }

    public function testUsersPageCreatesAdminUser(): void
    {
        $users = new SqliteAdminUserRepository(':memory:');
        $audit = new SqliteAuditLogRepository(':memory:');
        $controller = $this->makeController(adminUsers: $users, auditLog: $audit);
        $this->login($controller);

        $controller->handle('admin/users');
        $token = $this->csrfToken();

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'action' => 'create',
            'username' => 'second-admin',
            'password' => 'second-password',
            'csrf_token' => $token,
        ];

        $result = $controller->handle('admin/users');

        $this->assertSame(302, $result['status']);
        $this->assertNotNull($users->findByUsername('second-admin'));
        $this->assertSame('admin_user.create', $audit->list()[0]['action']);
    }

    public function testFormEditUpdatesStoredForm(): void
    {
        $formRepository = new SqliteFormRepository(':memory:');
        $formRepository->create('newsletter', [
            'recipient' => 'old@example.com',
            'allowed_origins' => ['https://example.com'],
            'subject' => 'Old',
        ]);
        $controller = $this->makeController(
            forms: ['newsletter' => $formRepository->all()['newsletter']],
            formRepository: $formRepository
        );
        $this->login($controller);

        $editPage = $controller->handle('admin/forms/newsletter/edit');
        $this->assertStringNotContainsString('value="discord" checked', $editPage['body']);
        $this->assertStringNotContainsString('value="slack" checked', $editPage['body']);
        $this->assertStringNotContainsString('value="telegram" checked', $editPage['body']);
        $this->assertStringNotContainsString('value="generic" checked', $editPage['body']);
        $token = $this->csrfToken();

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'recipient' => 'new@example.com',
            'allowed_origins' => 'https://example.com',
            'subject' => 'New',
            'success_redirect' => '',
            'rate_limit_max' => '5',
            'rate_limit_window' => '10',
            'daily_limit' => '200',
            'require_api_key' => '1',
            'upload_max_file_size_mb' => '12',
            'upload_max_files' => '4',
            'upload_allowed_extensions' => "pdf\nPNG",
            'notification_channels' => ['discord', 'telegram'],
            'csrf_token' => $token,
        ];

        $result = $controller->handle('admin/forms/newsletter/edit');

        $this->assertSame(302, $result['status']);
        $forms = $formRepository->all();
        $this->assertSame('new@example.com', $forms['newsletter']['recipient']);
        $this->assertTrue($forms['newsletter']['require_api_key']);
        $this->assertSame([
            'max_file_size_mb' => 12,
            'max_files' => 4,
            'allowed_extensions' => ['pdf', 'png'],
        ], $forms['newsletter']['uploads']);
        $this->assertSame(['discord', 'telegram'], $forms['newsletter']['notification_channels']);
    }

    public function testSubmissionActionsReviewDeleteAndExport(): void
    {
        $submissions = new SqliteSubmissionRepository(':memory:');
        $id = $submissions->create('contact', ['name' => 'Ada'], null);
        $audit = new SqliteAuditLogRepository(':memory:');
        $controller = $this->makeController(['203.0.113.10'], $submissions, auditLog: $audit);
        $this->login($controller);

        $controller->handle('admin/submissions/' . $id);
        $token = $this->csrfToken();

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['action' => 'review', 'csrf_token' => $token];
        $reviewResult = $controller->handle('admin/submissions/' . $id . '/action');
        $this->assertSame(302, $reviewResult['status']);
        $this->assertNotEmpty($submissions->find($id)['reviewed_at']);

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_POST = [];
        $export = $controller->handle('admin/export');
        $this->assertSame(200, $export['status']);
        $this->assertStringContainsString('payload_json', $export['body']);
        $this->assertSame('text/csv; charset=utf-8', $export['headers']['Content-Type']);

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['action' => 'delete', 'csrf_token' => $token];
        $deleteResult = $controller->handle('admin/submissions/' . $id . '/action');
        $this->assertSame(302, $deleteResult['status']);
        $this->assertNull($submissions->find($id));
        $this->assertNotSame([], $audit->list());
    }

    public function testCsvSafeCellNeutralizesFormulaInjection(): void
    {
        $controller = $this->makeController(['203.0.113.10'], new SqliteSubmissionRepository(':memory:'));
        $method = new \ReflectionMethod($controller, 'csvSafeCell');

        $this->assertSame("'=HYPERLINK(\"http://evil/\",\"x\")", $method->invoke($controller, '=HYPERLINK("http://evil/","x")'));
        $this->assertSame("'+1", $method->invoke($controller, '+1'));
        $this->assertSame("'-1", $method->invoke($controller, '-1'));
        $this->assertSame("'@SUM(1)", $method->invoke($controller, '@SUM(1)'));
        $this->assertSame('Ada', $method->invoke($controller, 'Ada'));
        $this->assertSame('', $method->invoke($controller, ''));
    }

    public function testSubmissionResendUsesMailSender(): void
    {
        $submissions = new SqliteSubmissionRepository(':memory:');
        $id = $submissions->create('contact', ['email' => 'ada@example.com'], null, 'failed');
        $mailSender = new FakeMailSender();
        $controller = $this->makeController(
            ['203.0.113.10'],
            $submissions,
            forms: ['contact' => ['recipient' => 'hello@example.com', 'subject' => 'Contact']],
            mailSender: $mailSender
        );
        $this->login($controller);

        $controller->handle('admin/submissions/' . $id);
        $token = $this->csrfToken();

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['action' => 'resend', 'csrf_token' => $token];

        $result = $controller->handle('admin/submissions/' . $id . '/action');

        $this->assertSame(302, $result['status']);
        $this->assertCount(1, $mailSender->sentMessages);
        $this->assertSame('sent', $submissions->find($id)['status']);
    }

    public function testDeliveryAndAuditPagesRender(): void
    {
        $submissions = new SqliteSubmissionRepository(':memory:');
        $submissions->create('contact', ['name' => 'Ada'], null, 'failed');
        $webhookDeliveries = new SqliteWebhookDeliveryRepository(':memory:');
        $webhookDeliveries->record('contact', 'slack', 'failed', 3, 'Webhook returned HTTP 500.');
        $audit = new SqliteAuditLogRepository(':memory:');
        $audit->record('admin', 'test.action', 'Something happened.');
        $controller = $this->makeController(
            ['203.0.113.10'],
            $submissions,
            auditLog: $audit,
            webhookDeliveries: $webhookDeliveries
        );
        $this->login($controller);

        $delivery = $controller->handle('admin/delivery');
        $auditPage = $controller->handle('admin/audit');

        $this->assertSame(200, $delivery['status']);
        $this->assertStringContainsString('Delivery log', $delivery['body']);
        $this->assertStringContainsString('Integration deliveries', $delivery['body']);
        $this->assertStringContainsString('Slack', $delivery['body']);
        $this->assertSame(200, $auditPage['status']);
        $this->assertStringContainsString('test.action', $auditPage['body']);
    }

    public function testDashboardFiltersIncludeSearchDatesAndPageSize(): void
    {
        $submissions = new SqliteSubmissionRepository(':memory:');
        $submissions->create('contact', ['name' => 'Ada', 'email' => 'ada@example.com'], null);
        $submissions->create('support', ['name' => 'Grace'], null);
        $controller = $this->makeController(['203.0.113.10'], $submissions);
        $this->login($controller);

        $_GET = [
            'q' => 'ada@example.com',
            'date_from' => '2000-01-01',
            'date_to' => '2999-12-31',
            'per_page' => '50',
        ];

        $result = $controller->handle('admin');

        $this->assertSame(200, $result['status']);
        $this->assertStringContainsString('value="ada@example.com"', $result['body']);
        $this->assertStringContainsString('value="2000-01-01"', $result['body']);
        $this->assertStringContainsString('<option value="50" selected', $result['body']);
    }

    public function testRecoveryTokenCanResetBootstrapPassword(): void
    {
        $files = $this->settingsFiles();
        $token = 'recovery-token';
        file_put_contents($files['env'], "RECOVERY_TOKEN_HASH='" . password_hash($token, PASSWORD_DEFAULT) . "'" . PHP_EOL, FILE_APPEND);

        try {
            $controller = $this->makeController(
                envPath: $files['env'],
                adminConfigPath: $files['admin'],
                securityConfigPath: $files['security']
            );

            $_GET = ['token' => $token];
            $redirect = $controller->handle('admin/recovery');
            $this->assertSame(302, $redirect['status']);
            $this->assertSame('/admin/recovery', $redirect['redirect']);

            $_GET = [];
            $get = $controller->handle('admin/recovery');
            $this->assertSame(200, $get['status']);
            $tokenCsrf = $this->csrfToken();

            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_POST = [
                'token' => $token,
                'password' => 'new-bootstrap-password',
                'csrf_token' => $tokenCsrf,
            ];

            $post = $controller->handle('admin/recovery');

            $this->assertSame(302, $post['status']);
            $env = (string) file_get_contents($files['env']);
            $this->assertStringContainsString("RECOVERY_TOKEN_HASH=''", $env);
            preg_match("/ADMIN_PASSWORD_HASH='([^']+)'/", $env, $matches);
            $this->assertTrue(password_verify('new-bootstrap-password', $matches[1]));
        } finally {
            $_GET = [];
            $this->removeSettingsFiles($files);
        }
    }

    public function testRecoveryRejectsInvalidTokenAfterRedirectHop(): void
    {
        $files = $this->settingsFiles();
        file_put_contents($files['env'], "RECOVERY_TOKEN_HASH='" . password_hash('real-token', PASSWORD_DEFAULT) . "'" . PHP_EOL, FILE_APPEND);

        try {
            $controller = $this->makeController(
                envPath: $files['env'],
                adminConfigPath: $files['admin'],
                securityConfigPath: $files['security']
            );

            $_GET = ['token' => 'wrong-token'];
            $redirect = $controller->handle('admin/recovery');
            $this->assertSame(302, $redirect['status']);

            $_GET = [];
            $result = $controller->handle('admin/recovery');
            $this->assertSame(403, $result['status']);
        } finally {
            $_GET = [];
            $this->removeSettingsFiles($files);
        }
    }

    public function testConfigExportAndBackupRejectMissingCsrfToken(): void
    {
        $files = $this->settingsFiles();

        try {
            $controller = $this->makeController(
                envPath: $files['env'],
                adminConfigPath: $files['admin'],
                securityConfigPath: $files['security']
            );
            $this->login($controller);

            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_POST = [];
            $this->assertSame(403, $controller->handle('admin/config/export')['status']);
            $this->assertSame(403, $controller->handle('admin/backup')['status']);

            $_POST = ['csrf_token' => 'wrong-token'];
            $this->assertSame(403, $controller->handle('admin/config/export')['status']);
        } finally {
            $_POST = [];
            $this->removeSettingsFiles($files);
        }
    }

    public function testConfigExportReturnsJson(): void
    {
        $files = $this->settingsFiles();

        try {
            $controller = $this->makeController(
                envPath: $files['env'],
                adminConfigPath: $files['admin'],
                securityConfigPath: $files['security']
            );
            $this->login($controller);

            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_POST = ['csrf_token' => $this->csrfToken()];
            $result = $controller->handle('admin/config/export');

            $this->assertSame(200, $result['status']);
            $this->assertSame('application/json; charset=utf-8', $result['headers']['Content-Type']);
            $this->assertIsArray(json_decode($result['body'], true));
        } finally {
            $_POST = [];
            $this->removeSettingsFiles($files);
        }
    }
}
