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
        return $this->deliver($this->submissions->findPendingMail($limit, $includeFailed));
    }

    /** Retries only submissions already marked 'failed', without picking up fresh pending mail. */
    public function retryFailed(int $limit = 100): array
    {
        return $this->deliver($this->submissions->findFailed($limit));
    }

    /**
     * @param list<array<string, mixed>> $submissions
     * @return array{attempted: int, sent: int, failed: int, skipped: int}
     */
    private function deliver(array $submissions): array
    {
        $summary = ['attempted' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0];

        foreach ($submissions as $submission) {
            $formId = (string) $submission['form_id'];
            $config = $this->forms[$formId] ?? null;
            $payload = json_decode((string) $submission['payload'], true);

            if (!is_array($config) || !isset($config['recipient']) || !is_array($payload)) {
                $summary['skipped']++;
                continue;
            }

            $submissionId = (int) $submission['id'];

            // Atomic claim: if another (e.g. overlapping cron) worker run already
            // grabbed this row, skip it instead of sending a duplicate email.
            if (!$this->submissions->claim($submissionId, (string) $submission['status'])) {
                $summary['skipped']++;
                continue;
            }

            $summary['attempted']++;

            try {
                $this->mailSender->send(
                    (string) $config['recipient'],
                    (string) ($config['subject'] ?? 'New form submission'),
                    SubmissionPayloadFormatter::displayFields($payload)
                );
                $this->submissions->markSent($submissionId);

                try {
                    AutoReply::send($this->mailSender, $formId, $config, $payload);
                } catch (Throwable) {
                }
                $summary['sent']++;
            } catch (Throwable $exception) {
                $this->submissions->markFailed($submissionId, $exception->getMessage());
                $summary['failed']++;
            }
        }

        return $summary;
    }
}
