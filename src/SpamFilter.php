<?php

declare(strict_types=1);

namespace formflow;

final class SpamFilter
{
    /** @param list<string> $blockedPatterns */
    public function __construct(
        private readonly array $blockedPatterns
    ) {
    }

    /** @param array<string, string> $fields */
    public function isSpam(array $fields): bool
    {
        if ($this->blockedPatterns === []) {
            return false;
        }

        $haystack = mb_strtolower(implode(' ', $fields));

        foreach ($this->blockedPatterns as $pattern) {
            if (str_contains($haystack, mb_strtolower($pattern))) {
                return true;
            }
        }

        return false;
    }
}
