<?php

declare(strict_types=1);

namespace formflow\Tests;

use formflow\ClientIpResolver;
use PHPUnit\Framework\TestCase;

final class ClientIpResolverTest extends TestCase
{
    public function testUntrustedRemoteAddressIgnoresForwardedHeaders(): void
    {
        $resolver = new ClientIpResolver(['127.0.0.1']);

        $this->assertSame('203.0.113.10', $resolver->resolve([
            'REMOTE_ADDR' => '203.0.113.10',
            'HTTP_CF_CONNECTING_IP' => '198.51.100.20',
        ]));
    }

    public function testTrustedProxyCanUseCloudflareHeader(): void
    {
        $resolver = new ClientIpResolver(['127.0.0.1']);

        $this->assertSame('198.51.100.20', $resolver->resolve([
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_CF_CONNECTING_IP' => '198.51.100.20',
        ]));
    }

    public function testTrustedProxyCanUseFirstForwardedForAddress(): void
    {
        $resolver = new ClientIpResolver(['10.0.0.0/8']);

        $this->assertSame('198.51.100.20', $resolver->resolve([
            'REMOTE_ADDR' => '10.1.2.3',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.20, 10.1.2.3',
        ]));
    }

    public function testInvalidTrustedHeaderFallsBackToRemoteAddress(): void
    {
        $resolver = new ClientIpResolver(['127.0.0.1']);

        $this->assertSame('127.0.0.1', $resolver->resolve([
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_X_REAL_IP' => 'not-an-ip',
        ]));
    }
}
