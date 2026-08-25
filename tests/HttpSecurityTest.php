<?php

declare(strict_types=1);

namespace formflow\Tests;

use formflow\HttpSecurity;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class HttpSecurityTest extends TestCase
{
    #[RunInSeparateProcess]
    public function testHardenSessionCookiesAppliesSecureCookieDefaultsForHttps(): void
    {
        HttpSecurity::hardenSessionCookies(true);

        $cookies = session_get_cookie_params();

        $this->assertSame(0, $cookies['lifetime']);
        $this->assertSame('/', $cookies['path']);
        $this->assertTrue($cookies['secure']);
        $this->assertTrue($cookies['httponly']);
        $this->assertSame('Lax', $cookies['samesite']);
        $this->assertSame('1', ini_get('session.use_strict_mode'));
    }

    #[RunInSeparateProcess]
    public function testHardenSessionCookiesKeepsLocalHttpCookieUsable(): void
    {
        HttpSecurity::hardenSessionCookies(false);

        $cookies = session_get_cookie_params();

        $this->assertFalse($cookies['secure']);
        $this->assertTrue($cookies['httponly']);
        $this->assertSame('Lax', $cookies['samesite']);
    }
}
