<?php

declare(strict_types=1);

namespace formflow;

interface AdminWhitelistRepositoryInterface
{
    public function add(string $ipOrCidr, ?string $note): void;

    public function remove(int $id): void;

    /** @return list<array{id: int, ip_or_cidr: string, note: ?string, created_at: string}> */
    public function list(): array;
}
