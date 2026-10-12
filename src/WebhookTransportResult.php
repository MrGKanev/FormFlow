<?php

declare(strict_types=1);

namespace formflow;

final readonly class WebhookTransportResult
{
    public function __construct(
        public ?string $error = null,
        public bool $retryable = true,
        public ?int $retryAfterSeconds = null
    ) {
    }
}
