<?php

declare(strict_types=1);
namespace formflow\Tests;

use formflow\DeliveryMonitor;
use formflow\DeliveryStatusController;
use formflow\SqliteSubmissionRepository;
use PHPUnit\Framework\TestCase;

final class DeliveryStatusControllerTest extends TestCase
{
    use MonitoringFixture;
    public function testTokenIsRequiredAndDisabledEndpointCannotAuthenticate(): void
    {
        $monitor = new DeliveryMonitor(new SqliteSubmissionRepository(':memory:'), null, $this->root);
        $controller = new DeliveryStatusController($monitor, 'secret');
        foreach (['', 'secret', 'Bearer wrong', 'Bearer secret '] as $header) {
            self::assertSame(401, $controller->handle('GET', $header)->status);
        }
        self::assertSame(401, (new DeliveryStatusController($monitor, ''))->handle('GET', 'Bearer ')->status);
        self::assertSame(200, $controller->handle('GET', 'Bearer secret')->status);
        $response = $controller->handle('POST', 'Bearer secret');
        self::assertSame(405, $response->status);
        self::assertSame('GET', $response->headers['Allow']);
    }
    public function testUnhealthyApiReturns503WithoutSensitivePayloadOrError(): void
    {
        $repository = new SqliteSubmissionRepository(':memory:');
        $id = $repository->create('contact', ['email' => 'private@example.com', 'secret' => 'private-payload'], 'private-ip', 'failed');
        $repository->markFailed($id, 'private-smtp-password');
        $response = (new DeliveryStatusController(new DeliveryMonitor($repository, null, $this->root), 'secret'))->handle('GET', 'Bearer secret');
        self::assertSame(503, $response->status);
        self::assertSame('no-store', $response->headers['Cache-Control']);
        $body = json_encode($response->body, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('private', $body);
        self::assertSame(1, $response->body['mail']['failed']);
    }
}
