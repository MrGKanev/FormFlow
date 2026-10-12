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

    public function testPublicIpv6LiteralDoesNotRequireDnsResolution(): void
    {
        $result = WebhookUrlPolicy::resolve('https://[2606:4700:4700::1111]/webhook', static function (string $host): array {
            self::fail('Literal IPv6 addresses must not be resolved through DNS.');
        });

        $this->assertNull($result['error']);
        $this->assertSame(['2606:4700:4700::1111'], $result['ips']);
    }

    public function testRejectsPrivateIpv6Literals(): void
    {
        foreach (['::1', 'fd00::1', 'fe80::1'] as $ip) {
            $this->assertSame('Webhook destination is not allowed.', WebhookUrlPolicy::validate('https://[' . $ip . ']/webhook'));
        }
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

    public function testRejectsNonPublicAndMulticastResolvedAddresses(): void
    {
        foreach (['100.64.0.1', '100.127.255.254', '224.0.0.1', 'ff02::1'] as $ip) {
            $this->assertSame(
                'Webhook destination is not allowed.',
                WebhookUrlPolicy::validate('https://hooks.example.test/webhook', static fn (string $host): array => [$ip]),
                $ip
            );
        }
    }

    public function testMixedPublicAndPrivateAnswersAreRejected(): void
    {
        $this->assertSame(
            'Webhook destination is not allowed.',
            WebhookUrlPolicy::validate('https://hooks.example.test/webhook', static fn (string $host): array => ['93.184.216.34', '10.0.0.1'])
        );
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
