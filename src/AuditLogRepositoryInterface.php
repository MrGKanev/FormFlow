<?php

declare(strict_types=1);

namespace formflow;

interface AuditLogRepositoryInterface
{
    public function record(?string $username, string $action, string $detail): void;

    /** @return list<array<string, mixed>> */
    public function list(int $limit = 100): array;
}
