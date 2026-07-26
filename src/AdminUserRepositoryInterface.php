<?php

declare(strict_types=1);

namespace formflow;

interface AdminUserRepositoryInterface
{
    /** @return array{id: int, username: string, password_hash: string, created_at: string}|null */
    public function findByUsername(string $username): ?array;

    /** @return list<array{id: int, username: string, created_at: string}> */
    public function list(): array;

    public function create(string $username, string $passwordHash): void;

    public function delete(int $id): void;
}
