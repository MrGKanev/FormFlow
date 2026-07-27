<?php

declare(strict_types=1);

namespace formflow;

interface WebhookDeliveryRepositoryInterface
{
    public function record(
        string $formId,
        string $channel,
        string $status,
        int $attempts,
        ?string $errorMessage = null
    ): void;

    /** @return list<array<string, mixed>> */
    public function deliveryLog(int $limit = 100): array;
}
