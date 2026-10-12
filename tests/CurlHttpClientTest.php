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
    public static ?string $retryAfter = null;
    /** @var list<string> */
    public static array $responseHeaders = [];

    public static function reset(): void
    {
        self::$retryAfter = null;
        self::$responseHeaders = [];
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
    foreach (CurlSpy::$responseHeaders as $header) {
        $callback = CurlSpy::$options[CURLOPT_HEADERFUNCTION];
        $callback($handle, $header);
    }
    if (CurlSpy::$retryAfter !== null) {
        $callback = CurlSpy::$options[CURLOPT_HEADERFUNCTION];
        $callback($handle, 'Retry-After: ' . CurlSpy::$retryAfter . "\r\n");
    }
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

    public function testPinsMixedIpv4AndIpv6AddressesWithExplicitPort(): void
    {
        CurlHttpClient::post('https://hooks.example.test:8443/incoming', '{}', [], 3, 2, [], [
            '93.184.216.34',
            '2606:4700:4700::1111',
        ]);

        $this->assertSame(
            ['hooks.example.test:8443:93.184.216.34,[2606:4700:4700::1111]'],
            CurlSpy::$options[CURLOPT_RESOLVE]
        );
    }

    public function testLiteralIpv6DestinationDoesNotNeedDnsOverride(): void
    {
        CurlHttpClient::post('https://[2606:4700:4700::1111]/incoming', '{}', [], 3, 2, [], ['2606:4700:4700::1111']);

        $this->assertSame('https://[2606:4700:4700::1111]/incoming', CurlSpy::$url);
        $this->assertArrayNotHasKey(CURLOPT_RESOLVE, CurlSpy::$options);
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

    public function testDetailedTransportClassifiesFailuresAndCapturesRetryAfter(): void
    {
        $transport = new CurlWebhookTransport(static fn (string $host): array => ['93.184.216.34']);
        CurlSpy::$statusCode = 429;
        CurlSpy::$retryAfter = '600';
        $result = $transport->deliver('https://hooks.example.test/incoming', [], 'ff-stable');
        $this->assertSame(600, $result->retryAfterSeconds);
        $this->assertTrue($result->retryable);
        $this->assertContains('X-FormFlow-Delivery-ID: ff-stable', CurlSpy::$options[CURLOPT_HTTPHEADER]);
        CurlSpy::$statusCode = 400;
        $this->assertFalse($transport->deliver('https://hooks.example.test/incoming', [], 'ff-stable')->retryable);
        CurlSpy::$statusCode = 408;
        $this->assertTrue($transport->deliver('https://hooks.example.test/incoming', [], 'ff-stable')->retryable);
    }

    public function testRetryAfterAcceptsSecondsAndHttpDatesAndRejectsRelativeDates(): void
    {
        $now = strtotime('Mon, 12 Oct 2026 12:00:00 GMT');
        $this->assertSame(600, CurlWebhookTransport::parseRetryAfter('600', $now));
        $this->assertSame(600, CurlWebhookTransport::parseRetryAfter('Mon, 12 Oct 2026 12:10:00 GMT', $now));
        $this->assertSame(0, CurlWebhookTransport::parseRetryAfter('Mon, 12 Oct 2026 11:00:00 GMT', $now));
        $this->assertSame(86400, CurlWebhookTransport::parseRetryAfter(str_repeat('9', 100), $now));
        $this->assertNull(CurlWebhookTransport::parseRetryAfter('+1 hour', $now));
        $this->assertNull(CurlWebhookTransport::parseRetryAfter('-1', $now));
    }

    public function testDetailedTransportSeparatesPermanentAndTransientResponses(): void
    {
        $transport = new CurlWebhookTransport(static fn (string $host): array => ['93.184.216.34']);
        foreach ([301 => false, 400 => false, 401 => false, 403 => false, 404 => false, 408 => true, 409 => false, 422 => false, 429 => true, 500 => true, 503 => true, 0 => true] as $code => $retryable) {
            CurlSpy::$statusCode = $code;
            $result = $transport->deliver('https://hooks.example.test/incoming', [], 'ff-stable');
            $this->assertSame($retryable, $result->retryable, 'HTTP ' . $code);
            $this->assertSame('Webhook returned HTTP ' . $code . '.', $result->error);
        }
        CurlSpy::$response = false;
        CurlSpy::$error = 'Network unavailable';
        $result = $transport->deliver('https://hooks.example.test/incoming', [], 'ff-stable');
        $this->assertTrue($result->retryable);
        $this->assertSame('Network unavailable', $result->error);
    }

    public function testInvalidDeliveryIdentityAndPrivateDestinationFailBeforeNetworkRequest(): void
    {
        $transport = new CurlWebhookTransport(static fn (string $host): array => ['127.0.0.1']);
        foreach (["ff-id\r\nInjected: yes", '', str_repeat('x', 129)] as $id) {
            $result = $transport->deliver('https://hooks.example.test/incoming', [], $id);
            $this->assertSame('Invalid webhook delivery ID.', $result->error);
            $this->assertFalse($result->retryable);
            $this->assertSame('', CurlSpy::$url);
        }
        $result = $transport->deliver('https://hooks.example.test/incoming', [], 'ff-safe');
        $this->assertNotNull($result->error);
        $this->assertFalse($result->retryable);
        $this->assertSame('', CurlSpy::$url);
    }

    public function testInterimHeadersCannotLeakRetryAfterIntoFinalResponse(): void
    {
        $transport = new CurlWebhookTransport(static fn (string $host): array => ['93.184.216.34']);
        CurlSpy::$statusCode = 503;
        CurlSpy::$responseHeaders = ["HTTP/1.1 100 Continue\r\n", "Retry-After: 600\r\n", "\r\n", "HTTP/1.1 503 Service Unavailable\r\n"];
        $result = $transport->deliver('https://hooks.example.test/incoming', [], 'ff-stable');
        $this->assertNull($result->retryAfterSeconds);
        $this->assertTrue($result->retryable);
        CurlSpy::$responseHeaders[] = "rEtRy-AfTeR: 900\r\n";
        $result = $transport->deliver('https://hooks.example.test/incoming', [], 'ff-stable');
        $this->assertSame(900, $result->retryAfterSeconds);
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
