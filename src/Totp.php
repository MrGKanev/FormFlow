<?php

declare(strict_types=1);

namespace formflow;

use InvalidArgumentException;

final class Totp
{
    /** @var array<int, array{data: int, ecc: int, blocks: list<array{data: int, ecc: int}>}> */
    private const QR_L_CAPACITY = [
        1 => ['data' => 19, 'ecc' => 7, 'blocks' => [['data' => 19, 'ecc' => 7]]],
        2 => ['data' => 34, 'ecc' => 10, 'blocks' => [['data' => 34, 'ecc' => 10]]],
        3 => ['data' => 55, 'ecc' => 15, 'blocks' => [['data' => 55, 'ecc' => 15]]],
        4 => ['data' => 80, 'ecc' => 20, 'blocks' => [['data' => 80, 'ecc' => 20]]],
        5 => ['data' => 108, 'ecc' => 26, 'blocks' => [['data' => 108, 'ecc' => 26]]],
        6 => ['data' => 136, 'ecc' => 36, 'blocks' => [['data' => 68, 'ecc' => 18], ['data' => 68, 'ecc' => 18]]],
    ];

    /** @var array<int, list<int>> */
    private const QR_ALIGNMENT = [
        1 => [],
        2 => [6, 18],
        3 => [6, 22],
        4 => [6, 26],
        5 => [6, 30],
        6 => [6, 34],
    ];

    public static function verify(string $secret, string $code, ?int $time = null): bool
    {
        $code = preg_replace('/\D+/', '', $code) ?? '';

        if ($secret === '' || strlen($code) !== 6) {
            return false;
        }

        $time ??= time();

        for ($offset = -1; $offset <= 1; $offset++) {
            if (hash_equals(self::code($secret, $time + ($offset * 30)), $code)) {
                return true;
            }
        }

        return false;
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
        $bytes = array_values(unpack('C*', $payload) ?: []);
        $version = self::qrVersionForLength(count($bytes));
        $data = self::qrDataCodewords($bytes, self::QR_L_CAPACITY[$version]['data']);
        $codewords = self::qrCodewords($data, self::QR_L_CAPACITY[$version]['blocks']);
        $matrix = self::qrMatrix($version, $codewords);
        $size = count($matrix);
        $totalSize = ($size + ($quietZone * 2)) * $scale;
        $darkPath = '';

        foreach ($matrix as $row => $columns) {
            foreach ($columns as $column => $dark) {
                if ($dark) {
                    $x = ($column + $quietZone) * $scale;
                    $y = ($row + $quietZone) * $scale;
                    $darkPath .= 'M' . $x . ' ' . $y . 'h' . $scale . 'v' . $scale . 'h-' . $scale . 'z';
                }
            }
        }

        return '<svg class="totp-qr" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $totalSize . ' ' . $totalSize . '" role="img" aria-label="TOTP QR code">'
            . '<path fill="#fff" d="M0 0h' . $totalSize . 'v' . $totalSize . 'H0z"/>'
            . '<path fill="#000" d="' . $darkPath . '"/>'
            . '</svg>';
    }

    /**
     * @param list<int> $data
     * @param list<array{data: int, ecc: int}> $blockDefinitions
     * @return list<int>
     */
    private static function qrCodewords(array $data, array $blockDefinitions): array
    {
        $blocks = [];
        $offset = 0;

        foreach ($blockDefinitions as $definition) {
            $blockData = array_slice($data, $offset, $definition['data']);
            $offset += $definition['data'];
            $blocks[] = [
                'data' => $blockData,
                'ecc' => self::qrReedSolomon($blockData, $definition['ecc']),
            ];
        }

        $codewords = [];
        $maxDataLength = max(array_map(static fn (array $block): int => count($block['data']), $blocks));
        $maxEccLength = max(array_map(static fn (array $block): int => count($block['ecc']), $blocks));

        for ($i = 0; $i < $maxDataLength; $i++) {
            foreach ($blocks as $block) {
                if (isset($block['data'][$i])) {
                    $codewords[] = $block['data'][$i];
                }
            }
        }

        for ($i = 0; $i < $maxEccLength; $i++) {
            foreach ($blocks as $block) {
                if (isset($block['ecc'][$i])) {
                    $codewords[] = $block['ecc'][$i];
                }
            }
        }

        return $codewords;
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

    private static function qrVersionForLength(int $byteLength): int
    {
        foreach (self::QR_L_CAPACITY as $version => $capacity) {
            $bitLength = 4 + 8 + ($byteLength * 8);

            if ($bitLength <= $capacity['data'] * 8) {
                return $version;
            }
        }

        throw new InvalidArgumentException('TOTP QR payload is too long.');
    }

    /** @param list<int> $bytes @return list<int> */
    private static function qrDataCodewords(array $bytes, int $dataCodewords): array
    {
        $capacityBits = $dataCodewords * 8;
        $bits = '0100' . str_pad(decbin(count($bytes)), 8, '0', STR_PAD_LEFT);

        foreach ($bytes as $byte) {
            $bits .= str_pad(decbin($byte), 8, '0', STR_PAD_LEFT);
        }

        $bits .= str_repeat('0', min(4, $capacityBits - strlen($bits)));

        while (strlen($bits) % 8 !== 0) {
            $bits .= '0';
        }

        $padBytes = [0xec, 0x11];
        $padIndex = 0;

        while (strlen($bits) < $capacityBits) {
            $bits .= str_pad(decbin($padBytes[$padIndex % 2]), 8, '0', STR_PAD_LEFT);
            $padIndex++;
        }

        $codewords = [];

        foreach (str_split($bits, 8) as $byte) {
            $codewords[] = bindec($byte);
        }

        return $codewords;
    }

    /** @param list<int> $data @return list<int> */
    private static function qrReedSolomon(array $data, int $eccCount): array
    {
        $generator = [1];

        for ($i = 0; $i < $eccCount; $i++) {
            $generator = self::qrPolynomialMultiply($generator, [1, self::qrGfPow($i)]);
        }

        $ecc = array_fill(0, $eccCount, 0);

        foreach ($data as $byte) {
            $factor = $byte ^ $ecc[0];
            array_shift($ecc);
            $ecc[] = 0;

            for ($i = 0; $i < $eccCount; $i++) {
                $ecc[$i] ^= self::qrGfMultiply($generator[$i + 1], $factor);
            }
        }

        return $ecc;
    }

    /** @param list<int> $left @param list<int> $right @return list<int> */
    private static function qrPolynomialMultiply(array $left, array $right): array
    {
        $result = array_fill(0, count($left) + count($right) - 1, 0);

        foreach ($left as $i => $leftValue) {
            foreach ($right as $j => $rightValue) {
                $result[$i + $j] ^= self::qrGfMultiply($leftValue, $rightValue);
            }
        }

        return $result;
    }

    private static function qrGfPow(int $power): int
    {
        $value = 1;

        for ($i = 0; $i < $power; $i++) {
            $value = self::qrGfMultiply($value, 2);
        }

        return $value;
    }

    private static function qrGfMultiply(int $left, int $right): int
    {
        $result = 0;

        while ($right > 0) {
            if (($right & 1) !== 0) {
                $result ^= $left;
            }

            $left <<= 1;

            if (($left & 0x100) !== 0) {
                $left ^= 0x11d;
            }

            $right >>= 1;
        }

        return $result & 0xff;
    }

    /** @param list<int> $codewords @return list<list<bool>> */
    private static function qrMatrix(int $version, array $codewords): array
    {
        $size = 17 + ($version * 4);
        $matrix = array_fill(0, $size, array_fill(0, $size, false));
        $reserved = array_fill(0, $size, array_fill(0, $size, false));
        $set = static function (int $row, int $column, bool $dark) use (&$matrix, &$reserved, $size): void {
            if ($row < 0 || $column < 0 || $row >= $size || $column >= $size) {
                return;
            }

            $matrix[$row][$column] = $dark;
            $reserved[$row][$column] = true;
        };

        self::qrFinder($set, 0, 0);
        self::qrFinder($set, 0, $size - 7);
        self::qrFinder($set, $size - 7, 0);
        self::qrTiming($set, $version, $size);
        self::qrAlignment($set, $version, $size);
        self::qrFormat($set, $size);
        $set(4 * $version + 9, 8, true);
        self::qrPlaceData($matrix, $reserved, $codewords);

        return $matrix;
    }

    private static function qrFinder(callable $set, int $row, int $column): void
    {
        for ($r = -1; $r <= 7; $r++) {
            for ($c = -1; $c <= 7; $c++) {
                $inside = $r >= 0 && $r <= 6 && $c >= 0 && $c <= 6;
                $dark = $inside && ($r === 0 || $r === 6 || $c === 0 || $c === 6 || ($r >= 2 && $r <= 4 && $c >= 2 && $c <= 4));
                $set($row + $r, $column + $c, $dark);
            }
        }
    }

    private static function qrTiming(callable $set, int $version, int $size): void
    {
        for ($i = 8; $i < $size - 8; $i++) {
            $set(6, $i, $i % 2 === 0);
            $set($i, 6, $i % 2 === 0);
        }
    }

    private static function qrAlignment(callable $set, int $version, int $size): void
    {
        foreach (self::QR_ALIGNMENT[$version] as $row) {
            foreach (self::QR_ALIGNMENT[$version] as $column) {
                if (($row === 6 && ($column === 6 || $column === $size - 7)) || ($column === 6 && $row === $size - 7)) {
                    continue;
                }

                for ($r = -2; $r <= 2; $r++) {
                    for ($c = -2; $c <= 2; $c++) {
                        $dark = max(abs($r), abs($c)) !== 1;
                        $set($row + $r, $column + $c, $dark);
                    }
                }
            }
        }
    }

    private static function qrFormat(callable $set, int $size): void
    {
        $format = self::qrFormatBits();

        for ($i = 0; $i <= 5; $i++) {
            $set(8, $i, (($format >> $i) & 1) === 1);
        }

        $set(8, 7, (($format >> 6) & 1) === 1);
        $set(8, 8, (($format >> 7) & 1) === 1);
        $set(7, 8, (($format >> 8) & 1) === 1);

        for ($i = 9; $i < 15; $i++) {
            $set(14 - $i, 8, (($format >> $i) & 1) === 1);
        }

        for ($i = 0; $i < 8; $i++) {
            $set($size - 1 - $i, 8, (($format >> $i) & 1) === 1);
        }

        for ($i = 8; $i < 15; $i++) {
            $set(8, $size - 15 + $i, (($format >> $i) & 1) === 1);
        }
    }

    private static function qrFormatBits(): int
    {
        $data = 1 << 3;
        $remainder = $data;

        for ($i = 0; $i < 10; $i++) {
            $remainder = ($remainder << 1) ^ (((($remainder >> 9) & 1) !== 0) ? 0x537 : 0);
        }

        return (($data << 10) | ($remainder & 0x3ff)) ^ 0x5412;
    }

    /** @param list<list<bool>> $matrix @param list<list<bool>> $reserved @param list<int> $codewords */
    private static function qrPlaceData(array &$matrix, array $reserved, array $codewords): void
    {
        $size = count($matrix);
        $bits = '';

        foreach ($codewords as $codeword) {
            $bits .= str_pad(decbin($codeword), 8, '0', STR_PAD_LEFT);
        }

        $bitIndex = 0;
        $upward = true;

        for ($right = $size - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right--;
            }

            for ($vertical = 0; $vertical < $size; $vertical++) {
                $row = $upward ? $size - 1 - $vertical : $vertical;

                for ($columnOffset = 0; $columnOffset < 2; $columnOffset++) {
                    $column = $right - $columnOffset;

                    if ($reserved[$row][$column]) {
                        continue;
                    }

                    $dark = $bitIndex < strlen($bits) && $bits[$bitIndex] === '1';
                    $masked = (($row + $column) % 2) === 0;
                    $matrix[$row][$column] = $dark !== $masked;
                    $bitIndex++;
                }
            }

            $upward = !$upward;
        }
    }
}
