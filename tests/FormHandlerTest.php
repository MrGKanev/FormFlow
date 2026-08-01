<?php

declare(strict_types=1);

namespace formflow\Tests;

use formflow\FormApiKeyRepositoryInterface;
use formflow\FormHandler;
use formflow\SqliteFormApiKeyRepository;
use formflow\SqliteRateLimiter;
use formflow\SqliteSubmissionRepository;
use formflow\Tests\Fakes\FakeCaptchaVerifier;
use formflow\Tests\Fakes\FakeMailSender;
use formflow\Tests\Fakes\FakeTurnstileVerifier;
use formflow\Tests\Fakes\FakeWebhookNotifier;
use formflow\WebhookNotifierInterface;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class FormHandlerTest extends TestCase
{
    protected function setUp(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['HTTP_ORIGIN'] = 'https://example.com';
        $_SERVER['REMOTE_ADDR'] = '198.51.100.10';
        unset($_SERVER['HTTP_REFERER']);
        $_POST = [];
        $_FILES = [];
    }

    protected function tearDown(): void
    {
        unset(
            $_SERVER['REQUEST_METHOD'],
            $_SERVER['HTTP_ORIGIN'],
            $_SERVER['HTTP_REFERER'],
            $_SERVER['REMOTE_ADDR']
        );
        $_POST = [];
        $_FILES = [];
    }

    /** @param array<string, mixed> $overrides */
    private function contactForm(array $overrides = []): array
    {
        return array_merge([
            'recipient' => 'hello@example.com',
            'allowed_origins' => ['https://example.com'],
            'subject' => 'New contact form submission',
            'success_redirect' => 'https://example.com/thank-you',
            'turnstile' => true,
        ], $overrides);
    }

    private function makeHandler(
        array $forms,
        ?FakeMailSender $mailSender = null,
        ?FakeTurnstileVerifier $turnstile = null,
        ?SqliteSubmissionRepository $repository = null,
        ?SqliteRateLimiter $rateLimiter = null,
        ?FormApiKeyRepositoryInterface $apiKeys = null,
        string $uploadDirectory = '',
        ?WebhookNotifierInterface $webhookNotifier = null,
        ?FakeCaptchaVerifier $captchaVerifier = null,
        bool $deferMail = false
    ): FormHandler {
        return new FormHandler(
            $forms,
            $mailSender ?? new FakeMailSender(),
            $repository ?? new SqliteSubmissionRepository(':memory:'),
            $turnstile ?? new FakeTurnstileVerifier(true),
            $rateLimiter ?? new SqliteRateLimiter(':memory:'),
            'test-secret',
            $apiKeys ?? new SqliteFormApiKeyRepository(':memory:'),
            $webhookNotifier,
            $uploadDirectory,
            $captchaVerifier,
            null,
            $deferMail
        );
    }

    public function testUploadPolicyRejectsDisallowedFileExtension(): void
    {
        $directory = sys_get_temp_dir() . '/formflow-upload-' . bin2hex(random_bytes(6));
        $_POST = ['email' => 'ada@example.com'];
        $_FILES = [
            'attachment' => [
                'name' => 'payload.exe',
                'tmp_name' => '',
                'error' => UPLOAD_ERR_OK,
                'size' => 1,
            ],
        ];
        $handler = $this->makeHandler([
            'contact' => $this->contactForm([
                'uploads' => [
                    'max_file_size_mb' => 2,
                    'max_files' => 1,
                    'allowed_extensions' => ['pdf'],
                ],
            ]),
        ], uploadDirectory: $directory);

        try {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('unsupported file type');
            $handler->handle('contact');
        } finally {
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

    public function testUploadPolicyStoresAnAllowedFile(): void
    {
        $directory = sys_get_temp_dir() . '/formflow-upload-' . bin2hex(random_bytes(6));
        $source = tempnam(sys_get_temp_dir(), 'formflow-source-');
        self::assertNotFalse($source);
        file_put_contents($source, "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF\n");
        $_POST = ['email' => 'ada@example.com'];
        $_FILES = [
            'attachment' => [
                'name' => 'document.PDF',
                'tmp_name' => $source,
                'error' => UPLOAD_ERR_OK,
                'size' => filesize($source),
            ],
        ];
        $mailSender = new FakeMailSender();
        $repository = new SqliteSubmissionRepository(':memory:');
        $handler = $this->makeHandler(
            ['contact' => $this->contactForm(['turnstile' => false, 'uploads' => ['allowed_extensions' => ['pdf']]])],
            $mailSender,
            null,
            $repository,
            null,
            null,
            $directory
        );

        try {
            $result = $handler->handle('contact');

            $this->assertSame(200, $result['status']);
            $this->assertCount(1, $mailSender->sentMessages);
            $this->assertStringContainsString('document.PDF', $mailSender->sentMessages[0]['fields']['attachment']);
            $this->assertCount(1, glob($directory . '/*') ?: []);
            $payload = json_decode((string) $repository->find(1)['payload'], true, flags: JSON_THROW_ON_ERROR);
            $this->assertArrayHasKey('attachment', $payload);
            $this->assertSame('upload', $payload['attachment']['type']);
            $this->assertSame('document.PDF', $payload['attachment']['original_name']);
            $this->assertNotSame('', $payload['attachment']['stored_name']);
            $this->assertSame(basename((string) $payload['attachment']['stored_name']), $payload['attachment']['stored_name']);
            $this->assertArrayNotHasKey('relative_path', $payload['attachment']);
            $this->assertArrayNotHasKey('path', $payload['attachment']);
        } finally {
            foreach (glob($directory . '/*') ?: [] as $file) {
                unlink($file);
            }
            if (is_dir($directory)) {
                rmdir($directory);
            }
            if (is_file($source)) {
                unlink($source);
            }
        }
    }

    public function testUploadPolicyRejectsDangerousExtensionWithoutAllowList(): void
    {
        $directory = sys_get_temp_dir() . '/formflow-upload-' . bin2hex(random_bytes(6));
        $_POST = ['email' => 'ada@example.com'];
        $_FILES = [
            'attachment' => [
                'name' => 'shell.php',
                'tmp_name' => '',
                'error' => UPLOAD_ERR_OK,
                'size' => 1,
            ],
        ];
        $handler = $this->makeHandler(
            ['contact' => $this->contactForm(['turnstile' => false])],
            uploadDirectory: $directory
        );

        try {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('unsupported file type');
            $handler->handle('contact');
        } finally {
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

    public function testUploadPolicyRejectsKnownExtensionWithWrongMimeType(): void
    {
        $directory = sys_get_temp_dir() . '/formflow-upload-' . bin2hex(random_bytes(6));
        $source = tempnam(sys_get_temp_dir(), 'formflow-source-');
        self::assertNotFalse($source);
        file_put_contents($source, 'plain text, not a pdf');
        $_POST = ['email' => 'ada@example.com'];
        $_FILES = [
            'attachment' => [
                'name' => 'document.pdf',
                'tmp_name' => $source,
                'error' => UPLOAD_ERR_OK,
                'size' => filesize($source),
            ],
        ];
        $handler = $this->makeHandler(
            ['contact' => $this->contactForm(['turnstile' => false, 'uploads' => ['allowed_extensions' => ['pdf']]])],
            uploadDirectory: $directory
        );

        try {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('unsupported MIME type');
            $handler->handle('contact');
        } finally {
            if (is_file($source)) {
                unlink($source);
            }
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

    public function testUploadPolicyRejectsTooManyFilesBeforeStoringAnyFile(): void
    {
        $directory = sys_get_temp_dir() . '/formflow-upload-' . bin2hex(random_bytes(6));
        $first = tempnam(sys_get_temp_dir(), 'formflow-source-');
        $second = tempnam(sys_get_temp_dir(), 'formflow-source-');
        self::assertNotFalse($first);
        self::assertNotFalse($second);
        file_put_contents($first, 'first');
        file_put_contents($second, 'second');
        $_POST = ['email' => 'ada@example.com'];
        $_FILES = [
            'attachments' => [
                'name' => ['first.pdf', 'second.pdf'],
                'tmp_name' => [$first, $second],
                'error' => [UPLOAD_ERR_OK, UPLOAD_ERR_OK],
                'size' => [filesize($first), filesize($second)],
            ],
        ];
        $handler = $this->makeHandler(
            ['contact' => $this->contactForm(['uploads' => ['max_files' => 1, 'allowed_extensions' => ['pdf']]])],
            uploadDirectory: $directory
        );

        try {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('Too many files were uploaded.');
            $handler->handle('contact');
        } finally {
            $this->assertSame([], glob($directory . '/*') ?: []);
            if (is_dir($directory)) {
                rmdir($directory);
            }
            if (is_file($first)) {
                unlink($first);
            }
            if (is_file($second)) {
                unlink($second);
            }
        }
    }

    public function testUploadPolicyRejectsFilesAboveTheConfiguredSize(): void
    {
        $directory = sys_get_temp_dir() . '/formflow-upload-' . bin2hex(random_bytes(6));
        $_POST = ['email' => 'ada@example.com'];
        $_FILES = [
            'attachment' => [
                'name' => 'document.pdf',
                'tmp_name' => '',
                'error' => UPLOAD_ERR_OK,
                'size' => 1024 * 1024 + 1,
            ],
        ];
        $handler = $this->makeHandler(
            ['contact' => $this->contactForm(['uploads' => ['max_file_size_mb' => 1]])],
            uploadDirectory: $directory
        );

        try {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('is too large');
            $handler->handle('contact');
        } finally {
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

    public function testPassesSelectedNotificationChannelsToWebhookNotifier(): void
    {
        $_POST = ['email' => 'ada@example.com'];
        $notifier = new FakeWebhookNotifier();
        $handler = $this->makeHandler(
            ['contact' => $this->contactForm(['turnstile' => false, 'notification_channels' => ['slack']])],
            webhookNotifier: $notifier
        );

        $handler->handle('contact');

        $this->assertSame(['slack'], $notifier->notifications[0]['channels']);
    }

    public function testPassesPerFormNotificationOverridesToWebhookNotifier(): void
    {
        $_POST = ['email' => 'ada@example.com'];
        $notifier = new FakeWebhookNotifier();
        $handler = $this->makeHandler(
            ['contact' => $this->contactForm([
                'turnstile' => false,
                'notification_channels' => ['slack'],
                'notification_overrides' => [
                    'slack_webhook_url' => 'https://hooks.slack.test/form-specific',
                ],
            ])],
            webhookNotifier: $notifier
        );

        $handler->handle('contact');

        $this->assertSame([
            'slack_webhook_url' => 'https://hooks.slack.test/form-specific',
        ], $notifier->notifications[0]['overrides']);
    }

    public function testPassesNullChannelsForLegacyFormConfigurations(): void
    {
        $_POST = ['email' => 'ada@example.com'];
        $notifier = new FakeWebhookNotifier();
        $handler = $this->makeHandler(
            ['contact' => $this->contactForm(['turnstile' => false])],
            webhookNotifier: $notifier
        );

        $handler->handle('contact');

        $this->assertNull($notifier->notifications[0]['channels']);
    }

    public function testUnknownFormReturns404(): void
    {
        $handler = $this->makeHandler(['contact' => $this->contactForm()]);

        $result = $handler->handle('missing-form');

        $this->assertSame(404, $result['status']);
        $this->assertFalse($result['body']['success']);
    }

    public function testNonPostMethodReturns405(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $handler = $this->makeHandler(['contact' => $this->contactForm()]);

        $result = $handler->handle('contact');

        $this->assertSame(405, $result['status']);
    }

    public function testDisallowedOriginThrowsInvalidArgumentException(): void
    {
        $_SERVER['HTTP_ORIGIN'] = 'https://evil.example';

        $handler = $this->makeHandler(['contact' => $this->contactForm()]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Origin is not allowed.');

        $handler->handle('contact');
    }

    public function testHoneypotFieldReturnsFakeSuccessAndLogsBlockedHoneypot(): void
    {
        $_POST = [
            'name' => 'Bot',
            'email' => 'bot@example.com',
            'message' => 'spam',
            '_website' => 'http://spam.example',
        ];

        $mailSender = new FakeMailSender();
        $repository = new SqliteSubmissionRepository(':memory:');

        $handler = $this->makeHandler(
            ['contact' => $this->contactForm()],
            $mailSender,
            null,
            $repository
        );

        $result = $handler->handle('contact');

        $this->assertSame(200, $result['status']);
        $this->assertTrue($result['body']['success']);
        $this->assertSame([], $mailSender->sentMessages);

        $row = $repository->find(1);
        $this->assertSame('blocked_honeypot', $row['status']);
    }

    public function testInvalidEmailThrowsInvalidArgumentException(): void
    {
        $_POST = ['name' => 'Ada', 'email' => 'not-an-email', 'message' => 'Hello'];

        $handler = $this->makeHandler(['contact' => $this->contactForm()]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The email field is invalid.');

        $handler->handle('contact');
    }

    public function testFailedTurnstileReturns422(): void
    {
        $_POST = [
            'name' => 'Ada',
            'email' => 'ada@example.com',
            'message' => 'Hello',
            'cf-turnstile-response' => 'bad-token',
        ];

        $handler = $this->makeHandler(
            ['contact' => $this->contactForm()],
            null,
            new FakeTurnstileVerifier(false)
        );

        $result = $handler->handle('contact');

        $this->assertSame(422, $result['status']);
        $this->assertFalse($result['body']['success']);
    }

    public function testConfiguredCaptchaProviderUsesMatchingResponseField(): void
    {
        $_POST = [
            'name' => 'Ada',
            'email' => 'ada@example.com',
            'message' => 'Hello',
            'h-captcha-response' => 'hcaptcha-token',
        ];

        $mailSender = new FakeMailSender();
        $captchaVerifier = new FakeCaptchaVerifier(true);
        $handler = $this->makeHandler(
            ['contact' => $this->contactForm(['captcha_provider' => 'hcaptcha', 'turnstile' => false])],
            $mailSender,
            captchaVerifier: $captchaVerifier
        );

        $result = $handler->handle('contact');

        $this->assertSame(200, $result['status']);
        $this->assertSame([
            'provider' => 'hcaptcha',
            'token' => 'hcaptcha-token',
            'remote_ip' => '198.51.100.10',
        ], $captchaVerifier->verifications[0]);
        $this->assertArrayNotHasKey('h-captcha-response', $mailSender->sentMessages[0]['fields']);
    }

    public function testConfiguredCaptchaProviderRejectsFailedVerification(): void
    {
        $_POST = [
            'name' => 'Ada',
            'email' => 'ada@example.com',
            'message' => 'Hello',
            'g-recaptcha-response' => 'recaptcha-token',
        ];

        $handler = $this->makeHandler(
            ['contact' => $this->contactForm(['captcha_provider' => 'recaptcha', 'turnstile' => false])],
            captchaVerifier: new FakeCaptchaVerifier(false)
        );

        $result = $handler->handle('contact');

        $this->assertSame(422, $result['status']);
        $this->assertSame('CAPTCHA validation failed.', $result['body']['message']);
    }

    public function testSuccessfulSubmissionSendsEmailAndStoresSent(): void
    {
        $_POST = [
            'name' => 'Ada',
            'email' => 'ada@example.com',
            'message' => 'Hello',
            'cf-turnstile-response' => 'good-token',
        ];

        $mailSender = new FakeMailSender();
        $repository = new SqliteSubmissionRepository(':memory:');

        $handler = $this->makeHandler(
            ['contact' => $this->contactForm()],
            $mailSender,
            new FakeTurnstileVerifier(true),
            $repository
        );

        $result = $handler->handle('contact');

        $this->assertSame(200, $result['status']);
        $this->assertTrue($result['body']['success']);
        $this->assertSame('https://example.com/thank-you', $result['redirect']);
        $this->assertCount(1, $mailSender->sentMessages);
        $this->assertSame('hello@example.com', $mailSender->sentMessages[0]['recipient']);

        $row = $repository->find(1);
        $this->assertSame('sent', $row['status']);
    }

    public function testSubmissionAcceptsAllUserFields(): void
    {
        $_POST = [
            'name' => 'Ada',
            'email' => 'ada@example.com',
            'project_type' => 'Migration',
            'preferred_date' => '2026-08-01',
            '_key' => 'secret',
            'cf-turnstile-response' => 'good-token',
            '_website' => '',
        ];

        $mailSender = new FakeMailSender();
        $repository = new SqliteSubmissionRepository(':memory:');

        $handler = $this->makeHandler(
            ['contact' => $this->contactForm()],
            $mailSender,
            new FakeTurnstileVerifier(true),
            $repository
        );

        $result = $handler->handle('contact');

        $this->assertSame(200, $result['status']);
        $this->assertSame([
            'name' => 'Ada',
            'email' => 'ada@example.com',
            'project_type' => 'Migration',
            'preferred_date' => '2026-08-01',
        ], $mailSender->sentMessages[0]['fields']);

        $row = $repository->find(1);
        $payload = json_decode((string) $row['payload'], true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame($mailSender->sentMessages[0]['fields'], $payload);
    }

    public function testQueuedMailModeStoresPendingSubmissionWithoutSendingImmediately(): void
    {
        $_POST = [
            'name' => 'Ada',
            'email' => 'ada@example.com',
            'message' => 'Hello',
            'cf-turnstile-response' => 'good-token',
        ];

        $mailSender = new FakeMailSender();
        $repository = new SqliteSubmissionRepository(':memory:');

        $handler = $this->makeHandler(
            ['contact' => $this->contactForm()],
            $mailSender,
            new FakeTurnstileVerifier(true),
            $repository,
            deferMail: true
        );

        $result = $handler->handle('contact');

        $this->assertSame(200, $result['status']);
        $this->assertSame('Submission accepted.', $result['body']['message']);
        $this->assertSame([], $mailSender->sentMessages);
        $this->assertSame('pending_mail', $repository->find(1)['status']);
    }

    public function testMailFailureMarksSubmissionFailedAndThrows(): void
    {
        $_POST = ['name' => 'Ada', 'email' => 'ada@example.com', 'message' => 'Hello'];

        $mailSender = new FakeMailSender();
        $mailSender->shouldThrow = true;

        $repository = new SqliteSubmissionRepository(':memory:');

        $handler = $this->makeHandler(
            ['contact' => $this->contactForm()],
            $mailSender,
            new FakeTurnstileVerifier(true),
            $repository
        );

        $this->expectException(RuntimeException::class);

        try {
            $handler->handle('contact');
        } finally {
            $row = $repository->find(1);
            $this->assertSame('failed', $row['status']);
            $this->assertSame('Simulated SMTP failure.', $row['error_message']);
        }
    }

    public function testNoGeneratedApiKeyAllowsSubmission(): void
    {
        $_POST = [
            'name' => 'Ada',
            'email' => 'ada@example.com',
            'message' => 'Hello',
            'cf-turnstile-response' => 'good-token',
        ];

        $handler = $this->makeHandler(['contact' => $this->contactForm()]);

        $result = $handler->handle('contact');

        $this->assertSame(200, $result['status']);
    }

    public function testRequiredApiKeyFailsWhenNoKeyHasBeenGenerated(): void
    {
        $_POST = [
            'name' => 'Ada',
            'email' => 'ada@example.com',
            'message' => 'Hello',
            'cf-turnstile-response' => 'good-token',
        ];

        $handler = $this->makeHandler([
            'contact' => $this->contactForm(['require_api_key' => true]),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('API key is required.');

        $handler->handle('contact');
    }

    public function testApiKeyMismatchThrowsInvalidArgumentException(): void
    {
        $_POST = [
            'name' => 'Ada',
            'email' => 'ada@example.com',
            'message' => 'Hello',
            '_key' => 'wrong-key',
        ];

        $apiKeys = new SqliteFormApiKeyRepository(':memory:');
        $apiKeys->regenerate('contact');

        $handler = $this->makeHandler(
            ['contact' => $this->contactForm()],
            null,
            null,
            null,
            null,
            $apiKeys
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid API key.');

        $handler->handle('contact');
    }

    public function testApiKeyMatchAllowsSubmission(): void
    {
        $apiKeys = new SqliteFormApiKeyRepository(':memory:');
        $correctKey = $apiKeys->regenerate('contact');

        $_POST = [
            'name' => 'Ada',
            'email' => 'ada@example.com',
            'message' => 'Hello',
            '_key' => $correctKey,
            'cf-turnstile-response' => 'good-token',
        ];

        $handler = $this->makeHandler(
            ['contact' => $this->contactForm()],
            null,
            null,
            null,
            null,
            $apiKeys
        );

        $result = $handler->handle('contact');

        $this->assertSame(200, $result['status']);
    }

    public function testSpamFilterMatchReturnsFakeSuccessAndLogsBlockedSpam(): void
    {
        $_POST = [
            'name' => 'Ada',
            'email' => 'ada@example.com',
            'message' => 'Buy cheap viagra now',
        ];

        $mailSender = new FakeMailSender();
        $repository = new SqliteSubmissionRepository(':memory:');

        $handler = $this->makeHandler(
            ['contact' => $this->contactForm(['blocked_patterns' => ['viagra']])],
            $mailSender,
            null,
            $repository
        );

        $result = $handler->handle('contact');

        $this->assertSame(200, $result['status']);
        $this->assertSame([], $mailSender->sentMessages);

        $row = $repository->find(1);
        $this->assertSame('blocked_spam', $row['status']);
    }

    public function testRateLimitPerIpExceededReturns429(): void
    {
        $_POST = [
            'name' => 'Ada',
            'email' => 'ada@example.com',
            'message' => 'Hello',
            'cf-turnstile-response' => 'good-token',
        ];

        $rateLimiter = new SqliteRateLimiter(':memory:');
        $form = $this->contactForm([
            'rate_limit_per_ip' => ['max' => 2, 'window_minutes' => 10],
        ]);

        $handler = $this->makeHandler(
            ['contact' => $form],
            null,
            new FakeTurnstileVerifier(true),
            new SqliteSubmissionRepository(':memory:'),
            $rateLimiter
        );

        $handler->handle('contact');
        $handler->handle('contact');
        $result = $handler->handle('contact');

        $this->assertSame(429, $result['status']);
    }

    public function testDailyLimitExceededReturns429(): void
    {
        $_POST = [
            'name' => 'Ada',
            'email' => 'ada@example.com',
            'message' => 'Hello',
            'cf-turnstile-response' => 'good-token',
        ];

        $rateLimiter = new SqliteRateLimiter(':memory:');
        $form = $this->contactForm([
            'rate_limit_per_ip' => ['max' => 1000, 'window_minutes' => 10],
            'daily_limit' => 1,
        ]);

        $handler = $this->makeHandler(
            ['contact' => $form],
            null,
            new FakeTurnstileVerifier(true),
            new SqliteSubmissionRepository(':memory:'),
            $rateLimiter
        );

        $handler->handle('contact');

        $_SERVER['REMOTE_ADDR'] = '198.51.100.99';
        $result = $handler->handle('contact');

        $this->assertSame(429, $result['status']);
    }
}
