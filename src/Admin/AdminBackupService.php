<?php

declare(strict_types=1);

namespace formflow\Admin;

final class AdminBackupService
{
    public function __construct(private readonly string $root)
    {
    }

    public function databasePath(string $databasePath): ?string
    {
        $storageRoot = realpath($this->root . '/storage');

        if ($storageRoot === false) {
            return null;
        }

        $path = str_starts_with($databasePath, '/')
            ? $databasePath
            : $this->root . '/' . ltrim($databasePath, '/');
        $directory = realpath(dirname($path));

        if ($directory === false || !$this->pathIsWithin($directory, $storageRoot)) {
            return null;
        }

        $realPath = realpath($path);

        if ($realPath !== false && !$this->pathIsWithin($realPath, $storageRoot)) {
            return null;
        }

        return $realPath !== false ? $realPath : $path;
    }

    public function isSqliteDatabase(string $path): bool
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return false;
        }

        $header = fread($handle, 16);
        fclose($handle);

        return $header === "SQLite format 3\0";
    }

    private function pathIsWithin(string $path, string $root): bool
    {
        $path = rtrim($path, DIRECTORY_SEPARATOR);
        $root = rtrim($root, DIRECTORY_SEPARATOR);

        return $path === $root || str_starts_with($path, $root . DIRECTORY_SEPARATOR);
    }
}
