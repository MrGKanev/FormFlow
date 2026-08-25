<?php

declare(strict_types=1);

namespace formflow\Admin;

final class SettingsSnapshot
{
    /** @param array<string, mixed> $values */
    public function __construct(private readonly array $values)
    {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->values;
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->values[$key] ?? $default;

        return is_scalar($value) ? (string) $value : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->values[$key] ?? $default;

        return is_numeric($value) ? (int) $value : $default;
    }

    /** @return list<string> */
    public function lines(string $key): array
    {
        $value = $this->string($key);
        $lines = preg_split('/\R/', $value) ?: [];
        $result = [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line !== '' && !in_array($line, $result, true)) {
                $result[] = $line;
            }
        }

        return $result;
    }

    public function appEnv(): string
    {
        return $this->string('app_env', 'production');
    }

    public function databasePath(): string
    {
        return $this->string('database_path', 'storage/submissions.sqlite');
    }

    public function mailDeliveryMode(): string
    {
        return $this->string('mail_delivery_mode', 'sync');
    }

    public function webhookDeliveryMode(): string
    {
        return $this->string('webhook_delivery_mode', 'sync');
    }
}
