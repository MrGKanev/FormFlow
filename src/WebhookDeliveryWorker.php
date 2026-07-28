<?php

declare(strict_types=1);

namespace formflow;

final class WebhookDeliveryWorker
{
    private const MAX_ATTEMPTS = 3;

    public function __construct(
        private readonly WebhookDeliveryRepositoryInterface $deliveries,
        private readonly WebhookTransportInterface $transport
    ) {
    }

    /** @return array{attempted: int, sent: int, failed: int, pending: int} */
    public function process(int $limit = 100): array
    {
        $summary = ['attempted' => 0, 'sent' => 0, 'failed' => 0, 'pending' => 0];

        foreach ($this->deliveries->due($limit) as $delivery) {
            $summary['attempted']++;
            $attempts = ((int) $delivery['attempts']) + 1;
            $payload = json_decode((string) $delivery['payload_json'], true);

            if (!is_array($payload)) {
                $this->deliveries->markQueuedFailed(
                    (int) $delivery['id'],
                    $attempts,
                    'Stored webhook payload is invalid.'
                );
                $summary['failed']++;
                continue;
            }

            $error = $this->transport->postJson((string) $delivery['url'], $payload);

            if ($error === null) {
                $this->deliveries->markQueuedSent((int) $delivery['id'], $attempts);
                $summary['sent']++;
                continue;
            }

            if ($attempts >= self::MAX_ATTEMPTS) {
                $this->deliveries->markQueuedFailed((int) $delivery['id'], $attempts, $error);
                $summary['failed']++;
                continue;
            }

            $this->deliveries->markQueuedFailed((int) $delivery['id'], $attempts, $error, $attempts * 60);
            $summary['pending']++;
        }

        return $summary;
    }
}
