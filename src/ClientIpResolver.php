<?php

declare(strict_types=1);

namespace formflow;

final class ClientIpResolver
{
    private readonly IpMatcher $matcher;

    /**
     * @param list<string> $trustedProxies
     * @param list<string> $trustedHeaders
     */
    public function __construct(
        private readonly array $trustedProxies = [],
        private readonly array $trustedHeaders = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
        ],
        ?IpMatcher $matcher = null
    ) {
        $this->matcher = $matcher ?? new IpMatcher();
    }

    /** @param array<string, mixed> $server */
    public function resolve(array $server): ?string
    {
        $remoteAddr = $this->validIp($server['REMOTE_ADDR'] ?? null);

        if ($remoteAddr === null) {
            return null;
        }

        if (!$this->matcher->matches($remoteAddr, $this->trustedProxies)) {
            return $remoteAddr;
        }

        foreach ($this->trustedHeaders as $header) {
            $candidate = $this->headerCandidate($server[$header] ?? null);

            if ($candidate !== null) {
                return $candidate;
            }
        }

        return $remoteAddr;
    }

    private function headerCandidate(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        foreach (explode(',', $value) as $part) {
            $candidate = $this->validIp(trim($part));

            if ($candidate !== null) {
                return $candidate;
            }
        }

        return null;
    }

    private function validIp(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_IP) === false ? null : $value;
    }
}
