<?php

declare(strict_types=1);

namespace formflow\Tests;

use formflow\WebhookUrlPolicy;
use PHPUnit\Framework\TestCase;

final class WebhookUrlPolicyTest extends TestCase
{
    public function testAllowsPublicHttpDestinations(): void
    {
        $error = WebhookUrlPolicy::validate(
            'https://hooks.example.com/formflow',
            static fn (string $host): array => ['93.184.216.34']
        );

        $this->assertNull($error);
    }

    public function testRejectsLocalhostDestination(): void
    {
        $error = WebhookUrlPolicy::validate(
            'https://localhost/webhook',
            static fn (string $host): array => ['127.0.0.1']
        );

        $this->assertSame('Webhook destination is not allowed.', $error);
    }

    public function testRejectsHostResolvingToPrivateAddress(): void
    {
        $error = WebhookUrlPolicy::validate(
            'https://hooks.example.test/webhook',
            static fn (string $host): array => ['10.0.0.12']
        );

        $this->assertSame('Webhook destination is not allowed.', $error);
    }

    public function testRejectsCredentialsInUrl(): void
    {
        $error = WebhookUrlPolicy::validate(
            'https://user:pass@hooks.example.com/webhook',
            static fn (string $host): array => ['93.184.216.34']
        );

        $this->assertSame('Webhook URL must not include credentials.', $error);
    }
}
