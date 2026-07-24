<?php

declare(strict_types=1);

namespace formflow\Tests;

use formflow\IpMatcher;
use PHPUnit\Framework\TestCase;

final class IpMatcherTest extends TestCase
{
    public function testExactIpMatch(): void
    {
        $matcher = new IpMatcher();

        $this->assertTrue($matcher->matches('203.0.113.5', ['203.0.113.5']));
    }

    public function testUnlistedIpDoesNotMatch(): void
    {
        $matcher = new IpMatcher();

        $this->assertFalse($matcher->matches('198.51.100.9', ['203.0.113.5']));
    }

    public function testCidrRangeMatchesIpInside(): void
    {
        $matcher = new IpMatcher();

        $this->assertTrue($matcher->matches('198.51.100.42', ['198.51.100.0/24']));
    }

    public function testCidrRangeDoesNotMatchIpOutside(): void
    {
        $matcher = new IpMatcher();

        $this->assertFalse($matcher->matches('198.51.101.42', ['198.51.100.0/24']));
    }

    public function testEmptyEntryListNeverMatches(): void
    {
        $matcher = new IpMatcher();

        $this->assertFalse($matcher->matches('203.0.113.5', []));
    }

    public function testMalformedCidrPrefixDoesNotMatchEverything(): void
    {
        $matcher = new IpMatcher();

        $this->assertFalse($matcher->matches('203.0.113.5', ['198.51.100.0/oops']));
    }

    public function testNonOctetAlignedCidrPrefixMatchesCorrectly(): void
    {
        $matcher = new IpMatcher();

        $this->assertTrue($matcher->matches('198.51.100.5', ['198.51.100.0/28']));
        $this->assertFalse($matcher->matches('198.51.100.20', ['198.51.100.0/28']));
    }
}
