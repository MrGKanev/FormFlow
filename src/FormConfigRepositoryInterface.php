<?php

declare(strict_types=1);

namespace formflow;

interface FormConfigRepositoryInterface
{
    /** @return array<string, array<string, mixed>> */
    public function all(): array;

    /** @param array<string, mixed> $config */
    public function create(string $formId, array $config): void;

    public function exists(string $formId): bool;
}
