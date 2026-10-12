<?php

declare(strict_types=1);

namespace formflow;

use Throwable;

final class WebhookDeliveryWorker
{
    private const MAX_ATTEMPTS = 3;

    public function __construct(
        private readonly WebhookDeliveryRepositoryInterface $deliveries,
        private readonly WebhookTransportInterface $transport
    ) {
    }

    /** @return array{attempted: int, sent: int, failed: int, pending: int, skipped: int} */
    public function process(int $limit = 100): array
    {
        $summary = ['attempted' => 0, 'sent' => 0, 'failed' => 0, 'pending' => 0, 'skipped' => 0];

        foreach ($this->deliveries->due($limit) as $delivery) {
            // Atomic claim: if another (e.g. overlapping cron) worker run already
            // grabbed this row, skip it instead of dispatching a duplicate webhook.
            if ($this->deliveries instanceof ClaimedWebhookDeliveryRepositoryInterface) {
                $delivery = $this->deliveries->claimDue((int) $delivery['id']);
                $claimed = $delivery !== null;
            } else {
                $claimed = $this->deliveries->claim((int) $delivery['id']);
            }

            if (!$claimed) {
                $summary['skipped']++;
                continue;
            }

            $summary['attempted']++;
            $attempts = ((int) $delivery['attempts']) + 1;
            $decoded = json_decode((string) $delivery['payload_json'], true);
            $payload = $this->payload($decoded);

            if ($payload === null) {
                $this->deliveries->markQueuedFailed(
                    (int) $delivery['id'],
                    $attempts,
                    'Stored webhook payload is invalid.'
                );
                $summary['failed']++;
                continue;
            }

            try {
                // A manual replay creates a new row and thus a new delivery ID.
                // Automatic retries keep the ID so receivers can deduplicate.
                $deliveryId = isset($delivery['delivery_id']) ? (string) $delivery['delivery_id'] : 'ff-legacy-' . (string) $delivery['id'];
                $result = $this->transport instanceof DetailedWebhookTransportInterface
                    ? $this->transport->deliver((string) $delivery['url'], $payload, $deliveryId)
                    : new WebhookTransportResult($this->transport->postJson((string) $delivery['url'], $payload));
                $error = $result->error;
            } catch (Throwable $exception) {
                $error = $exception->getMessage();
                $result = new WebhookTransportResult($error);
            }

            if ($error === null) {
                $this->deliveries->markQueuedSent((int) $delivery['id'], $attempts);
                $summary['sent']++;
                continue;
            }

            if ($attempts >= self::MAX_ATTEMPTS || !$result->retryable) {
                $this->deliveries->markQueuedFailed((int) $delivery['id'], $attempts, $error);
                $summary['failed']++;
                continue;
            }

            $this->deliveries->markQueuedFailed((int) $delivery['id'], $attempts, $error, max($this->retryDelay($attempts), $result->retryAfterSeconds ?? 0));
            $summary['pending']++;
        }

        return $summary;
    }

    /** @return array<string, mixed>|null */
    private function payload(mixed $decoded): ?array
    {
        if (!is_array($decoded)) {
            return null;
        }

        $payload = [];
        foreach ($decoded as $key => $value) {
            if (!is_string($key)) {
                return null;
            }
            $payload[$key] = $value;
        }

        return $payload;
    }

    private function retryDelay(int $attempts): int
    {
        // Equal jitter keeps a minimum delay while spreading overlapping workers.
        $ceiling = min(3600, 60 * (2 ** min(6, max(0, $attempts - 1))));

        return random_int((int) ($ceiling / 2), $ceiling);
    }
}
