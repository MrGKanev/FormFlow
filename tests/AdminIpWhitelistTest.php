<?php

declare(strict_types=1);

namespace formflow\Tests;

use formflow\AdminIpWhitelist;
use formflow\SqliteAdminWhitelistRepository;
use PHPUnit\Framework\TestCase;

final class AdminIpWhitelistTest extends TestCase
{
    public function testConfiguredIpIsAllowed(): void
    {
        $whitelist = new AdminIpWhitelist(
            ['203.0.113.10'],
            new SqliteAdminWhitelistRepository(':memory:')
        );

        $this->assertTrue($whitelist->isAllowed('203.0.113.10'));
    }

    public function testDynamicIpIsAllowed(): void
    {
        $repository = new SqliteAdminWhitelistRepository(':memory:');
        $repository->add('203.0.113.20', null);

        $whitelist = new AdminIpWhitelist([], $repository);

        $this->assertTrue($whitelist->isAllowed('203.0.113.20'));
    }

    public function testUnionOfConfigAndDynamicIsAllowed(): void
    {
        $repository = new SqliteAdminWhitelistRepository(':memory:');
        $repository->add('203.0.113.20', null);

        $whitelist = new AdminIpWhitelist(['203.0.113.10'], $repository);

        $this->assertTrue($whitelist->isAllowed('203.0.113.10'));
        $this->assertTrue($whitelist->isAllowed('203.0.113.20'));
    }

    public function testUnlistedIpIsNotAllowed(): void
    {
        $repository = new SqliteAdminWhitelistRepository(':memory:');
        $repository->add('203.0.113.20', null);

        $whitelist = new AdminIpWhitelist(['203.0.113.10'], $repository);

        $this->assertFalse($whitelist->isAllowed('198.51.100.99'));
    }

    public function testCidrEntryFromDynamicRepositoryIsHonored(): void
    {
        $repository = new SqliteAdminWhitelistRepository(':memory:');
        $repository->add('198.51.100.0/24', null);

        $whitelist = new AdminIpWhitelist([], $repository);

        $this->assertTrue($whitelist->isAllowed('198.51.100.42'));
    }
}
