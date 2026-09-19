<?php

declare(strict_types=1);

namespace formflow;

final class WebhookUrlPolicy
{
    /** @param callable(string): list<string>|null $resolver */
    public static function validate(string $url, ?callable $resolver = null): ?string
    {
        return self::resolve($url, $resolver)['error'];
    }

    /**
     * Validates the URL and returns the exact IPs that were checked, so callers can
     * pin the outbound connection to them (avoiding a DNS-rebinding gap between
     * validation and the actual request).
     *
     * @param callable(string): list<string>|null $resolver
     * @return array{error: ?string, ips: list<string>}
     */
    public static function resolve(string $url, ?callable $resolver = null): array
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return ['error' => 'Webhook URL is invalid.', 'ips' => []];
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if (!in_array($scheme, ['http', 'https'], true)) {
            return ['error' => 'Webhook URL must use http or https.', 'ips' => []];
        }

        if (parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_PASS) !== null) {
            return ['error' => 'Webhook URL must not include credentials.', 'ips' => []];
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost')) {
            return ['error' => 'Webhook destination is not allowed.', 'ips' => []];
        }

        $ips = self::resolveHost($host, $resolver);

        if ($ips === []) {
            return ['error' => 'Webhook destination could not be resolved.', 'ips' => []];
        }

        foreach ($ips as $ip) {
            if (!self::isPublicIp($ip)) {
                return ['error' => 'Webhook destination is not allowed.', 'ips' => []];
            }
        }

        return ['error' => null, 'ips' => $ips];
    }

    /** @param callable(string): list<string>|null $resolver @return list<string> */
    private static function resolveHost(string $host, ?callable $resolver): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        if ($resolver !== null) {
            return array_values(array_unique(array_filter(
                array_map('strval', $resolver($host)),
                static fn (string $ip): bool => filter_var($ip, FILTER_VALIDATE_IP) !== false
            )));
        }

        $ips = [];
        $records = dns_get_record($host, DNS_A | DNS_AAAA);

        foreach ($records === false ? [] : $records as $record) {
            foreach (['ip', 'ipv6'] as $key) {
                if (isset($record[$key]) && filter_var($record[$key], FILTER_VALIDATE_IP) !== false) {
                    $ips[] = $record[$key];
                }
            }
        }

        foreach (gethostbynamel($host) ?: [] as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                $ips[] = $ip;
            }
        }

        return array_values(array_unique($ips));
    }

    private static function isPublicIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }
}
