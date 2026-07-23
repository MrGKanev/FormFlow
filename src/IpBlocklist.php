<?php

declare(strict_types=1);

namespace formflow;

final class IpBlocklist
{
    /** @param list<string> $blockedIps */
    public function __construct(
        private readonly array $blockedIps
    ) {
    }

    public function isBlocked(string $ip): bool
    {
        foreach ($this->blockedIps as $entry) {
            if (str_contains($entry, '/')) {
                if ($this->ipInCidr($ip, $entry)) {
                    return true;
                }

                continue;
            }

            if ($entry === $ip) {
                return true;
            }
        }

        return false;
    }

    private function ipInCidr(string $ip, string $cidr): bool
    {
        [$subnet, $prefixLength] = explode('/', $cidr, 2);

        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);

        if ($ipLong === false || $subnetLong === false) {
            return false;
        }

        $prefixLength = (int) $prefixLength;

        if ($prefixLength < 0 || $prefixLength > 32) {
            return false;
        }

        if ($prefixLength === 0) {
            return true;
        }

        $mask = -1 << (32 - $prefixLength);

        return ($ipLong & $mask) === ($subnetLong & $mask);
    }
}
