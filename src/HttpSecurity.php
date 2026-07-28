<?php

declare(strict_types=1);

namespace formflow;

final class HttpSecurity
{
    public static function hardenSessionCookies(bool $https): void
    {
        if (session_status() !== PHP_SESSION_NONE) {
            return;
        }

        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        ini_set('session.use_strict_mode', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_samesite', 'Lax');
        ini_set('session.cookie_secure', $https ? '1' : '0');
    }

    public static function sendHeaders(bool $https): void
    {
        if (headers_sent()) {
            return;
        }

        header_remove('X-Powered-By');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
        header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://challenges.cloudflare.com https://js.hcaptcha.com https://www.google.com https://www.gstatic.com; style-src 'self' 'unsafe-inline'; img-src 'self' data:; frame-src https://challenges.cloudflare.com https://*.hcaptcha.com https://www.google.com; connect-src 'self'; form-action 'self' https:; base-uri 'none'; frame-ancestors 'none'");

        if ($https) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }
}
