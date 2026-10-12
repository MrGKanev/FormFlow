<?php

declare(strict_types=1);

namespace formflow\Admin;

use formflow\MailSenderInterface;
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

        try {
            $this->mailSender->send(
                (string) ($config['recipient'] ?? ''),
                (string) ($config['subject'] ?? 'New form submission'),
                SubmissionPayloadFormatter::displayFields($payload)
            );

            $this->submissions->markSent((int) $submission['id']);

            return null;
        } catch (Throwable $exception) {
            $this->submissions->markFailed((int) $submission['id'], $exception->getMessage());

            return 'Unable to resend email: ' . $exception->getMessage();
        }
    }
}
