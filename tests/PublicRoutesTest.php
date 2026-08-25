<?php

declare(strict_types=1);

namespace formflow\Tests;

use PHPUnit\Framework\TestCase;

final class PublicRoutesTest extends TestCase
{
    public function testHealthRouteReturnsMachineReadableLivenessPayload(): void
    {
        [$exitCode, $output, $error] = $this->request(
            '/health',
            ['HTTP_ACCEPT' => 'application/json'],
            ['format' => 'json']
        );

        $this->assertSame(0, $exitCode, $error);
        $health = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('ok', $health['status']);
        $this->assertSame('formflow', $health['service']);
        $this->assertArrayHasKey('time', $health);
    }

    public function testHealthRouteRendersHtmlWhenTheClientDoesNotAskForJson(): void
    {
        [$exitCode, $output, $error] = $this->request('/health', ['HTTP_ACCEPT' => 'text/html']);

        $this->assertSame(0, $exitCode, $error);
        $this->assertStringContainsString('<title>formflow health</title>', $output);
        $this->assertStringContainsString('Health check', $output);
    }

    public function testPublicHomeDoesNotExposeAdminLinkToRemoteVisitors(): void
    {
        [$exitCode, $output, $error] = $this->request('/', ['REMOTE_ADDR' => '203.0.113.7']);

        $this->assertSame(0, $exitCode, $error);
        $this->assertStringContainsString('Self-hosted form backend', $output);
        $this->assertStringNotContainsString('<a href="/admin"', $output);
    }

    /**
     * @param array<string, string> $serverOverrides
     * @param array<string, string> $query
     * @return array{0: int, 1: string, 2: string}
     */
    private function request(string $path, array $serverOverrides = [], array $query = []): array
    {
        $server = array_merge([
            'REQUEST_URI' => $path,
            'REQUEST_METHOD' => 'GET',
            'REMOTE_ADDR' => '203.0.113.7',
        ], $serverOverrides);
        $script = <<<'PHP'
$_SERVER = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
$_GET = json_decode($argv[2], true, 512, JSON_THROW_ON_ERROR);
$_POST = [];
$_FILES = [];
require $argv[3];
PHP;
        $process = proc_open(
            [
                PHP_BINARY,
                '-r',
                $script,
                json_encode($server, JSON_THROW_ON_ERROR),
                json_encode($query, JSON_THROW_ON_ERROR),
                dirname(__DIR__) . '/public/index.php',
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );

        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $output, $error];
    }
}
