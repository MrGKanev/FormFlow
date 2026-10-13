<?php

declare(strict_types=1);

namespace formflow;

use InvalidArgumentException;

final readonly class MailRetryPolicy
{
    public function __construct(
        public int $maxAttempts = 5,
        private int $baseDelaySeconds = 60,
        private int $maxDelaySeconds = 3600
    ) {
        if ($maxAttempts < 1 || $maxAttempts > 100 || $baseDelaySeconds < 1
            || $maxDelaySeconds < $baseDelaySeconds || $maxDelaySeconds > 86400) {
            throw new InvalidArgumentException('Mail retries require 1–100 attempts and delays between 1 and 86400 seconds, with maximum delay at least the base delay.');
        }
    }

    public static function fromEnvironment(): self
    {
        return new self(
            self::setting('MAIL_MAX_ATTEMPTS', 5),
            self::setting('MAIL_RETRY_BASE_DELAY_SECONDS', 60),
            self::setting('MAIL_RETRY_MAX_DELAY_SECONDS', 3600)
        );
    }

    public function delay(int $attempts): int
    {
        if ($attempts < 1) {
            throw new InvalidArgumentException('Mail attempt number must be positive.');
        }
        $ceiling = $this->baseDelaySeconds;
        for ($step = 1; $step < $attempts && $ceiling < $this->maxDelaySeconds; $step++) {
            $ceiling = min($this->maxDelaySeconds, $ceiling * 2);
        }
        // Equal jitter spreads retries without allowing an immediate retry.
        return random_int(max(1, intdiv($ceiling, 2)), $ceiling);
    }

    private static function setting(string $name, int $default): int
    {
        $raw = getenv($name);
        if ($raw === false || trim($raw) === '') {
            return $default;
        }
        $value = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($value === false) {
            throw new InvalidArgumentException($name . ' must be a positive integer.');
        }
        return $value;
    }
}
