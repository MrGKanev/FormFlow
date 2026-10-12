<?php

declare(strict_types=1);

namespace formflow;

interface ClaimedWebhookDeliveryRepositoryInterface extends WebhookDeliveryRepositoryInterface
{
    /** @return array<string, mixed>|null Fresh row after an atomic due claim. */
    public function claimDue(int $id): ?array;
}
