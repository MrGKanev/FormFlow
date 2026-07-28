<?php

declare(strict_types=1);

namespace formflow;

use Throwable;

final class MailDeliveryWorker
{
    /**
     * @param array<string, array<string, mixed>> $forms
     */
    public function __construct(
        private readonly array $forms,
        private readonly MailSenderInterface $mailSender,
        private readonly SubmissionRepositoryInterface $submissions
    ) {
    }

    /** @return array{attempted: int, sent: int, failed: int, skipped: int} */
    public function process(int $limit = 100, bool $includeFailed = true): array
    {
        $summary = ['attempted' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0];

        foreach ($this->submissions->findPendingMail($limit, $includeFailed) as $submission) {
            $formId = (string) $submission['form_id'];
            $config = $this->forms[$formId] ?? null;
            $payload = json_decode((string) $submission['payload'], true);

            if (!is_array($config) || !isset($config['recipient']) || !is_array($payload)) {
                $summary['skipped']++;
                continue;
            }

            $summary['attempted']++;

            try {
                $this->mailSender->send(
                    (string) $config['recipient'],
                    (string) ($config['subject'] ?? 'New form submission'),
                    array_map('strval', $payload)
                );
                $this->submissions->markSent((int) $submission['id']);
                $summary['sent']++;
            } catch (Throwable $exception) {
                $this->submissions->markFailed((int) $submission['id'], $exception->getMessage());
                $summary['failed']++;
            }
        }

        return $summary;
    }
}
