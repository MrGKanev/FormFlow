<?php

declare(strict_types=1);

namespace formflow\Tests;

use formflow\HttpsDetector;
use PHPUnit\Framework\TestCase;

final class HttpsDetectorTest extends TestCase
{
    public function testDetectsDirectHttps(): void
    {
        $this->assertTrue((new HttpsDetector())->isHttps(['HTTPS' => 'on']));
    }

    public function testIgnoresForwardedProtoFromUntrustedAddress(): void
    {
        $detector = new HttpsDetector();

        $this->assertFalse($detector->isHttps([
            'REMOTE_ADDR' => '203.0.113.10',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ], ['10.0.0.0/8']));
    }

    public function testTrustsForwardedProtoFromTrustedProxy(): void
    {
        $detector = new HttpsDetector();

        $this->assertTrue($detector->isHttps([
            'REMOTE_ADDR' => '10.0.0.12',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ], ['10.0.0.0/8']));
    }
}
