<?php

declare(strict_types=1);

namespace formflow\Tests;

use PHPUnit\Framework\TestCase;
use formflow\SqliteSubmissionRepository;

final class PublicRoutesTest extends TestCase
{
    use MonitoringFixture;
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
        [$exitCode, $output, $error] = $this->installedRequest('/', ['REMOTE_ADDR' => '203.0.113.7']);

        $this->assertSame(0, $exitCode, $error);
        $this->assertStringContainsString('Self-hosted form backend', $output);
        $this->assertStringNotContainsString('<a href="/admin"', $output);
    }

    public function testAnalyticsRouteRejectsRequestsWithoutCreatingMissingDatabase(): void
    {
        file_put_contents($this->root . '/.env', "APP_ENV='test'\n");
        foreach ([
            ['', '', 'GET', [], 401],
            ['secret', 'Bearer wrong', 'GET', [], 401],
            ['secret', 'Bearer secret', 'POST', [], 405],
            ['secret', 'Bearer secret', 'GET', ['days' => 'invalid'], 422],
            ['secret', 'Bearer secret', 'GET', [], 503],
        ] as [$token, $header, $method, $query, $expected]) {
            [$exit, $output, $error] = $this->request('/api/analytics', ['HTTP_AUTHORIZATION' => $header, 'REQUEST_METHOD' => $method], $query, $this->root,
                ['ANALYTICS_API_TOKEN' => $token, 'DATABASE_PATH' => 'storage/submissions.sqlite'], true);
            self::assertSame(0, $exit, $error);
            $response = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
            self::assertSame($expected, $response['status']);
            self::assertArrayHasKey('error', $response['body']);
            self::assertFileDoesNotExist($this->root . '/storage/submissions.sqlite');
        }
    }

    public function testAnalyticsRouteReadsExistingDatabaseAfterAuthorization(): void
    {
        file_put_contents($this->root . '/.env', "APP_ENV='test'\n");
        $repository = new SqliteSubmissionRepository($this->root . '/storage/submissions.sqlite');
        $repository->create('contact', ['message' => 'private text'], null, 'sent');
        [$exit, $output, $error] = $this->request('/api/analytics', ['REDIRECT_HTTP_AUTHORIZATION' => 'Bearer secret'], [], $this->root,
            ['ANALYTICS_API_TOKEN' => 'secret', 'DATABASE_PATH' => 'storage/submissions.sqlite'], true);
        self::assertSame(0, $exit, $error);
        $response = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(200, $response['status']);
        self::assertSame(1, $response['body']['analytics']['summary']['total']);
        self::assertStringNotContainsString('private text', $output);
    }

    public function testDeliveryStatusRouteAuthenticatesBeforeOpeningDatabase(): void
    {
        file_put_contents($this->root . '/.env', "APP_ENV='test'\n");
        foreach ([['', 'GET', 401], ['Bearer secret', 'POST', 405], ['Bearer secret', 'GET', 503]] as [$header, $method, $expected]) {
            [$exit, $output, $error] = $this->request('/api/delivery-status', ['HTTP_AUTHORIZATION' => $header, 'REQUEST_METHOD' => $method], [], $this->root,
                ['OPERATIONS_API_TOKEN' => 'secret', 'DATABASE_PATH' => 'storage/submissions.sqlite'], true);
            self::assertSame(0, $exit, $error);
            self::assertSame($expected, json_decode($output, true, 512, JSON_THROW_ON_ERROR)['status']);
            self::assertFileDoesNotExist($this->root . '/storage/submissions.sqlite');
        }
    }

    public function testDeliveryStatusAcceptsAuthorizationPreservedByApacheInternalRedirect(): void
    {
        file_put_contents($this->root . '/.env', "APP_ENV='test'\n");
        new SqliteSubmissionRepository($this->root . '/storage/submissions.sqlite');
        [$exit, $output, $error] = $this->request('/api/delivery-status', ['REDIRECT_HTTP_AUTHORIZATION' => 'Bearer secret'], [], $this->root,
            ['OPERATIONS_API_TOKEN' => 'secret', 'DATABASE_PATH' => 'storage/submissions.sqlite'], true);
        self::assertSame(0, $exit, $error);
        $response = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(200, $response['status']);
        self::assertTrue($response['body']['healthy']);
    }

    public function testDeliveryStatusRouteReportsHealthyAndPendingQueues(): void
    {
        file_put_contents($this->root . '/.env', "APP_ENV='test'\n");
        $repository = new SqliteSubmissionRepository($this->root . '/storage/submissions.sqlite');
        foreach ([200, 503] as $expected) {
            [$exit, $output, $error] = $this->request('/api/delivery-status', ['HTTP_AUTHORIZATION' => 'Bearer secret'], [], $this->root,
                ['OPERATIONS_API_TOKEN' => 'secret', 'DATABASE_PATH' => 'storage/submissions.sqlite'], true);
            self::assertSame(0, $exit, $error);
            $response = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
            self::assertSame($expected, $response['status']);
            self::assertSame($expected === 200, $response['body']['healthy']);
            $repository->create('contact', ['email' => 'private@example.com'], null, 'pending_mail');
            self::assertStringNotContainsString('private', $output);
        }
    }

    /**
     * @param array<string, string> $serverOverrides
     * @param array<string, string> $query
     * @param array<string, string> $environmentOverrides
     * @return array{0: int, 1: string, 2: string}
     */
    private function request(
        string $path,
        array $serverOverrides = [],
        array $query = [],
        ?string $routerRoot = null,
        array $environmentOverrides = [],
        bool $captureStatus = false
    ): array
    {
        $server = array_merge([
            'REQUEST_URI' => $path,
            'REQUEST_METHOD' => 'GET',
            'REMOTE_ADDR' => '203.0.113.7',
        ], $serverOverrides);
        $script = <<<'PHP'
foreach (json_decode($argv[5], true, 512, JSON_THROW_ON_ERROR) as $key => $value) { putenv($key . '=' . $value); }
if ($argv[6] === 'capture') { ob_start(); }
$_SERVER = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
$_GET = json_decode($argv[2], true, 512, JSON_THROW_ON_ERROR);
$_POST = [];
$_FILES = [];
require $argv[3];

if ($argv[4] !== '') {
    $root = $argv[4];
    (new \formflow\AppRouter(new \formflow\AppFactory($root), [], $root))->dispatch();
}
if ($argv[6] === 'capture') {
    $body = ob_get_clean();
    echo json_encode(['status' => http_response_code(), 'body' => json_decode($body, true)], JSON_THROW_ON_ERROR);
}
PHP;
        $command = [
            PHP_BINARY,
            '-r',
            $script,
            json_encode($server, JSON_THROW_ON_ERROR),
            json_encode($query, JSON_THROW_ON_ERROR),
            $routerRoot === null
                ? dirname(__DIR__) . '/public/index.php'
                : dirname(__DIR__) . '/vendor/autoload.php',
        ];

        $command[] = $routerRoot ?? '';
        $command[] = json_encode($environmentOverrides, JSON_THROW_ON_ERROR);
        $command[] = $captureStatus ? 'capture' : '';

        $process = proc_open(
            $command,
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

    /**
     * @param array<string, string> $serverOverrides
     * @param array<string, string> $query
     * @return array{0: int, 1: string, 2: string}
     */
    private function installedRequest(string $path, array $serverOverrides = [], array $query = []): array
    {
        $root = sys_get_temp_dir() . '/formflow-public-routes-' . bin2hex(random_bytes(6));
        mkdir($root);
        file_put_contents($root . '/.env', "APP_ENV='test'\n");

        try {
            return $this->request($path, $serverOverrides, $query, $root);
        } finally {
            unlink($root . '/.env');
            rmdir($root);
        }
    }
}
