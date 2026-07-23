<?php

declare(strict_types=1);

namespace formflow\Tests;

use formflow\IpBlocklist;
use PHPUnit\Framework\TestCase;

final class IpBlocklistTest extends TestCase
{
    public function testExactIpMatchIsBlocked(): void
    {
        $blocklist = new IpBlocklist(['203.0.113.5']);

        $this->assertTrue($blocklist->isBlocked('203.0.113.5'));
    }

    public function testUnlistedIpIsNotBlocked(): void
    {
        $blocklist = new IpBlocklist(['203.0.113.5']);

        $this->assertFalse($blocklist->isBlocked('198.51.100.9'));
    }

    public function testCidrRangeBlocksMatchingIp(): void
    {
        $blocklist = new IpBlocklist(['198.51.100.0/24']);

        $this->assertTrue($blocklist->isBlocked('198.51.100.42'));
    }

    public function testCidrRangeDoesNotBlockOutsideIp(): void
    {
        $blocklist = new IpBlocklist(['198.51.100.0/24']);

        $this->assertFalse($blocklist->isBlocked('198.51.101.42'));
    }

    public function testEmptyBlocklistNeverBlocks(): void
    {
        $blocklist = new IpBlocklist([]);

        $this->assertFalse($blocklist->isBlocked('203.0.113.5'));
    }

    public function testMalformedCidrPrefixDoesNotBlockEverything(): void
    {
        $blocklist = new IpBlocklist(['198.51.100.0/oops']);

        $this->assertFalse($blocklist->isBlocked('203.0.113.5'));
    }

    public function testNonOctetAlignedCidrPrefixMatchesCorrectly(): void
    {
        $blocklist = new IpBlocklist(['198.51.100.0/28']);

        $this->assertTrue($blocklist->isBlocked('198.51.100.5'));
        $this->assertFalse($blocklist->isBlocked('198.51.100.20'));
    }
}
