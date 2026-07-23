<?php

declare(strict_types=1);

namespace formflow\Tests;

use formflow\SqliteRateLimiter;
use PHPUnit\Framework\TestCase;

final class SqliteRateLimiterTest extends TestCase
{
    public function testCountRecentHitsByIpCountsOnlyMatchingFormAndIp(): void
    {
        $limiter = new SqliteRateLimiter(':memory:');

        $limiter->hit('contact', 'ip-a');
        $limiter->hit('contact', 'ip-a');
        $limiter->hit('contact', 'ip-b');
        $limiter->hit('support', 'ip-a');

        $count = $limiter->countRecentHitsByIp('contact', 'ip-a', 10);

        $this->assertSame(2, $count);
    }

    public function testCountRecentHitsByIpTreatsNullIpHashSeparately(): void
    {
        $limiter = new SqliteRateLimiter(':memory:');

        $limiter->hit('contact', null);
        $limiter->hit('contact', 'ip-a');

        $count = $limiter->countRecentHitsByIp('contact', null, 10);

        $this->assertSame(1, $count);
    }

    public function testCountRecentHitsForFormCountsAcrossAllIps(): void
    {
        $limiter = new SqliteRateLimiter(':memory:');

        $limiter->hit('contact', 'ip-a');
        $limiter->hit('contact', 'ip-b');
        $limiter->hit('contact', 'ip-c');
        $limiter->hit('support', 'ip-a');

        $count = $limiter->countRecentHitsForForm('contact', 1440);

        $this->assertSame(3, $count);
    }

    public function testHitsForOneFormDoNotAffectAnotherForm(): void
    {
        $limiter = new SqliteRateLimiter(':memory:');

        $limiter->hit('contact', 'ip-a');

        $this->assertSame(0, $limiter->countRecentHitsByIp('support', 'ip-a', 10));
        $this->assertSame(0, $limiter->countRecentHitsForForm('support', 10));
    }
}
