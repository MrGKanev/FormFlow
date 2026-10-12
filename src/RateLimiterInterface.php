<?php

declare(strict_types=1);

namespace formflow;

interface RateLimiterInterface
{
    /** Atomically records a hit and returns the exceeded limit, if any.
     * @return 'ip'|'daily'|null
     */
    public function consume(string $formId, ?string $ipHash, int $windowMinutes, int $ipMaximum, int $dailyMaximum): ?string;

    public function hit(string $formId, ?string $ipHash): void;

    public function countRecentHitsByIp(string $formId, ?string $ipHash, int $windowMinutes): int;

    public function countRecentHitsForForm(string $formId, int $windowMinutes): int;
}
