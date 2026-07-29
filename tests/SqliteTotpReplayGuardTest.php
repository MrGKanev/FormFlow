<?php

declare(strict_types=1);

namespace formflow\Tests;

use formflow\SqliteTotpReplayGuard;
use PHPUnit\Framework\TestCase;

final class SqliteTotpReplayGuardTest extends TestCase
{
    public function testConsumeReturnsTrueOnFirstUseOfAStep(): void
    {
        $guard = new SqliteTotpReplayGuard(':memory:');

        $this->assertTrue($guard->consume('user:1', 12345));
    }

    public function testConsumeReturnsFalseWhenTheSameStepIsReplayed(): void
    {
        $guard = new SqliteTotpReplayGuard(':memory:');

        $guard->consume('user:1', 12345);

        $this->assertFalse($guard->consume('user:1', 12345));
    }

    public function testConsumeTreatsDifferentStepsForTheSameIdentityIndependently(): void
    {
        $guard = new SqliteTotpReplayGuard(':memory:');

        $this->assertTrue($guard->consume('user:1', 12345));
        $this->assertTrue($guard->consume('user:1', 12346));
    }

    public function testConsumeTreatsDifferentIdentitiesIndependently(): void
    {
        $guard = new SqliteTotpReplayGuard(':memory:');

        $this->assertTrue($guard->consume('user:1', 12345));
        $this->assertTrue($guard->consume('user:2', 12345));
    }

    public function testConsumeDistinguishesBootstrapAndUserIdentitiesWithTheSameStep(): void
    {
        $guard = new SqliteTotpReplayGuard(':memory:');

        $this->assertTrue($guard->consume('bootstrap:admin', 999));
        $this->assertTrue($guard->consume('user:1', 999));
        $this->assertFalse($guard->consume('bootstrap:admin', 999));
    }
}
