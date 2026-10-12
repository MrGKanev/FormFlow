<?php

declare(strict_types=1);

namespace formflow\Tests;

use formflow\UploadFileCleaner;
use PHPUnit\Framework\TestCase;

final class UploadFileCleanerTest extends TestCase
{
    public function testEmptyUploadDirectoryDoesNotDeleteFilesInWorkingDirectory(): void
    {
        $path = tempnam((string) getcwd(), 'formflow-cleaner-');
        self::assertNotFalse($path);

        try {
            UploadFileCleaner::deletePayloadUploads([
                'attachment' => ['type' => 'upload', 'stored_name' => basename($path)],
            ], '');

            $this->assertFileExists($path);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}
