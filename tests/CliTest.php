<?php

declare(strict_types=1);

namespace formflow\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CliTest extends TestCase
{
    public static function invalidOptions(): iterable
    {
        foreach (['0', '-1', 'garbage', '1.5', '999999999999999999999999'] as $value) {
            yield 'retention ' . $value => ['retention:prune', 'days', $value];
            yield 'mail ' . $value => ['mail:work', 'limit', $value];
            yield 'mail retry ' . $value => ['mail:retry-failed', 'limit', $value];
            yield 'webhook ' . $value => ['webhooks:retry', 'limit', $value];
        }
    }

    #[DataProvider('invalidOptions')]
    public function testInvalidOptionFailsBeforeCreatingDatabase(string $command, string $option, string $value): void
    {
        $database = sys_get_temp_dir() . '/formflow-cli-' . bin2hex(random_bytes(8)) . '.sqlite';
        $environment = getenv();
        $environment['DATABASE_PATH'] = $database;
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__) . '/bin/formflow', $command, '--' . $option . '=' . $value],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__),
            $environment
        );
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        try {
            self::assertSame(2, $exit, $error);
            self::assertSame('', $output);
            self::assertStringContainsString('--' . $option . ' must be a positive integer.', $error);
            self::assertFileDoesNotExist($database);
        } finally {
            foreach ([$database, $database . '-wal', $database . '-shm'] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }
}
