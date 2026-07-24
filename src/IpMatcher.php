<?php

declare(strict_types=1);

namespace formflow;

final class IpMatcher
{
    /** @param list<string> $entries */
    public function matches(string $ip, array $entries): bool
    {
        foreach ($entries as $entry) {
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

        if (!ctype_digit($prefixLength)) {
            return false;
        }

        $prefixLength = (int) $prefixLength;

        if ($prefixLength > 32) {
            return false;
        }

        if ($prefixLength === 0) {
            return true;
        }

        $mask = -1 << (32 - $prefixLength);

        return ($ipLong & $mask) === ($subnetLong & $mask);
    }
}
