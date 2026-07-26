<?php

declare(strict_types=1);

namespace formflow\Tests\Install;

use formflow\Install\InstallController;
use PHPUnit\Framework\TestCase;

final class InstallControllerTest extends TestCase
{
    private string $envPath;
    private string $adminConfigPath;

    protected function setUp(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REMOTE_ADDR'] = '203.0.113.10';
        $_POST = [];

        $this->envPath = tempnam(sys_get_temp_dir(), 'formflow-env-');
        unlink($this->envPath);

        $this->adminConfigPath = tempnam(sys_get_temp_dir(), 'formflow-admin-');
        file_put_contents($this->adminConfigPath, "<?php\ndeclare(strict_types=1);\nreturn ['allowed_ips' => [], 'login_rate_limit' => ['max' => 5, 'window_minutes' => 15]];\n");
    }

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }

        $_SESSION = [];

        unset($_SERVER['REQUEST_METHOD'], $_SERVER['REMOTE_ADDR']);

        @unlink($this->envPath);
        @unlink($this->adminConfigPath);
    }

    private function makeController(): InstallController
    {
        return new InstallController($this->envPath, $this->adminConfigPath, $_SERVER['REMOTE_ADDR'] ?? null);
    }

    private function csrfToken(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        return $_SESSION['csrf_token'] ?? '';
    }

    private function validPayload(): array
    {
        return [
            'app_url' => 'https://forms.example.com',
            'mailer_dsn' => '',
            'smtp_host' => 'smtp.example.com',
            'smtp_port' => '587',
            'smtp_encryption' => 'tls',
            'smtp_username' => 'user',
            'smtp_password' => 'pass',
            'mail_from' => 'forms@example.com',
            'mail_from_name' => 'Example Forms',
            'turnstile_secret' => 'turnstile-secret',
            'admin_username' => 'admin',
            'admin_password' => 'a-strong-password',
        ];
    }

    public function testGetRendersForm(): void
    {
        $controller = $this->makeController();

        $result = $controller->handle();

        $this->assertSame(200, $result['status']);
        $this->assertStringContainsString('<form', $result['body']);
    }

    public function testGetPrefillsAppUrlFromRequestHost(): void
    {
        $_SERVER['HTTP_HOST'] = 'localhost:8091';
        unset($_SERVER['HTTPS'], $_SERVER['HTTP_X_FORWARDED_PROTO']);

        $controller = $this->makeController();
        $result = $controller->handle();

        $this->assertStringContainsString('value="http://localhost:8091"', $result['body']);

        unset($_SERVER['HTTP_HOST']);
    }

    public function testGetPrefillsHttpsAppUrlBehindForwardedProto(): void
    {
        $_SERVER['HTTP_HOST'] = 'forms.example.com';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';

        $controller = $this->makeController();
        $result = $controller->handle();

        $this->assertStringContainsString('value="https://forms.example.com"', $result['body']);

        unset($_SERVER['HTTP_HOST'], $_SERVER['HTTP_X_FORWARDED_PROTO']);
    }

    public function testPostWithSingleLineBreakInFieldReturns422(): void
    {
        $controller = $this->makeController();
        $controller->handle();
        $token = $this->csrfToken();

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = $this->validPayload();
        $_POST['mail_from_name'] = "Example\nADMIN_USERNAME=attacker";
        $_POST['csrf_token'] = $token;

        $result = $controller->handle();

        $this->assertSame(422, $result['status']);
        $this->assertFileDoesNotExist($this->envPath);
    }

    public function testPostWithoutCsrfTokenReturns419(): void
    {
        $controller = $this->makeController();
        $controller->handle();

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = $this->validPayload();

        $result = $controller->handle();

        $this->assertSame(419, $result['status']);
        $this->assertFileDoesNotExist($this->envPath);
    }

    public function testPostWithMissingAdminUsernameReturns422(): void
    {
        $controller = $this->makeController();
        $controller->handle();
        $token = $this->csrfToken();

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = $this->validPayload();
        unset($_POST['admin_username']);
        $_POST['csrf_token'] = $token;

        $result = $controller->handle();

        $this->assertSame(422, $result['status']);
        $this->assertFileDoesNotExist($this->envPath);
    }

    public function testPostWithOnlyAdminFieldsInstallsSuccessfully(): void
    {
        $controller = $this->makeController();
        $controller->handle();
        $token = $this->csrfToken();

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'admin_username' => 'admin',
            'admin_password' => 'a-strong-password',
            'csrf_token' => $token,
        ];

        $result = $controller->handle();

        $this->assertSame(302, $result['status']);
        $this->assertSame('/admin/login', $result['redirect']);

        $env = file_get_contents($this->envPath);
        $this->assertStringContainsString("APP_URL=''", $env);
        $this->assertStringContainsString("MAILER_DSN=''", $env);
        $this->assertStringContainsString("SMTP_HOST=''", $env);
        $this->assertStringContainsString("SMTP_PORT='587'", $env);
        $this->assertStringContainsString("SMTP_ENCRYPTION='tls'", $env);
        $this->assertStringContainsString("MAIL_FROM=''", $env);
        $this->assertStringContainsString("TURNSTILE_SECRET=''", $env);
        $this->assertStringContainsString("MAIL_FROM_NAME='formflow'", $env);
        $this->assertStringContainsString("ADMIN_USERNAME='admin'", $env);
    }

    public function testPostWithShortPasswordReturns422(): void
    {
        $controller = $this->makeController();
        $controller->handle();
        $token = $this->csrfToken();

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = $this->validPayload();
        $_POST['admin_password'] = 'short';
        $_POST['csrf_token'] = $token;

        $result = $controller->handle();

        $this->assertSame(422, $result['status']);
        $this->assertFileDoesNotExist($this->envPath);
    }

    public function testPostWithSingleQuoteInFieldReturns422(): void
    {
        $controller = $this->makeController();
        $controller->handle();
        $token = $this->csrfToken();

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = $this->validPayload();
        $_POST['admin_username'] = "ad'min";
        $_POST['csrf_token'] = $token;

        $result = $controller->handle();

        $this->assertSame(422, $result['status']);
        $this->assertFileDoesNotExist($this->envPath);
    }

    public function testPostWithValidDataWritesEnvAndRedirects(): void
    {
        $controller = $this->makeController();
        $controller->handle();
        $token = $this->csrfToken();

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = $this->validPayload();
        $_POST['csrf_token'] = $token;

        $result = $controller->handle();

        $this->assertSame(302, $result['status']);
        $this->assertSame('/admin/login', $result['redirect']);
        $this->assertFileExists($this->envPath);

        $env = file_get_contents($this->envPath);
        $this->assertStringContainsString("APP_URL='https://forms.example.com'", $env);
        $this->assertStringContainsString("MAILER_DSN=''", $env);
        $this->assertStringContainsString("SMTP_HOST='smtp.example.com'", $env);
        $this->assertStringContainsString("SMTP_PORT='587'", $env);
        $this->assertStringContainsString("SMTP_ENCRYPTION='tls'", $env);
        $this->assertStringContainsString("SMTP_USERNAME='user'", $env);
        $this->assertStringContainsString("SMTP_PASSWORD='pass'", $env);
        $this->assertStringContainsString("ADMIN_USERNAME='admin'", $env);
        $this->assertMatchesRegularExpression("/ADMIN_PASSWORD_HASH='\\\$2y\\\$/", $env);

        preg_match("/ADMIN_PASSWORD_HASH='([^']+)'/", $env, $matches);
        $this->assertTrue(password_verify('a-strong-password', $matches[1]));

        preg_match("/IP_HASH_SECRET='([^']+)'/", $env, $ipHashMatches);
        $this->assertNotSame('', $ipHashMatches[1]);
    }

    public function testPostWithValidDataAppendsClientIpToAdminConfig(): void
    {
        $_SERVER['REMOTE_ADDR'] = '198.51.100.42';
        $controller = $this->makeController();
        $controller->handle();
        $token = $this->csrfToken();

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = $this->validPayload();
        $_POST['csrf_token'] = $token;

        $controller->handle();

        $adminConfig = require $this->adminConfigPath;

        $this->assertContains('198.51.100.42', $adminConfig['allowed_ips']);
        $this->assertSame(5, $adminConfig['login_rate_limit']['max']);
        $this->assertSame(15, $adminConfig['login_rate_limit']['window_minutes']);
    }

    public function testPostDoesNotDuplicateAlreadyAllowedIp(): void
    {
        file_put_contents(
            $this->adminConfigPath,
            "<?php\ndeclare(strict_types=1);\nreturn ['allowed_ips' => ['198.51.100.42'], 'login_rate_limit' => ['max' => 5, 'window_minutes' => 15]];\n"
        );

        $_SERVER['REMOTE_ADDR'] = '198.51.100.42';
        $controller = $this->makeController();
        $controller->handle();
        $token = $this->csrfToken();

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = $this->validPayload();
        $_POST['csrf_token'] = $token;

        $controller->handle();

        $adminConfig = require $this->adminConfigPath;

        $this->assertSame(['198.51.100.42'], $adminConfig['allowed_ips']);
    }

    public function testEmptyTurnstileSecretIsAllowed(): void
    {
        $controller = $this->makeController();
        $controller->handle();
        $token = $this->csrfToken();

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = $this->validPayload();
        $_POST['turnstile_secret'] = '';
        $_POST['csrf_token'] = $token;

        $result = $controller->handle();

        $this->assertSame(302, $result['status']);

        $env = file_get_contents($this->envPath);
        $this->assertStringContainsString("TURNSTILE_SECRET=''", $env);
    }

    public function testUnwritableEnvDirectoryShowsErrorInsteadOfForm(): void
    {
        if (posix_geteuid() === 0) {
            $this->markTestSkipped('Running as root ignores permission bits.');
        }

        $readOnlyDir = sys_get_temp_dir() . '/formflow-readonly-' . bin2hex(random_bytes(4));
        mkdir($readOnlyDir, 0755);
        $this->envPath = $readOnlyDir . '/.env';
        chmod($readOnlyDir, 0555);

        try {
            $controller = $this->makeController();
            $result = $controller->handle();

            $this->assertSame(503, $result['status']);
            $this->assertStringContainsString($readOnlyDir, $result['body']);
            $this->assertFileDoesNotExist($this->envPath);
        } finally {
            chmod($readOnlyDir, 0755);
            @rmdir($readOnlyDir);
        }
    }

    public function testUnwritableAdminConfigFileShowsErrorInsteadOfForm(): void
    {
        if (posix_geteuid() === 0) {
            $this->markTestSkipped('Running as root ignores permission bits.');
        }

        chmod($this->adminConfigPath, 0444);

        try {
            $controller = $this->makeController();
            $result = $controller->handle();

            $this->assertSame(503, $result['status']);
            $this->assertStringContainsString($this->adminConfigPath, $result['body']);
        } finally {
            chmod($this->adminConfigPath, 0644);
        }
    }
}
