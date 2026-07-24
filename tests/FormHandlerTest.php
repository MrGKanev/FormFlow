<?php

declare(strict_types=1);

namespace formflow\Tests;

use formflow\FormApiKeyRepositoryInterface;
use formflow\FormHandler;
use formflow\SqliteFormApiKeyRepository;
use formflow\SqliteRateLimiter;
use formflow\SqliteSubmissionRepository;
use formflow\Tests\Fakes\FakeMailSender;
use formflow\Tests\Fakes\FakeTurnstileVerifier;
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
        ?FormApiKeyRepositoryInterface $apiKeys = null
    ): FormHandler {
        return new FormHandler(
            $forms,
            $mailSender ?? new FakeMailSender(),
            $repository ?? new SqliteSubmissionRepository(':memory:'),
            $turnstile ?? new FakeTurnstileVerifier(true),
            $rateLimiter ?? new SqliteRateLimiter(':memory:'),
            'test-secret',
            $apiKeys ?? new SqliteFormApiKeyRepository(':memory:')
        );
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
