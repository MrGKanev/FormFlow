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

    /** Supports both IPv4 and IPv6 CIDR ranges by comparing the inet_pton() binary form. */
    private function ipInCidr(string $ip, string $cidr): bool
    {
        [$subnet, $prefixLength] = explode('/', $cidr, 2);

        if (!ctype_digit($prefixLength)) {
            return false;
        }

        $ipBinary = inet_pton($ip);
        $subnetBinary = inet_pton($subnet);

        if ($ipBinary === false || $subnetBinary === false || strlen($ipBinary) !== strlen($subnetBinary)) {
            return false;
        }

        $prefixLength = (int) $prefixLength;
        $addressBits = strlen($ipBinary) * 8;

        if ($prefixLength > $addressBits) {
            return false;
        }

        if ($prefixLength === 0) {
            return true;
        }

        $fullBytes = intdiv($prefixLength, 8);

        if (strncmp($ipBinary, $subnetBinary, $fullBytes) !== 0) {
            return false;
        }

        $remainderBits = $prefixLength % 8;

        if ($remainderBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainderBits)) & 0xFF;

        return (ord($ipBinary[$fullBytes]) & $mask) === (ord($subnetBinary[$fullBytes]) & $mask);
    }
}
