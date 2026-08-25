<?php

declare(strict_types=1);

namespace formflow\Tests;

use formflow\Admin\AdminController;
use formflow\Admin\AdminSettingsService;
use formflow\AdminAuth;
use formflow\AdminIpWhitelist;
use formflow\CurlCaptchaVerifier;
use formflow\FormHandler;
use formflow\Install\InstallController;
use formflow\SqliteAdminUserRepository;
use formflow\SqliteAdminWhitelistRepository;
use formflow\SqliteAuditLogRepository;
use formflow\SqliteFormApiKeyRepository;
use formflow\SqliteFormRepository;
use formflow\SqliteRateLimiter;
use formflow\SqliteSubmissionRepository;
use formflow\SqliteTotpReplayGuard;
use formflow\SqliteWebhookDeliveryRepository;
use formflow\Tests\Fakes\FakeMailSender;
use formflow\Tests\Fakes\FakeTurnstileVerifier;
use PHPUnit\Framework\TestCase;

final class ProductionSmokeTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/formflow-production-smoke-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/config', 0775, true);
        mkdir($this->root . '/storage/uploads', 0775, true);
        file_put_contents($this->root . '/config/admin.php', "<?php\n\ndeclare(strict_types=1);\n\nreturn ['allowed_ips' => [], 'login_rate_limit' => ['max' => 5, 'window_minutes' => 15]];\n");
        file_put_contents($this->root . '/config/security.php', "<?php\n\ndeclare(strict_types=1);\n\nreturn ['blocked_ips' => [], 'trusted_proxies' => [], 'trusted_ip_headers' => []];\n");

        $_SERVER['REMOTE_ADDR'] = '203.0.113.10';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTP_ORIGIN'] = 'https://example.com';
        $_GET = [];
        $_POST = [];
        $_FILES = [];
    }

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }

        $_SESSION = [];
        $_GET = [];
        $_POST = [];
        $_FILES = [];
        unset($_SERVER['REMOTE_ADDR'], $_SERVER['REQUEST_METHOD'], $_SERVER['HTTP_ORIGIN']);
        $this->removeDirectory($this->root);
    }

    public function testFreshInstallCreateSubmitExportAndBackup(): void
    {
        $envPath = $this->root . '/.env';
        $adminConfigPath = $this->root . '/config/admin.php';
        $securityConfigPath = $this->root . '/config/security.php';
        $databasePath = $this->root . '/storage/submissions.sqlite';

        $installer = new InstallController($envPath, $adminConfigPath, '203.0.113.10');
        $installPage = $installer->handle();
        $this->assertSame(200, $installPage['status']);

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'csrf_token' => $this->csrfToken(),
            'admin_username' => 'admin',
            'admin_password' => 'correct-password',
            'app_url' => 'https://forms.example.com',
            'smtp_port' => '587',
            'smtp_encryption' => 'tls',
            'mail_from_name' => 'formflow',
        ];
        $installResult = $installer->handle();
        $this->assertSame(302, $installResult['status']);
        $this->assertFileExists($envPath);

        $settingsService = new AdminSettingsService(
            ['203.0.113.10'],
            $envPath,
            $adminConfigPath,
            $securityConfigPath
        );
        $settings = $settingsService->currentSettings();
        $submissions = new SqliteSubmissionRepository($databasePath, $this->root . '/storage/uploads');
        $apiKeys = new SqliteFormApiKeyRepository($databasePath);
        $formRepository = new SqliteFormRepository($databasePath);
        $adminUsers = new SqliteAdminUserRepository($databasePath);
        $audit = new SqliteAuditLogRepository($databasePath);
        $mail = new FakeMailSender();
        $controller = $this->adminController(
            $settings,
            $databasePath,
            $envPath,
            $adminConfigPath,
            $securityConfigPath,
            $settingsService,
            $submissions,
            $apiKeys,
            $formRepository,
            [],
            $adminUsers,
            $audit,
            $mail
        );

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_POST = [];
        $controller->handle('admin/login');
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'csrf_token' => $this->csrfToken(),
            'username' => 'admin',
            'password' => 'correct-password',
        ];
        $login = $controller->handle('admin/login');
        $this->assertSame(302, $login['status']);

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_POST = [];
        $controller->handle('admin/forms/new');
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'csrf_token' => $this->csrfToken(),
            'form_id' => 'contact',
            'recipient' => 'hello@example.com',
            'allowed_origins' => 'https://example.com',
            'captcha_provider' => 'none',
            'require_api_key' => '1',
        ];
        $created = $controller->handle('admin/forms/new');
        $this->assertSame(302, $created['status']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $apiKeys->get('contact'));

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            '_key' => (string) $apiKeys->get('contact'),
            'email' => 'ada@example.com',
            'message' => 'Smoke submission',
        ];
        $handler = new FormHandler(
            $formRepository->all(),
            $mail,
            $submissions,
            new FakeTurnstileVerifier(true),
            new SqliteRateLimiter($databasePath),
            'test-ip-hash-secret',
            $apiKeys,
            null,
            $this->root . '/storage/uploads',
            new CurlCaptchaVerifier([]),
            '203.0.113.10'
        );
        $submitted = $handler->handle('contact');
        $this->assertSame(200, $submitted['status']);
        $this->assertSame(1, $submissions->count('contact', null));

        $controller = $this->adminController(
            $settings,
            $databasePath,
            $envPath,
            $adminConfigPath,
            $securityConfigPath,
            $settingsService,
            $submissions,
            $apiKeys,
            $formRepository,
            $formRepository->all(),
            $adminUsers,
            $audit,
            $mail
        );

        $_POST = ['csrf_token' => $this->csrfToken()];
        $export = $controller->handle('admin/config/export');
        $this->assertSame(200, $export['status']);
        $this->assertStringContainsString('"contact"', $export['body']);

        $backup = $controller->handle('admin/backup');
        $this->assertSame(200, $backup['status']);
        $this->assertStringStartsWith('SQLite format 3', $backup['body']);
    }

    private function csrfToken(): string
    {
        return (string) ($_SESSION['csrf_token'] ?? '');
    }

    /**
     * @param array<string, mixed> $settings
     * @param array<string, array<string, mixed>> $forms
     */
    private function adminController(
        array $settings,
        string $databasePath,
        string $envPath,
        string $adminConfigPath,
        string $securityConfigPath,
        AdminSettingsService $settingsService,
        SqliteSubmissionRepository $submissions,
        SqliteFormApiKeyRepository $apiKeys,
        SqliteFormRepository $formRepository,
        array $forms,
        SqliteAdminUserRepository $adminUsers,
        SqliteAuditLogRepository $audit,
        FakeMailSender $mail
    ): AdminController {
        return new AdminController(
            new AdminAuth(
                (string) $settings['admin_username'],
                (string) ($settings['admin_password_hash'] ?? $this->envValue($envPath, 'ADMIN_PASSWORD_HASH')),
                new SqliteRateLimiter($databasePath),
                5,
                15,
                $adminUsers,
                '',
                new SqliteTotpReplayGuard($databasePath)
            ),
            new AdminIpWhitelist(['203.0.113.10'], new SqliteAdminWhitelistRepository($databasePath)),
            $submissions,
            new SqliteAdminWhitelistRepository($databasePath),
            ['203.0.113.10'],
            'test-ip-hash-secret',
            $apiKeys,
            $forms,
            $formRepository,
            false,
            $envPath,
            $adminConfigPath,
            $securityConfigPath,
            $adminUsers,
            $audit,
            $mail,
            new SqliteWebhookDeliveryRepository($databasePath),
            '203.0.113.10',
            $this->root . '/storage/uploads',
            $this->root,
            $settingsService
        );
    }

    private function envValue(string $path, string $key): string
    {
        preg_match('/^' . preg_quote($key, '/') . "='(.*)'$/m", (string) file_get_contents($path), $matches);

        return $matches[1] ?? '';
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $items = scandir($directory) ?: [];

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $item;

            if (is_dir($path)) {
                $this->removeDirectory($path);
                continue;
            }

            unlink($path);
        }

        rmdir($directory);
    }
}
