<?php

declare(strict_types=1);

namespace formflow;

use RuntimeException;

/** Records worker execution separately from individual delivery outcomes. */
final class WorkerHeartbeat
{
    public function __construct(private readonly string $root)
    {
    }

    public function started(string $worker): void
    {
        $this->write($worker, [
            'status' => 'running', 'started_at' => Clock::nowIso(),
        ]);
    }

    /** @param array<string, int> $summary */
    public function completed(string $worker, array $summary): void
    {
        $now = Clock::nowIso();
        $changes = ['status' => 'completed', 'last_run' => $now, 'summary' => $summary];
        if (($summary['failed'] ?? 0) === 0) {
            $changes['last_success'] = $now;
        } else {
            $changes['status'] = 'failed';
        }
        $this->write($worker, $changes);
        // Keep the existing heartbeat readable by older deployments.
        if (file_put_contents($this->directory() . '/' . $worker . '-last-run.txt', $now . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('Unable to record worker completion.');
        }
    }

    public function failed(string $worker): void
    {
        $this->write($worker, ['status' => 'failed', 'last_run' => Clock::nowIso()]);
    }

    /** @return array<string, mixed> */
    public function read(string $worker): array
    {
        $path = $this->path($worker);
        if (!is_file($path)) {
            return [];
        }
        $state = json_decode((string) file_get_contents($path), true);
        return is_array($state) ? $state : [];
    }

    /** @param array<string, mixed> $changes */
    private function write(string $worker, array $changes): void
    {
        $directory = $this->directory();
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create the worker status directory.');
        }
        $path = $this->path($worker);
        $lock = fopen($path . '.lock', 'c');
        if ($lock === false) {
            throw new RuntimeException('Unable to open worker status lock.');
        }
        $temporary = false;
        try {
            if (!flock($lock, LOCK_EX)) {
                throw new RuntimeException('Unable to lock worker status.');
            }
            // Merge under the lock so overlapping workers retain each other's
            // last successful run instead of writing an earlier snapshot.
            $state = $this->read($worker) + ['last_success' => null];
            $temporary = tempnam($directory, $worker . '-');
            if ($temporary === false) {
                throw new RuntimeException('Unable to create worker status file.');
            }
            $content = json_encode(array_replace($state, $changes), JSON_THROW_ON_ERROR);
            if (file_put_contents($temporary, $content) !== strlen($content) || !rename($temporary, $path)) {
                throw new RuntimeException('Unable to save worker status.');
            }
        } finally {
            if ($temporary !== false && is_file($temporary)) {
                unlink($temporary);
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function directory(): string
    {
        return $this->root . '/storage/runtime';
    }

    private function path(string $worker): string
    {
        if (!in_array($worker, ['mail', 'webhooks'], true)) {
            throw new RuntimeException('Unknown worker.');
        }
        return $this->directory() . '/' . $worker . '-status.json';
    }
}
