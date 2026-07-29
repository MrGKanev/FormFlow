<?php

declare(strict_types=1);

namespace formflow\Tests;

use formflow\Totp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class TotpTest extends TestCase
{
    /** RFC 6238 Appendix B test vectors (SHA1), truncated to our 6-digit codes. */
    private const RFC6238_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    /** @return iterable<string, array{0: int, 1: string}> */
    public static function rfc6238Vectors(): iterable
    {
        yield 'T=59' => [59, '287082'];
        yield 'T=1111111109' => [1111111109, '081804'];
        yield 'T=1111111111' => [1111111111, '050471'];
        yield 'T=1234567890' => [1234567890, '005924'];
        yield 'T=2000000000' => [2000000000, '279037'];
    }

    #[DataProvider('rfc6238Vectors')]
    public function testVerifyMatchesRfc6238TestVectors(int $time, string $expectedCode): void
    {
        $this->assertTrue(Totp::verify(self::RFC6238_SECRET, $expectedCode, $time));
    }

    public function testVerifyRejectsWrongCode(): void
    {
        $this->assertFalse(Totp::verify(self::RFC6238_SECRET, '000000', 59));
    }

    public function testVerifyRejectsEmptySecret(): void
    {
        $this->assertFalse(Totp::verify('', '287082', 59));
    }

    public function testVerifyRejectsMalformedCode(): void
    {
        $this->assertFalse(Totp::verify(self::RFC6238_SECRET, '12345', 59));
        $this->assertFalse(Totp::verify(self::RFC6238_SECRET, 'abcdef', 59));
    }

    public function testVerifyStripsNonDigitsBeforeComparing(): void
    {
        $this->assertTrue(Totp::verify(self::RFC6238_SECRET, '287-082', 59));
    }

    public function testVerifyAcceptsAdjacentTimeStepsWithinWindow(): void
    {
        // T=59 is step 1 (floor(59/30)); the code for step 1 must also verify at T=60..89 (step 2 start) minus one step tolerance.
        $this->assertTrue(Totp::verify(self::RFC6238_SECRET, '287082', 59 - 30));
        $this->assertTrue(Totp::verify(self::RFC6238_SECRET, '287082', 59 + 30));
    }

    public function testVerifyRejectsCodeOutsideWindow(): void
    {
        $this->assertFalse(Totp::verify(self::RFC6238_SECRET, '287082', 59 + 90));
    }

    public function testMatchedStepReturnsNullForNoMatch(): void
    {
        $this->assertNull(Totp::matchedStep(self::RFC6238_SECRET, '000000', 59));
    }

    public function testMatchedStepReturnsTimeStepCounterForMatch(): void
    {
        $this->assertSame(intdiv(59, 30), Totp::matchedStep(self::RFC6238_SECRET, '287082', 59));
    }

    public function testGenerateSecretReturnsValidBase32(): void
    {
        $secret = Totp::generateSecret();

        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $secret);
    }

    public function testGenerateSecretIsRandomEachCall(): void
    {
        $this->assertNotSame(Totp::generateSecret(), Totp::generateSecret());
    }

    public function testGeneratedSecretRoundTripsThroughVerify(): void
    {
        $secret = Totp::generateSecret();
        $reflection = new ReflectionMethod(Totp::class, 'code');
        $code = $reflection->invoke(null, $secret, 1000000);

        $this->assertTrue(Totp::verify($secret, $code, 1000000));
    }

    public function testProvisioningUriUsesOtpAuthFormat(): void
    {
        $uri = Totp::provisioningUri('jbsw y3dp ehpk3pxp', 'owner@example.com', 'FormFlow');

        $this->assertSame(
            'otpauth://totp/FormFlow%3Aowner%40example.com?secret=JBSWY3DPEHPK3PXP&issuer=FormFlow',
            $uri
        );
    }

    public function testQrSvgRendersLocalQrCode(): void
    {
        $svg = Totp::qrSvg(Totp::provisioningUri('JBSWY3DPEHPK3PXP'));

        $this->assertStringStartsWith('<svg role="img" aria-label="TOTP QR code"', $svg);
        $this->assertStringContainsString('class="qr-svg totp-qr"', $svg);
        $this->assertStringContainsString('fill="#000"', $svg);
        $this->assertStringNotContainsString('<?xml', $svg);
    }
}
