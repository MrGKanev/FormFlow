<?php

declare(strict_types=1);

namespace formflow\Tests;

use formflow\AnalyticsApiController;
use formflow\SqliteSubmissionRepository;
use PHPUnit\Framework\TestCase;

final class AnalyticsApiControllerTest extends TestCase
{
    public function testAccessIsDisabledWithoutTokenAndRequiresMatchingBearer(): void
    {
        $repository = new SqliteSubmissionRepository(':memory:');
        foreach ([['', 'Bearer '], ['secret', ''], ['secret', 'Bearer wrong']] as [$token, $header]) {
            $response = (new AnalyticsApiController($repository, $token))->handle('GET', $header, []);
            self::assertSame(401, $response->status);
            self::assertSame('no-store', $response->headers['Cache-Control']);
        }
    }

    public function testValidatesMethodAndFilters(): void
    {
        $controller = new AnalyticsApiController(new SqliteSubmissionRepository(':memory:'), 'secret');
        self::assertSame(405, $controller->handle('POST', 'Bearer secret', [])->status);
        foreach ([['days' => '7foo'], ['days' => []], ['form_id' => []]] as $query) {
            self::assertSame(422, $controller->handle('GET', 'Bearer secret', $query)->status);
        }
    }

    public function testCountsPeopleAndSubmissionsWithoutExposingEmails(): void
    {
        $repository = new SqliteSubmissionRepository(':memory:');
        $repository->create('newsletter', ['email' => ' ADA@example.com '], null, 'sent');
        $repository->create('newsletter', ['email' => 'ada@example.com'], null, 'failed');
        $repository->create('newsletter', ['email' => 'spam@example.com'], null, 'blocked_spam');
        $repository->create('newsletter', [], null);
        $repository->create('newsletter', ['email' => ['invalid']], null);
        $repository->create('contact', ['email' => 'other@example.com'], null);
        $controller = new AnalyticsApiController($repository, 'secret');
        $response = $controller->handle('GET', 'Bearer secret', ['days' => '7', 'form_id' => 'newsletter']);
        self::assertSame(200, $response->status);
        $analytics = $response->body['analytics'];
        self::assertSame(5, $analytics['summary']['total']);
        self::assertSame(4, $analytics['summary']['accepted']);
        self::assertSame(1, $analytics['summary']['unique_emails']);
        self::assertSame(1, $analytics['forms'][0]['sent']);
        self::assertSame(1, $analytics['forms'][0]['unique_emails']);
        self::assertStringNotContainsString('@example.com', json_encode($response->body));
        self::assertSame(2, $repository->analyticsOverview(7)['summary']['unique_emails']);
        self::assertSame(0, $repository->analyticsOverview(7, 'missing')['summary']['unique_emails']);
    }
}
