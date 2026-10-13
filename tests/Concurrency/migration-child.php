<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

echo "ready\n";
fflush(STDOUT);
if (trim((string) fgets(STDIN)) !== 'go') {
    exit(2);
}

try {
    new \formflow\SqliteSubmissionRepository($argv[1]);
    echo "migrated\n";
} catch (\Throwable $exception) {
    fwrite(STDERR, $exception->getMessage());
    exit(1);
}
