<?php

declare(strict_types=1);

namespace formflow;

use RuntimeException;

/** @phpstan-import-type DeliveryStatus from DeliveryMonitor */
final class DeliveryAlertNotifier
{
    public function __construct(private readonly MailSenderInterface $sender, private readonly string $statePath, private readonly int $repeatAfterSeconds = 3600)
    {
    }

    /** @param DeliveryStatus $status Returns true only when a notification was sent. */
    public function notify(array $status, string $recipient, ?int $now = null): bool
    {
        if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('DELIVERY_ALERT_EMAIL must be a valid email address.');
        }
        $now ??= Clock::nowTimestamp();
        $directory = dirname($this->statePath);
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create alert state directory.');
        }
        $handle = fopen($this->statePath, 'c+');
        if ($handle === false) {
            throw new RuntimeException('Unable to open delivery alert state.');
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('Unable to lock delivery alert state.');
            }
            $state = json_decode((string) stream_get_contents($handle), true);
            $state = is_array($state) ? $state : [];
            $fingerprint = hash('sha256', json_encode([$recipient, $status['alerts']], JSON_THROW_ON_ERROR));
            $lastSent = is_int($state['sent_at'] ?? null) ? $state['sent_at'] : 0;
            if ($status['healthy'] && ($state['healthy'] ?? true) === true) {
                return false;
            }
            if (!$status['healthy'] && ($state['fingerprint'] ?? null) === $fingerprint && $now >= $lastSent && $now - $lastSent < max(1, $this->repeatAfterSeconds)) {
                return false;
            }
            $this->sender->send($recipient, $status['healthy'] ? 'FormFlow deliveries recovered' : 'FormFlow delivery alert', [
                'checked_at' => $status['checked_at'],
                'status' => $status['healthy'] ? 'Delivery queues recovered.' : implode(PHP_EOL, $status['alerts']),
            ]);
            $content = json_encode(['fingerprint' => $fingerprint, 'sent_at' => $now, 'healthy' => $status['healthy']], JSON_THROW_ON_ERROR);
            rewind($handle);
            if (!ftruncate($handle, 0) || fwrite($handle, $content) !== strlen($content) || !fflush($handle)) {
                throw new RuntimeException('Unable to save delivery alert state.');
            }
            return true;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
