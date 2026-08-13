<?php

declare(strict_types=1);

namespace formflow\Tests;

use formflow\CurlCaptchaVerifier;
use formflow\CurlSpy;
use formflow\Turnstile;
use JsonException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CaptchaVerifierTest extends TestCase
{
    protected function setUp(): void
    {
        CurlSpy::reset();
    }

    public function testTurnstileSkipsNetworkCallWhenTokenOrSecretIsMissing(): void
    {
        $this->assertFalse((new Turnstile('secret'))->verify(''));
        $this->assertFalse((new Turnstile(''))->verify('token'));
        $this->assertSame('', CurlSpy::$url);
    }

    public function testTurnstilePostsExpectedPayloadAndAcceptsSuccessfulResponse(): void
    {
        CurlSpy::$statusCode = 200;
        CurlSpy::$response = '{"success":true}';

        $result = (new Turnstile('turnstile-secret'))->verify('token value', '203.0.113.9');

        $this->assertTrue($result);
        $this->assertSame(
            'https://challenges.cloudflare.com/turnstile/v0/siteverify',
            CurlSpy::$url
        );
        $this->assertSame(
            ['secret' => 'turnstile-secret', 'response' => 'token value', 'remoteip' => '203.0.113.9'],
            $this->decodeFormBody()
        );
        $this->assertSame(['Content-Type: application/x-www-form-urlencoded'], CurlSpy::$options[CURLOPT_HTTPHEADER]);
    }

    public function testTurnstileRejectsNon2xxResponsesAndSurfacesNetworkFailures(): void
    {
        CurlSpy::$statusCode = 400;
        CurlSpy::$response = '{"success":true}';
        $this->assertFalse((new Turnstile('secret'))->verify('token'));

        CurlSpy::$response = false;
        CurlSpy::$error = 'Timed out';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Turnstile request failed: Timed out');
        (new Turnstile('secret'))->verify('token');
    }

    public function testCaptchaVerifierSkipsUnknownProvidersAndMissingProviderSecrets(): void
    {
        $verifier = new CurlCaptchaVerifier([]);

        $this->assertFalse($verifier->verify('unknown', 'token'));
        $this->assertFalse($verifier->verify('hcaptcha', 'token'));
        $this->assertFalse($verifier->verify('friendlycaptcha', 'token'));
        $this->assertSame('', CurlSpy::$url);
    }

    public function testCaptchaVerifierPostsFormEncodedProviderPayloadWithoutEmptyRemoteIp(): void
    {
        CurlSpy::$statusCode = 200;
        CurlSpy::$response = '{"success":true}';
        $verifier = new CurlCaptchaVerifier(['hcaptcha_secret' => ' h-secret ']);

        $this->assertTrue($verifier->verify('hcaptcha', 'token value'));
        $this->assertSame('https://api.hcaptcha.com/siteverify', CurlSpy::$url);
        $this->assertSame(['secret' => 'h-secret', 'response' => 'token value'], $this->decodeFormBody());
        $this->assertSame(['Content-Type: application/x-www-form-urlencoded'], CurlSpy::$options[CURLOPT_HTTPHEADER]);
    }

    public function testFriendlyCaptchaPostsJsonWithOptionalSiteKey(): void
    {
        CurlSpy::$statusCode = 200;
        CurlSpy::$response = '{"success":true}';
        $verifier = new CurlCaptchaVerifier([
            'friendly_captcha_api_key' => 'api-key',
            'friendly_captcha_site_key' => 'site-key',
        ]);

        $this->assertTrue($verifier->verify('friendlycaptcha', 'token'));
        $this->assertSame('https://global.frcapi.com/api/v2/captcha/siteverify', CurlSpy::$url);
        $this->assertSame(
            ['response' => 'token', 'sitekey' => 'site-key'],
            json_decode((string) CurlSpy::$options[CURLOPT_POSTFIELDS], true, 512, JSON_THROW_ON_ERROR)
        );
        $this->assertSame(
            ['X-API-Key: api-key', 'Content-Type: application/json'],
            CurlSpy::$options[CURLOPT_HTTPHEADER]
        );
    }

    public function testCaptchaVerifierRejectsFailedResponsesAndInvalidJson(): void
    {
        CurlSpy::$statusCode = 200;
        CurlSpy::$response = '{"success":false}';
        $verifier = new CurlCaptchaVerifier(['recaptcha_secret' => 'secret']);
        $this->assertFalse($verifier->verify('recaptcha', 'token'));

        CurlSpy::$response = 'not json';

        $this->expectException(JsonException::class);
        $verifier->verify('recaptcha', 'token');
    }

    /** @return array<string, string> */
    private function decodeFormBody(): array
    {
        parse_str((string) CurlSpy::$options[CURLOPT_POSTFIELDS], $payload);

        return $payload;
    }
}
