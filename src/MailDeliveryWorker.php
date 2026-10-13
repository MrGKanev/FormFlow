<?php

declare(strict_types=1);

namespace formflow;

use Throwable;

final class MailDeliveryWorker
{
    private readonly MailRetryPolicy $retryPolicy;

    /** @param array<string, array<string, mixed>> $forms */
    public function __construct(
        private readonly array $forms,
        private readonly MailSenderInterface $mailSender,
        private readonly MailRetryRepositoryInterface $submissions,
        ?MailRetryPolicy $retryPolicy = null
    ) {
        $this->retryPolicy = $retryPolicy ?? new MailRetryPolicy();
    }

    /** @return array{attempted: int, sent: int, failed: int, pending: int, skipped: int} */
    public function process(int $limit = 100, bool $includeFailed = true): array
    {
        return $this->deliver($this->submissions->findDueMail($limit, $includeFailed, $this->retryPolicy->maxAttempts));
    }

    /**
     * An explicit retry starts a new budget for failed mail, including terminal
     * failures, without picking up fresh pending mail or ignoring live locks.
     * @return array{attempted: int, sent: int, failed: int, pending: int, skipped: int}
     */
    public function retryFailed(int $limit = 100): array
    {
        return $this->deliver($this->submissions->findFailed($limit), true);
    }

    /**
     * @param list<array<string, mixed>> $submissions
     * @return array{attempted: int, sent: int, failed: int, pending: int, skipped: int}
     */
    private function deliver(array $submissions, bool $forceRetry = false): array
    {
        $summary = ['attempted' => 0, 'sent' => 0, 'failed' => 0, 'pending' => 0, 'skipped' => 0];

        foreach ($submissions as $candidate) {
            $submission = $this->submissions->claimMail(
                (int) $candidate['id'], (string) $candidate['status'],
                $this->retryPolicy->maxAttempts, $forceRetry
            );
            if ($submission === null) {
                $summary['skipped']++;
                continue;
            }
            $summary['attempted']++;
            $submissionId = (int) $submission['id'];
            $attempts = (int) $submission['mail_attempts'];
            $formId = (string) $submission['form_id'];
            $config = $this->forms[$formId] ?? null;
            $payload = json_decode((string) $submission['payload'], true);

            if (!is_array($config) || !is_string($config['recipient'] ?? null)
                || filter_var($config['recipient'], FILTER_VALIDATE_EMAIL) === false || !is_array($payload)) {
                $error = !is_array($payload)
                    ? 'Stored submission payload is invalid.'
                    : 'Submission form is missing or has no recipient configured.';
                $this->submissions->markMailFailed($submissionId, $error, null);
                $summary['failed']++;
                continue;
            }

            // Keep bookkeeping failures separate from transport failures: a sent
            // message must not be reclassified as an SMTP failure by this catch.
            try {
                $this->mailSender->send(
                    (string) $config['recipient'],
                    (string) ($config['subject'] ?? 'New form submission'),
                    SubmissionPayloadFormatter::displayFields($payload)
                );
            } catch (Throwable $exception) {
                $delay = $attempts < $this->retryPolicy->maxAttempts ? $this->retryPolicy->delay($attempts) : null;
                $this->submissions->markMailFailed($submissionId, $exception->getMessage(), $delay);
                $summary[$delay === null ? 'failed' : 'pending']++;
                continue;
            }
            $this->submissions->markSent($submissionId);
            try {
                AutoReply::send($this->mailSender, $formId, $config, $payload);
            } catch (Throwable) {
            }
            $summary['sent']++;
        }
        return $summary;
    }
}
