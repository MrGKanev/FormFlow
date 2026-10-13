<?php

declare(strict_types=1);

namespace formflow\Tests;

use formflow\SqliteRateLimiter;
use PHPUnit\Framework\TestCase;

final class AdminAuthConcurrencyTest extends TestCase
{
    public function testOverlappingLoginsAdmitExactlyTheConfiguredNumberOfAttempts(): void
    {
        $directory = sys_get_temp_dir() . '/formflow-login-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $database = $directory . '/rate.sqlite';
        $limiter = new SqliteRateLimiter($database);
        $hash = password_hash('correct-password', PASSWORD_BCRYPT, ['cost' => 4]);
        $children = [];
        $code = <<<'CHILD'
require $argv[1];
$limiter = new class(new \formflow\SqliteRateLimiter($argv[2])) implements \formflow\RateLimiterInterface {
    public function __construct(private \formflow\SqliteRateLimiter $inner) {}
    public function consume(string $form, ?string $ip, int $window, int $maximum, int $daily): ?string {
        return $this->inner->consume($form, $ip, $window, $maximum, $daily);
    }
    public function hit(string $form, ?string $ip): void {
        $this->inner->hit($form, $ip);
        // Force the old hit/count implementation to observe every concurrent hit.
        echo "recorded\n";
        fflush(STDOUT);
        if (trim((string) fgets(STDIN)) !== 'count') { exit(2); }
    }
    public function countRecentHitsByIp(string $form, ?string $ip, int $window): int {
        return $this->inner->countRecentHitsByIp($form, $ip, $window);
    }
    public function countRecentHitsForForm(string $form, int $window): int {
        return $this->inner->countRecentHitsForForm($form, $window);
    }
};
$auth = new \formflow\AdminAuth('admin', $argv[3], $limiter, 3, 15);
echo "ready\n";
fflush(STDOUT);
if (trim((string) fgets(STDIN)) !== 'go') { exit(2); }
echo $auth->attemptLogin('admin', 'correct-password', 'shared-ip') . "\n";
CHILD;

        try {
            for ($i = 0; $i < 8; $i++) {
                $process = proc_open([PHP_BINARY, '-r', $code, dirname(__DIR__) . '/vendor/autoload.php', $database, $hash],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                self::assertIsResource($process);
                stream_set_timeout($pipes[1], 15);
                $children[] = [$process, $pipes];
            }
            foreach ($children as [, $pipes]) {
                self::assertSame("ready\n", fgets($pipes[1]));
            }
            foreach ($children as [, $pipes]) {
                fwrite($pipes[0], "go\n");
                fflush($pipes[0]);
            }
            $results = [];
            $waiting = [];
            foreach ($children as [, $pipes]) {
                $line = fgets($pipes[1]);
                self::assertNotFalse($line, 'Child timed out or exited before reporting.');
                if ($line === "recorded\n") {
                    $waiting[] = $pipes;
                } else {
                    $results[] = trim($line);
                }
            }
            foreach ($waiting as $pipes) {
                fwrite($pipes[0], "count\n");
                fflush($pipes[0]);
            }
            foreach ($waiting as $pipes) {
                $line = fgets($pipes[1]);
                self::assertNotFalse($line);
                $results[] = trim($line);
            }
            self::assertSame(8, $limiter->countRecentHitsByIp('admin_login', 'shared-ip', 15));
            self::assertCount(3, array_filter($results, static fn ($result) => $result === 'ok'));
            self::assertCount(5, array_filter($results, static fn ($result) => $result === 'locked'));
        } finally {
            foreach ($children as [$process, $pipes]) {
                if (proc_get_status($process)['running']) {
                    proc_terminate($process, 9);
                }
                foreach ($pipes as $pipe) { fclose($pipe); }
                proc_close($process);
            }
            foreach (glob($directory . '/*') ?: [] as $path) { unlink($path); }
            rmdir($directory);
        }
    }
}
