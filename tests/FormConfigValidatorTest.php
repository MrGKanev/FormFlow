<?php

declare(strict_types=1);

namespace formflow\Tests;

use formflow\FormConfigValidator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class FormConfigValidatorTest extends TestCase
{
    public function testNormalizesCaptchaProviderConfig(): void
    {
        $config = FormConfigValidator::normalize('contact', [
            'recipient' => 'hello@example.com',
            'allowed_origins' => ['https://example.com'],
            'captcha_provider' => 'turnstile',
        ]);

        $this->assertSame('turnstile', $config['captcha_provider']);
        $this->assertSame(['max' => 5, 'window_minutes' => 10], $config['rate_limit_per_ip']);
        $this->assertSame([
            'enabled' => true,
            'max_file_size_mb' => 10,
            'max_files' => 3,
            'allowed_extensions' => [],
        ], $config['uploads']);
    }

    public function testNormalizesDeliveryChannels(): void
    {
        $config = FormConfigValidator::normalize('contact', [
            'recipient' => 'hello@example.com',
            'allowed_origins' => ['https://example.com'],
            'delivery_channels' => ['slack', 'generic'],
        ]);

        $this->assertSame(['slack', 'generic'], $config['delivery_channels']);
    }

    public function testNormalizesLegacyCaptchaAndNotificationConfig(): void
    {
        $config = FormConfigValidator::normalize('contact', [
            'recipient' => 'hello@example.com',
            'allowed_origins' => ['https://example.com'],
            'turnstile' => true,
            'notification_channels' => ['discord', 'generic'],
        ]);

        $this->assertSame('turnstile', $config['captcha_provider']);
        $this->assertSame(['discord', 'generic'], $config['delivery_channels']);
        $this->assertArrayNotHasKey('turnstile', $config);
        $this->assertArrayNotHasKey('notification_channels', $config);
    }

    public function testRejectsInvalidImportedFormConfig(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Recipient must be a valid email address.');

        FormConfigValidator::normalize('contact', [
            'recipient' => 'not-an-email',
            'allowed_origins' => ['https://example.com'],
        ]);
    }

    public function testNormalizesAutoReplyConfiguration(): void
    {
        $config = FormConfigValidator::normalize('contact', [
            'recipient' => 'hello@example.com',
            'allowed_origins' => ['https://example.com'],
            'auto_reply' => [
                'enabled' => true,
                'subject' => 'Thanks, {{name}}',
                'body' => 'We received your message.',
            ],
        ]);

        $this->assertSame([
            'enabled' => true,
            'subject' => 'Thanks, {{name}}',
            'body' => 'We received your message.',
        ], $config['auto_reply']);
    }

    public function testEnabledAutoReplyRequiresSubjectAndBody(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Auto-reply subject and message are required');

        FormConfigValidator::normalize('contact', [
            'recipient' => 'hello@example.com',
            'allowed_origins' => ['https://example.com'],
            'auto_reply' => ['enabled' => true],
        ]);
    }
}
