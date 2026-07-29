<?php

declare(strict_types=1);

namespace formflow;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QRMarkupSVG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

final class Totp
{
    public static function verify(string $secret, string $code, ?int $time = null): bool
    {
        return self::matchedStep($secret, $code, $time) !== null;
    }

    /** Returns the matched 30-second time-step counter, or null if the code doesn't match. Used to detect replay. */
    public static function matchedStep(string $secret, string $code, ?int $time = null): ?int
    {
        $code = preg_replace('/\D+/', '', $code) ?? '';

        if ($secret === '' || strlen($code) !== 6) {
            return null;
        }

        $time ??= time();

        for ($offset = -1; $offset <= 1; $offset++) {
            $candidate = $time + ($offset * 30);

            if (hash_equals(self::code($secret, $candidate), $code)) {
                return intdiv($candidate, 30);
            }
        }

        return null;
    }

    public static function generateSecret(): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bytes = random_bytes(20);
        $bits = '';
        $secret = '';

        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        foreach (str_split($bits, 5) as $chunk) {
            if (strlen($chunk) < 5) {
                $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            }

            $secret .= $alphabet[bindec($chunk)];
        }

        return $secret;
    }

    public static function provisioningUri(string $secret, string $account = 'admin', string $issuer = 'FormFlow'): string
    {
        $secret = strtoupper(preg_replace('/[^A-Z2-7]+/i', '', $secret) ?? '');
        $account = trim($account) !== '' ? trim($account) : 'admin';
        $issuer = trim($issuer) !== '' ? trim($issuer) : 'FormFlow';
        $account = strlen($account) > 48 ? substr($account, 0, 48) : $account;

        return 'otpauth://totp/' . rawurlencode($issuer . ':' . $account)
            . '?secret=' . rawurlencode($secret)
            . '&issuer=' . rawurlencode($issuer);
    }

    public static function qrSvg(string $payload, int $scale = 5, int $quietZone = 4): string
    {
        $options = new QROptions([
            'outputInterface' => QRMarkupSVG::class,
            'outputBase64' => false,
            'svgAddXmlHeader' => false,
            'cssClass' => 'totp-qr',
            'eccLevel' => EccLevel::L,
            'addQuietzone' => true,
            'quietzoneSize' => $quietZone,
            'scale' => $scale,
        ]);

        $svg = (new QRCode($options))->render($payload);

        return str_replace('<svg ', '<svg role="img" aria-label="TOTP QR code" ', $svg);
    }

    private static function code(string $secret, int $time): string
    {
        $counter = intdiv($time, 30);
        $binaryCounter = pack('N*', 0) . pack('N*', $counter);
        $hash = hash_hmac('sha1', $binaryCounter, self::base32Decode($secret), true);
        $offset = ord($hash[19]) & 0x0f;
        $value = ((ord($hash[$offset]) & 0x7f) << 24)
            | ((ord($hash[$offset + 1]) & 0xff) << 16)
            | ((ord($hash[$offset + 2]) & 0xff) << 8)
            | (ord($hash[$offset + 3]) & 0xff);

        return str_pad((string) ($value % 1000000), 6, '0', STR_PAD_LEFT);
    }

    private static function base32Decode(string $secret): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = strtoupper(preg_replace('/[^A-Z2-7]+/i', '', $secret) ?? '');
        $bits = '';
        $output = '';

        foreach (str_split($secret) as $char) {
            $index = strpos($alphabet, $char);

            if ($index === false) {
                continue;
            }

            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }

        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $output .= chr(bindec($byte));
            }
        }

        return $output;
    }
}
