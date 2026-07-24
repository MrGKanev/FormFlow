<?php

declare(strict_types=1);

namespace formflow;

final class AdminIpWhitelist implements AdminIpWhitelistInterface
{
    private readonly IpMatcher $matcher;

    /** @param list<string> $configuredIps */
    public function __construct(
        private readonly array $configuredIps,
        private readonly AdminWhitelistRepositoryInterface $repository,
        ?IpMatcher $matcher = null
    ) {
        $this->matcher = $matcher ?? new IpMatcher();
    }

    public function isAllowed(string $ip): bool
    {
        if ($this->matcher->matches($ip, $this->configuredIps)) {
            return true;
        }

        $dynamicEntries = array_map(
            static fn (array $row): string => (string) $row['ip_or_cidr'],
            $this->repository->list()
        );

        return $this->matcher->matches($ip, $dynamicEntries);
    }
}
