<?php

declare(strict_types=1);
namespace formflow\Tests;

trait MonitoringFixture
{
    private string $root;
    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/formflow-monitor-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/storage/runtime', 0700, true);
    }
    protected function tearDown(): void
    {
        $entries = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($entries as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->root);
    }
    private function heartbeat(string $worker, array $state): void
    {
        file_put_contents($this->root . '/storage/runtime/' . $worker . '-status.json', json_encode($state, JSON_THROW_ON_ERROR));
    }
}
