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

    public function testIpv6ExactMatch(): void
    {
        $matcher = new IpMatcher();

        $this->assertTrue($matcher->matches('2001:db8::1', ['2001:db8::1']));
        $this->assertFalse($matcher->matches('2001:db8::2', ['2001:db8::1']));
    }

    public function testIpv6CidrRangeMatchesIpInside(): void
    {
        $matcher = new IpMatcher();

        $this->assertTrue($matcher->matches('2001:db8::42', ['2001:db8::/32']));
        $this->assertFalse($matcher->matches('2001:db9::42', ['2001:db8::/32']));
    }

    public function testIpv6NonOctetAlignedCidrPrefixMatchesCorrectly(): void
    {
        $matcher = new IpMatcher();

        $this->assertTrue($matcher->matches('2001:db8::5', ['2001:db8::/125']));
        $this->assertFalse($matcher->matches('2001:db8::9', ['2001:db8::/125']));
    }

    public function testMixedAddressFamilyCidrNeverMatches(): void
    {
        $matcher = new IpMatcher();

        $this->assertFalse($matcher->matches('2001:db8::1', ['198.51.100.0/24']));
        $this->assertFalse($matcher->matches('198.51.100.1', ['2001:db8::/32']));
    }
}
