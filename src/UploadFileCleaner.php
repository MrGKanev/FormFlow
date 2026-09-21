<?php

declare(strict_types=1);

namespace formflow;

final class UploadFileCleaner
{
    /** @param array<string, mixed> $payload */
    public static function deletePayloadUploads(array $payload, string $uploadDirectory): void
    {
        $root = realpath($uploadDirectory);

        if ($root === false) {
            return;
        }

        foreach ($payload as $value) {
            if (!is_array($value) || ($value['type'] ?? null) !== 'upload') {
                continue;
            }

            $name = trim((string) ($value['stored_name'] ?? $value['relative_path'] ?? ''));
            if ($name === '' || $name !== basename($name)) {
                continue;
            }

            $path = realpath($root . DIRECTORY_SEPARATOR . $name);
            if ($path !== false && str_starts_with($path, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) && is_file($path)) {
                @unlink($path);
            }
        }
    }
}
