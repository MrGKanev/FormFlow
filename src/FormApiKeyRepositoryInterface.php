<?php

declare(strict_types=1);

namespace formflow;

interface FormApiKeyRepositoryInterface
{
    public function get(string $formId): ?string;

    public function regenerate(string $formId): string;

    /** @return array<string, array{api_key: string, created_at: string, updated_at: string}> */
    public function all(): array;
}
