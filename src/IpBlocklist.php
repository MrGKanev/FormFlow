<?php

declare(strict_types=1);

namespace formflow;

final class IpBlocklist
{
    private readonly IpMatcher $matcher;

    /** @param list<string> $blockedIps */
    public function __construct(
        private readonly array $blockedIps,
        ?IpMatcher $matcher = null
    ) {
        $this->matcher = $matcher ?? new IpMatcher();
    }

    public function isBlocked(string $ip): bool
    {
        return $this->matcher->matches($ip, $this->blockedIps);
    }
}
