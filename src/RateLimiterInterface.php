<?php

declare(strict_types=1);

namespace formflow;

interface RateLimiterInterface
{
    public function hit(string $formId, ?string $ipHash): void;

    public function countRecentHitsByIp(string $formId, ?string $ipHash, int $windowMinutes): int;

    public function countRecentHitsForForm(string $formId, int $windowMinutes): int;
}
