<?php

declare(strict_types=1);

namespace formflow\Tests;

use formflow\Totp;
use PHPUnit\Framework\TestCase;

final class TotpTest extends TestCase
{
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

        $this->assertStringStartsWith('<svg class="totp-qr"', $svg);
        $this->assertStringContainsString('aria-label="TOTP QR code"', $svg);
        $this->assertStringContainsString('<path fill="#000"', $svg);
    }
}
