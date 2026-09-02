<?php

declare(strict_types=1);

namespace formflow;

final class AppVersion
{
    public static function current(): string
    {
        static $version = null;

        if (is_string($version)) {
            return $version;
        }

        $composerJson = @file_get_contents(dirname(__DIR__) . '/composer.json');
        $composer = is_string($composerJson) ? json_decode($composerJson, true) : null;
        $configuredVersion = is_array($composer) ? trim((string) ($composer['version'] ?? '')) : '';
        $version = $configuredVersion !== '' ? $configuredVersion : 'dev';

        return $version;
    }
}
