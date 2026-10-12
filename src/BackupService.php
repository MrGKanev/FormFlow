<?php

declare(strict_types=1);

namespace formflow;

use FilesystemIterator;
use PDO;
use PharData;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/** Portable backups containing credentials: keep archives private. */
final class BackupService
{
    /** @return array{files:int,bytes:int} */
    public function create(string $root, string $archivePath, string $databasePath = 'storage/submissions.sqlite'): array
    {
        $root = realpath($root) ?: throw new RuntimeException('Backup source does not exist.');
        if (file_exists($archivePath) || is_link($archivePath)) {
            throw new RuntimeException('Backup destination already exists.');
        }
        $database = realpath(str_starts_with($databasePath, '/') ? $databasePath : $root . '/' . $databasePath);
        if ($database === false || !is_file($database)) {
            throw new RuntimeException('Configured database must exist.');
        }
        $sourceHeader = fopen($database, 'rb');
        if ($sourceHeader === false) {
            throw new RuntimeException('Cannot read configured database.');
        }
        try {
            if (fread($sourceHeader, 16) !== "SQLite format 3\0") {
                throw new RuntimeException('Configured database is not SQLite.');
            }
        } finally {
            fclose($sourceHeader);
        }
        $databaseName = str_starts_with($database, $root . '/')
            ? substr($database, strlen($root) + 1)
            : 'storage/submissions.sqlite';
        $this->validatePath($databaseName);
        if (in_array($databaseName, ['.env', 'manifest.json'], true) || str_starts_with($databaseName, 'config/') || str_starts_with($databaseName, 'storage/uploads/')) {
            throw new RuntimeException('Database path overlaps reserved backup contents.');
        }
        $snapshot = tempnam(sys_get_temp_dir(), 'formflow-snapshot-');
        if ($snapshot === false) {
            throw new RuntimeException('Cannot create database snapshot.');
        }
        unlink($snapshot);
        $files = [];
        try {
            $sourceUri = 'sqlite:file:' . str_replace('%2F', '/', rawurlencode($database)) . '?mode=ro';
            $pdo = new PDO($sourceUri, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->exec('PRAGMA busy_timeout = 5000');
            $quotedSnapshot = $pdo->quote($snapshot);
            $previousMask = umask(0077);
            try {
                $pdo->exec('VACUUM INTO ' . $quotedSnapshot);
            } finally {
                umask($previousMask);
            }
            $files[$databaseName] = $snapshot;
            foreach (['config', 'storage/uploads'] as $directory) {
                if (is_link($root . '/' . $directory)) {
                    throw new RuntimeException('Directory symlinks are not supported in backups.');
                }
                if (!is_dir($root . '/' . $directory)) {
                    continue;
                }
                $resolvedDirectory = realpath($root . '/' . $directory);
                if ($resolvedDirectory === false || !str_starts_with($resolvedDirectory, $root . '/')) {
                    throw new RuntimeException('Backup directory points outside the project.');
                }
                $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS));
                foreach ($iterator as $entry) {
                    if (!$entry->isFile()) {
                        if ($entry->isLink()) {
                            throw new RuntimeException('Directory symlinks are not supported in backups.');
                        }
                        continue;
                    }
                    $name = substr($entry->getPathname(), strlen($root) + 1);
                    $this->validatePath($name);
                    $resolved = realpath($entry->getPathname());
                    if ($resolved === false || !str_starts_with($resolved, $root . '/')) {
                        throw new RuntimeException('Backup symlink points outside the project.');
                    }
                    if ($name === $databaseName) {
                        throw new RuntimeException('Database path overlaps configuration or uploads.');
                    }
                    $files[$name] = $resolved;
                }
            }
            if (is_file($root . '/.env')) {
                $resolved = realpath($root . '/.env');
                if ($resolved === false || !str_starts_with($resolved, $root . '/')) {
                    throw new RuntimeException('Environment file points outside the project.');
                }
                $files['.env'] = $resolved;
            }
            $previousMask = umask(0077);
            try {
                $archive = new PharData($archivePath);
                $archive->addFromString('manifest.json', '{}');
            } finally {
                umask($previousMask);
            }
            chmod($archivePath, 0600);
            $manifest = [];
            $bytes = 0;
            foreach ($files as $name => $path) {
                $archive->addFile($path, $name);
                $entryPath = $archive[$name]->getPathname();
                $size = filesize($entryPath);
                $hash = hash_file('sha256', $entryPath);
                if ($size === false || $hash === false) {
                    throw new RuntimeException('Cannot read archived file: ' . $name);
                }
                $manifest[$name] = ['size' => $size, 'sha256' => $hash];
                $bytes += $size;
            }
            $this->validateSnapshotUploads($snapshot, array_keys($manifest));
            $archive->addFromString('manifest.json', json_encode(['version' => 1, 'database' => $databaseName, 'files' => $manifest], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
            return ['files' => count($files), 'bytes' => $bytes];
        } catch (\Throwable $error) {
            if (is_file($archivePath)) {
                unlink($archivePath);
            }
            throw $error;
        } finally {
            if (is_file($snapshot)) {
                unlink($snapshot);
            }
        }
    }

    /** @return array{files:int,bytes:int} */
    public function restore(string $archivePath, string $targetRoot): array
    {
        if (is_link($targetRoot) || (file_exists($targetRoot) && !is_dir($targetRoot))) {
            throw new RuntimeException('Restore target must be an empty directory.');
        }
        if (is_dir($targetRoot) && (new FilesystemIterator($targetRoot))->valid()) {
            throw new RuntimeException('Restore target must be empty.');
        }
        $archive = new PharData($archivePath);
        if (!isset($archive['manifest.json'])) {
            throw new RuntimeException('Backup manifest missing.');
        }
        if ($archive['manifest.json']->getSize() > 16 * 1024 * 1024 || $archive['manifest.json']->isLink()) {
            throw new RuntimeException('Backup manifest is unsafe or too large.');
        }
        $manifest = json_decode($archive['manifest.json']->getContent(), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($manifest) || ($manifest['version'] ?? null) !== 1 || !is_array($manifest['files'] ?? null) || !is_string($manifest['database'] ?? null)) {
            throw new RuntimeException('Invalid backup manifest.');
        }
        $database = $manifest['database'];
        $this->validatePath($database);
        $entries = [];
        $bytes = 0;
        foreach ($manifest['files'] as $name => $metadata) {
            if (!is_string($name) || !is_array($metadata)) {
                throw new RuntimeException('Invalid backup file metadata.');
            }
            $this->validatePath($name);
            if ($name !== '.env' && $name !== $database && !str_starts_with($name, 'config/') && !str_starts_with($name, 'storage/uploads/')) {
                throw new RuntimeException('Unexpected backup file.');
            }
            if (!isset($archive[$name]) || !$archive[$name]->isFile() || $archive[$name]->isLink()) {
                throw new RuntimeException('Missing or unsafe backup entry.');
            }
            $entryPath = $archive[$name]->getPathname();
            $size = filesize($entryPath);
            $hash = hash_file('sha256', $entryPath);
            if ($size === false || $hash === false || ($metadata['size'] ?? null) !== $size || ($metadata['sha256'] ?? null) !== $hash) {
                throw new RuntimeException('Backup checksum mismatch: ' . $name);
            }
            $entries[$name] = $entryPath;
            $bytes += $size;
        }
        foreach (new RecursiveIteratorIterator($archive) as $entry) {
            $name = substr($entry->getPathname(), strlen('phar://' . $archive->getPath()) + 1);
            $this->validatePath($name);
            if ($entry->isLink() || ($name !== 'manifest.json' && !array_key_exists($name, $entries))) {
                throw new RuntimeException('Unlisted or unsafe archive entry.');
            }
        }
        if (!isset($entries[$database])) {
            throw new RuntimeException('Backup database missing or invalid.');
        }
        $headerStream = fopen($entries[$database], 'rb');
        if ($headerStream === false) {
            throw new RuntimeException('Cannot read backup database.');
        }
        try {
            if (fread($headerStream, 16) !== "SQLite format 3\0") {
                throw new RuntimeException('Backup database missing or invalid.');
            }
        } finally {
            fclose($headerStream);
        }
        if (!is_dir($targetRoot) && !mkdir($targetRoot, 0700, true)) {
            throw new RuntimeException('Cannot create restore directory.');
        }
        $targetRoot = realpath($targetRoot) ?: throw new RuntimeException('Cannot resolve restore directory.');
        try {
            foreach ($entries as $name => $entryPath) {
                $path = $targetRoot . '/' . $name;
                if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0700, true)) {
                    throw new RuntimeException('Cannot create restore subdirectory.');
                }
                $input = fopen($entryPath, 'rb');
                $previousMask = umask(0077);
                try {
                    $output = fopen($path, 'xb');
                } finally {
                    umask($previousMask);
                }
                if ($input === false || $output === false) {
                    if (is_resource($input)) {
                        fclose($input);
                    }
                    if (is_resource($output)) {
                        fclose($output);
                    }
                    throw new RuntimeException('Cannot restore file: ' . $name);
                }
                try {
                    if (stream_copy_to_stream($input, $output) !== $archive[$name]->getSize()) {
                        throw new RuntimeException('Cannot restore file: ' . $name);
                    }
                } finally {
                    fclose($input);
                    fclose($output);
                }
                chmod($path, 0600);
            }
            $this->rewriteDatabaseEnvironment($targetRoot, $database);
            $pdo = new PDO('sqlite:' . $targetRoot . '/' . $database);
            $check = $pdo->query('PRAGMA integrity_check');
            if ($check === false || $check->fetchColumn() !== 'ok') {
                throw new RuntimeException('Restored database failed integrity check.');
            }
        } catch (\Throwable $error) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($targetRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($iterator as $entry) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            throw $error;
        }
        return ['files' => count($entries), 'bytes' => $bytes];
    }

    /** @param list<string> $archivedNames */
    private function validateSnapshotUploads(string $snapshot, array $archivedNames): void
    {
        $pdo = new PDO('sqlite:' . $snapshot, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $columns = $pdo->query('PRAGMA table_info(submissions)');
        if ($columns === false || !in_array('payload', $columns->fetchAll(PDO::FETCH_COLUMN, 1), true)) {
            return;
        }
        $statement = $pdo->query('SELECT payload FROM submissions');
        if ($statement === false) {
            throw new RuntimeException('Cannot validate snapshot uploads.');
        }
        $names = array_fill_keys($archivedNames, true);
        while (($payloadJson = $statement->fetchColumn()) !== false) {
            if (!is_string($payloadJson)) {
                continue;
            }
            $payload = json_decode($payloadJson, true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($payload)) {
                continue;
            }
            foreach ($payload as $value) {
                if (!is_array($value) || ($value['type'] ?? null) !== 'upload') {
                    continue;
                }
                $name = $value['stored_name'] ?? $value['relative_path'] ?? null;
                if (!is_string($name) || $name === '' || basename($name) !== $name || !isset($names['storage/uploads/' . $name])) {
                    throw new RuntimeException('Snapshot references an upload missing from the backup.');
                }
            }
        }
    }

    /** Keep restored credentials while making the configured database portable. */
    private function rewriteDatabaseEnvironment(string $targetRoot, string $database): void
    {
        $environment = $targetRoot . '/.env';
        $replacement = 'DATABASE_PATH="' . str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $database) . '"';
        if (!is_file($environment)) {
            $previousMask = umask(0077);
            try {
                if (file_put_contents($environment, $replacement . "\n") === false) {
                    throw new RuntimeException('Cannot configure restored database.');
                }
            } finally {
                umask($previousMask);
            }
            chmod($environment, 0600);
            return;
        }
        // Dotenv files are configuration-sized; uploads/database are always streamed.
        if (filesize($environment) > 1024 * 1024) {
            throw new RuntimeException('Environment configuration exceeds 1 MiB.');
        }
        $content = file_get_contents($environment);
        if ($content === false) {
            throw new RuntimeException('Cannot read restored environment.');
        }
        $variables = (new \Symfony\Component\Dotenv\Dotenv())->parse($content);
        $updated = $content;
        if (($variables['DATABASE_PATH'] ?? 'storage/submissions.sqlite') !== $database) {
            // A final assignment safely supersedes even a multiline original value.
            $updated = rtrim($content, "\r\n") . "\n" . $replacement . "\n";
        }
        if (file_put_contents($environment, $updated) !== strlen($updated)) {
            throw new RuntimeException('Cannot configure restored database.');
        }
    }

    private function validatePath(string $path): void
    {
        if ($path === '' || str_contains($path, '\\') || preg_match('/[\x00-\x1f\x7f]/', $path) || str_starts_with($path, '/') || preg_match('~(^|/)(\.|\.\.)(/|$)|:|//~', $path)) {
            throw new RuntimeException('Unsafe backup path.');
        }
    }
}
