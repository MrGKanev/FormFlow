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

    /** Return a standalone SQLite snapshot, including committed WAL transactions. */
    public function snapshotDatabase(string $path): string
    {
        $allowedPath = $this->databasePath($path);
        if ($allowedPath === null || !is_file($allowedPath) || !$this->isSqliteDatabase($allowedPath)) {
            throw new \RuntimeException('Backup database is unavailable or not allowed.');
        }
        $snapshot = tempnam(sys_get_temp_dir(), 'formflow-admin-backup-');
        if ($snapshot === false) {
            throw new \RuntimeException('Cannot create backup snapshot.');
        }
        unlink($snapshot);
        try {
            $pdo = new \PDO('sqlite:' . $allowedPath, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            $pdo->exec('PRAGMA busy_timeout = 5000');
            $previousMask = umask(0077);
            try {
                $pdo->exec('VACUUM INTO ' . $pdo->quote($snapshot));
            } finally {
                umask($previousMask);
            }
            $content = file_get_contents($snapshot);
            if ($content === false) {
                throw new \RuntimeException('Cannot read backup snapshot.');
            }
            return $content;
        } finally {
            if (is_file($snapshot)) {
                unlink($snapshot);
            }
        }
    }

    private function pathIsWithin(string $path, string $root): bool
    {
        $path = rtrim($path, DIRECTORY_SEPARATOR);
        $root = rtrim($root, DIRECTORY_SEPARATOR);

        return $path === $root || str_starts_with($path, $root . DIRECTORY_SEPARATOR);
    }
}
