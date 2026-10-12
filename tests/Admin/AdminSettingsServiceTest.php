<?php

declare(strict_types=1);

namespace formflow\Tests\Admin;

use formflow\Admin\AdminSettingsService;
use PHPUnit\Framework\TestCase;

final class AdminSettingsServiceTest extends TestCase
{
    public function testEnvUpdatePreservesSymlinkAndWritesPersistentTarget(): void
    {
        $directory = sys_get_temp_dir() . '/formflow-settings-link-' . bin2hex(random_bytes(6));
        mkdir($directory);
        $target = $directory . '/runtime.env';
        file_put_contents($target, "APP_URL='https://old.example.com'\n");
        symlink('runtime.env', $directory . '/.env');

        try {
            $service = new AdminSettingsService([], $directory . '/.env');
            $service->writeEnvFile(['APP_URL' => 'https://new.example.com']);

            $this->assertTrue(is_link($directory . '/.env'));
            $this->assertSame("APP_URL='https://new.example.com'\n", file_get_contents($target));
        } finally {
            unlink($directory . '/.env');
            unlink($target);
            rmdir($directory);
        }
    }
}
