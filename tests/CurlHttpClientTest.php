<?php

declare(strict_types=1);

namespace formflow {

/**
 * Namespaced cURL doubles let the low-level transport be tested without making
 * real network calls. Unqualified cURL calls in CurlHttpClient resolve here
 * before falling back to the global extension functions.
 */
final class CurlSpy
{
    public static string $url = '';

    /** @var array<int, mixed> */
    public static array $options = [];

    public static string|false $response = '';
    public static string $error = '';
    public static int $statusCode = 0;
    public static bool $closed = false;
    public static bool $initializationFails = false;

    public static function reset(): void
    {
        self::$url = '';
        self::$options = [];
        self::$response = '';
        self::$error = '';
        self::$statusCode = 0;
        self::$closed = false;
        self::$initializationFails = false;
    }
}

function curl_init(string $url): object|false
{
    CurlSpy::$url = $url;

    return CurlSpy::$initializationFails ? false : new \stdClass();
}

/** @param array<int, mixed> $options */
function curl_setopt_array(object $handle, array $options): bool
{
    CurlSpy::$options = $options;

    return true;
}

function curl_exec(object $handle): string|false
{
    return CurlSpy::$response;
}

function curl_error(object $handle): string
{
    return CurlSpy::$error;
}

function curl_getinfo(object $handle, int $option): int
{
    return CurlSpy::$statusCode;
}

function curl_close(object $handle): void
{
    CurlSpy::$closed = true;
}
}

namespace formflow\Tests {

use formflow\CurlHttpClient;
use formflow\CurlSpy;
use formflow\CurlWebhookTransport;
use PHPUnit\Framework\TestCase;

final class CurlHttpClientTest extends TestCase
{
    protected function setUp(): void
    {
        CurlSpy::reset();
    }

    public function testPostPassesTheRequestConfigurationAndReturnsResponseDetails(): void
    {
        CurlSpy::$response = '{"success":true}';
        CurlSpy::$statusCode = 201;

        $result = CurlHttpClient::post(
            'https://hooks.example.test/incoming',
            '{"name":"Ada"}',
            ['Content-Type: application/json', 'X-Test: yes'],
            7,
            3
        );

        $this->assertSame('https://hooks.example.test/incoming', CurlSpy::$url);
        $this->assertSame('{"name":"Ada"}', CurlSpy::$options[CURLOPT_POSTFIELDS]);
        $this->assertSame(['Content-Type: application/json', 'X-Test: yes'], CurlSpy::$options[CURLOPT_HTTPHEADER]);
        $this->assertTrue(CurlSpy::$options[CURLOPT_POST]);
        $this->assertTrue(CurlSpy::$options[CURLOPT_RETURNTRANSFER]);
        $this->assertSame(7, CurlSpy::$options[CURLOPT_TIMEOUT]);
        $this->assertSame(3, CurlSpy::$options[CURLOPT_CONNECTTIMEOUT]);
        $this->assertSame(['statusCode' => 201, 'body' => '{"success":true}', 'error' => ''], $result);
        $this->assertTrue(CurlSpy::$closed);
    }

    public function testPostPreservesTransportFailureInformation(): void
    {
        CurlSpy::$response = false;
        CurlSpy::$error = 'Connection refused';

        $result = CurlHttpClient::post('https://hooks.example.test/incoming', '{}', [], 3, 2);

        $this->assertSame(['statusCode' => 0, 'body' => false, 'error' => 'Connection refused'], $result);
        $this->assertTrue(CurlSpy::$closed);
    }

    public function testPostReturnsAUsefulErrorWhenCurlCannotInitialize(): void
    {
        CurlSpy::$initializationFails = true;

        $result = CurlHttpClient::post('https://hooks.example.test/incoming', '{}', [], 3, 2);

        $this->assertSame([
            'statusCode' => 0,
            'body' => false,
            'error' => 'Unable to initialize cURL.',
        ], $result);
        $this->assertFalse(CurlSpy::$closed);
    }

    public function testWebhookTransportSendsJsonAndAcceptsAny2xxResponse(): void
    {
        CurlSpy::$response = '';
        CurlSpy::$statusCode = 204;

        $error = (new CurlWebhookTransport(
            static fn (string $host): array => ['93.184.216.34']
        ))->postJson(
            'https://hooks.example.test/incoming',
            ['form_id' => 'contact', 'fields' => ['name' => 'Ada']]
        );

        $this->assertNull($error);
        $this->assertSame('https://hooks.example.test/incoming', CurlSpy::$url);
        $this->assertSame(
            ['Content-Type: application/json', 'Accept: application/json, text/plain, */*'],
            CurlSpy::$options[CURLOPT_HTTPHEADER]
        );
        $this->assertSame(
            ['form_id' => 'contact', 'fields' => ['name' => 'Ada']],
            json_decode((string) CurlSpy::$options[CURLOPT_POSTFIELDS], true, 512, JSON_THROW_ON_ERROR)
        );
    }

    public function testWebhookTransportReturnsUsefulErrorsForTransportAndHttpFailures(): void
    {
        CurlSpy::$response = false;
        CurlSpy::$error = 'Connection refused';

        $transport = new CurlWebhookTransport(
            static fn (string $host): array => ['93.184.216.34']
        );
        $this->assertSame('Connection refused', $transport->postJson('https://hooks.example.test/incoming', []));

        CurlSpy::$response = 'temporarily unavailable';
        CurlSpy::$statusCode = 503;
        $this->assertSame('Webhook returned HTTP 503.', $transport->postJson('https://hooks.example.test/incoming', []));
    }
}
}
