<?php

declare(strict_types=1);

namespace formflow;

interface AdminIpWhitelistInterface
{
    public function isAllowed(string $ip): bool;
}
