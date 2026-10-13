<?php

declare(strict_types=1);

namespace formflow\Tests;

use formflow\MailRetryPolicy;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MailRetryPolicyTest extends TestCase
{
    public function testDelayHasExponentialEqualJitterAndNeverExceedsCap(): void
    {
        $policy = new MailRetryPolicy(5, 60, 3600);
        self::assertSame(5, $policy->maxAttempts);
        foreach ([1 => [30, 60], 2 => [60, 120], 3 => [120, 240], 10 => [1800, 3600], PHP_INT_MAX => [1800, 3600]] as $attempt => [$minimum, $maximum]) {
            for ($sample = 0; $sample < 10; $sample++) {
                $delay = $policy->delay($attempt);
                self::assertGreaterThanOrEqual($minimum, $delay);
                self::assertLessThanOrEqual($maximum, $delay);
            }
        }
    }

    public function testCustomSmallBoundNeverSchedulesZeroOrExceedsMaximum(): void
    {
        $policy = new MailRetryPolicy(1, 1, 1);
        self::assertSame(1, $policy->delay(1));
        self::assertSame(1, $policy->delay(100));
    }

    public static function invalidConfigurations(): array
    {
        return [[0, 60, 3600], [101, 60, 3600], [5, 0, 3600], [5, 100, 99], [5, 60, 86401]];
    }

    #[DataProvider('invalidConfigurations')]
    public function testInvalidConfigurationRejected(int $attempts, int $base, int $maximum): void
    {
        $this->expectException(InvalidArgumentException::class);
        new MailRetryPolicy($attempts, $base, $maximum);
    }

    public function testAttemptNumberMustBePositive(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new MailRetryPolicy())->delay(0);
    }

    public function testEnvironmentDefaultsCustomValuesAndEmptyValues(): void
    {
        $keys = ['MAIL_MAX_ATTEMPTS', 'MAIL_RETRY_BASE_DELAY_SECONDS', 'MAIL_RETRY_MAX_DELAY_SECONDS'];
        $previous = [];
        foreach ($keys as $key) {
            $previous[$key] = [getenv($key), $_ENV[$key] ?? null, array_key_exists($key, $_ENV)];
            putenv($key);
            unset($_ENV[$key]);
        }
        try {
            self::assertSame(5, MailRetryPolicy::fromEnvironment()->maxAttempts);
            foreach (['MAIL_MAX_ATTEMPTS' => '2', 'MAIL_RETRY_BASE_DELAY_SECONDS' => '20', 'MAIL_RETRY_MAX_DELAY_SECONDS' => '20'] as $key => $value) {
                putenv($key . '=' . $value);
                $_ENV[$key] = $value;
            }
            $policy = MailRetryPolicy::fromEnvironment();
            self::assertSame(2, $policy->maxAttempts);
            self::assertGreaterThanOrEqual(10, $policy->delay(1));
            self::assertLessThanOrEqual(20, $policy->delay(100));
            foreach ($keys as $key) { putenv($key . '='); $_ENV[$key] = ''; }
            self::assertSame(5, MailRetryPolicy::fromEnvironment()->maxAttempts);
            putenv('MAIL_MAX_ATTEMPTS=2.5');
            $_ENV['MAIL_MAX_ATTEMPTS'] = '2.5';
            $this->expectException(InvalidArgumentException::class);
            MailRetryPolicy::fromEnvironment();
        } finally {
            foreach ($previous as $key => [$value, $envValue, $hadEnv]) {
                putenv($value === false ? $key : $key . '=' . $value);
                if ($hadEnv) { $_ENV[$key] = $envValue; } else { unset($_ENV[$key]); }
            }
        }
    }
}
