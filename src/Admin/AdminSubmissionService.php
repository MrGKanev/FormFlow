<?php

declare(strict_types=1);

namespace formflow\Admin;

use formflow\MailSenderInterface;
use formflow\MailRetryRepositoryInterface;
use formflow\MailRetryPolicy;
use formflow\SubmissionPayloadFormatter;
use formflow\SubmissionRepositoryInterface;
use Throwable;

final class AdminSubmissionService
{
    /** @param array<string, array<string, mixed>> $forms */
    public function __construct(
        private readonly SubmissionRepositoryInterface $submissions,
        private readonly ?MailSenderInterface $mailSender,
        private readonly array $forms
    ) {
    }

    /**
     * @param mixed $value
     * @return list<int>
     */
    public function selectedIds(mixed $value): array
    {
        $ids = is_array($value) ? $value : [$value];

        $selected = [];

        foreach ($ids as $id) {
            if (!is_int($id) && !is_string($id)) {
                continue;
            }

            $validated = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

            if ($validated !== false) {
                $selected[] = $validated;
            }
        }

        return array_values(array_unique($selected));
    }

    /** @param array<string, mixed> $submission */
    public function resend(array $submission): ?string
    {
        if ($this->mailSender === null) {
            return 'Mail service is not available.';
        }

        $formId = (string) $submission['form_id'];
        $config = $this->forms[$formId] ?? null;

        if (!is_array($config)) {
            return 'Form configuration was not found.';
        }

        $payload = json_decode((string) $submission['payload'], true);

        if (!is_array($payload)) {
            return 'Submission payload is invalid.';
        }

        $policy = MailRetryPolicy::fromEnvironment();
        $id = (int) $submission['id'];
        $status = (string) $submission['status'];

        if ($this->submissions instanceof MailRetryRepositoryInterface && in_array($status, ['pending_mail', 'failed'], true)) {
            $claimed = $this->submissions->claimMail($id, $status, $policy->maxAttempts, true);
        } else {
            $claimed = $this->submissions->claim($id, $status) ? $this->submissions->find($id) : null;
        }

        if ($claimed === null) {
            return 'This submission is already being delivered or its delivery status changed. Please refresh and try again.';
        }

        // Use the claimed row so edits or status changes cannot leave us sending a stale payload.
        $config = $this->forms[(string) $claimed['form_id']] ?? null;
        $payload = json_decode((string) $claimed['payload'], true);

        if (!is_array($config) || !is_array($payload)) {
            $error = !is_array($config) ? 'Form configuration was not found.' : 'Submission payload is invalid.';
            if ($this->submissions instanceof MailRetryRepositoryInterface) {
                $this->submissions->markMailFailed($id, $error, null);
            } else {
                $this->submissions->markFailed($id, $error);
            }

            return $error;
        }

        try {
            $this->mailSender->send(
                (string) ($config['recipient'] ?? ''),
                (string) ($config['subject'] ?? 'New form submission'),
                SubmissionPayloadFormatter::displayFields($payload)
            );

        } catch (Throwable $exception) {
            if ($this->submissions instanceof MailRetryRepositoryInterface) {
                $attempts = (int) ($claimed['mail_attempts'] ?? 1);
                $this->submissions->markMailFailed(
                    $id,
                    $exception->getMessage(),
                    $attempts < $policy->maxAttempts ? $policy->delay($attempts) : null
                );
            } else {
                $this->submissions->markFailed($id, $exception->getMessage());
            }

            return 'Unable to resend email: ' . $exception->getMessage();
        }

        $this->submissions->markSent($id);

        return null;
    }
}
