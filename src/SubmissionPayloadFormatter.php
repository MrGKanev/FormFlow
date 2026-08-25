<?php

declare(strict_types=1);

namespace formflow;

final class SubmissionPayloadFormatter
{
    /** @param array<string, mixed> $payload @return array<string, string> */
    public static function displayFields(array $payload): array
    {
        $fields = [];

        foreach ($payload as $key => $value) {
            $fields[(string) $key] = self::displayValue($value);
        }

        return $fields;
    }

    public static function displayValue(mixed $value): string
    {
        if (is_array($value) && ($value['type'] ?? null) === 'upload') {
            $name = trim((string) ($value['original_name'] ?? 'upload'));
            $size = isset($value['size_bytes']) ? self::humanBytes((int) $value['size_bytes']) : '';
            $mimeType = trim((string) ($value['mime_type'] ?? ''));

            $details = array_values(array_filter([$size, $mimeType], static fn (string $detail): bool => $detail !== ''));

            return $details === [] ? $name : $name . ' (' . implode(', ', $details) . ')';
        }

        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        }

        return trim((string) $value);
    }

    private static function humanBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $value = (float) max(0, $bytes);
        $unit = 'B';

        foreach ($units as $unit) {
            if ($value < 1024 || $unit === 'GB') {
                break;
            }

            $value /= 1024;
        }

        return $unit === 'B'
            ? (string) $bytes . ' B'
            : number_format($value, 1) . ' ' . $unit;
    }
}
