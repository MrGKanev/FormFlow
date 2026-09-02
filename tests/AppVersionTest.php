<?php

declare(strict_types=1);

namespace formflow\Tests;

use formflow\AppVersion;
use PHPUnit\Framework\TestCase;

final class AppVersionTest extends TestCase
{
    public function testVersionComesFromComposerManifest(): void
    {
        $composer = json_decode(
            (string) file_get_contents(dirname(__DIR__) . '/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->assertSame($composer['version'], AppVersion::current());
    }
}
