<?php

declare(strict_types=1);

namespace formflow;

final class Clock
{
    public static function nowTimestamp(): int
    {
        return time();
    }

    public static function nowIso(): string
    {
        return gmdate('c', self::nowTimestamp());
    }

    public static function relativeIso(int $seconds): string
    {
        return gmdate('c', self::nowTimestamp() + $seconds);
    }

    public static function currentMonth(): string
    {
        return gmdate('Y-m');
    }

    public static function compactTimestamp(): string
    {
        return gmdate('YmdHis');
    }
}
