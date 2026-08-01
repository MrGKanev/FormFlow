<?php

declare(strict_types=1);

namespace formflow\Tests;

use formflow\FormConfigValidator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class FormConfigValidatorTest extends TestCase
{
    public function testNormalizesLegacyTurnstileConfig(): void
    {
        $config = FormConfigValidator::normalize('contact', [
            'recipient' => 'hello@example.com',
            'allowed_origins' => ['https://example.com'],
            'turnstile' => true,
        ]);

        $this->assertSame('turnstile', $config['captcha_provider']);
        $this->assertTrue($config['turnstile']);
        $this->assertSame(['max' => 5, 'window_minutes' => 10], $config['rate_limit_per_ip']);
        $this->assertSame([
            'max_file_size_mb' => 10,
            'max_files' => 3,
            'allowed_extensions' => [],
        ], $config['uploads']);
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
}
