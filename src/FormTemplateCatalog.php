<?php

declare(strict_types=1);

namespace formflow;

final class FormTemplateCatalog
{
    /** @return array<string, array{label: string, description: string, values: array<string, mixed>}> */
    public static function all(): array
    {
        return [
            'contact' => [
                'label' => 'Contact form',
                'description' => 'Balanced defaults for general enquiries.',
                'values' => [
                    'subject' => 'New contact form message',
                    'rate_limit_max' => '5',
                    'rate_limit_window' => '10',
                    'daily_limit' => '200',
                    'auto_reply_enabled' => '1',
                    'auto_reply_subject' => 'Thanks for contacting us',
                    'auto_reply_body' => "Hi {{name}},\n\nThanks for your message. We received it and will get back to you soon.",
                ],
            ],
            'newsletter' => [
                'label' => 'Newsletter signup',
                'description' => 'High-volume signup endpoint with a confirmation reply.',
                'values' => [
                    'subject' => 'New newsletter signup',
                    'rate_limit_max' => '10',
                    'rate_limit_window' => '10',
                    'daily_limit' => '2000',
                    'auto_reply_enabled' => '1',
                    'auto_reply_subject' => 'You are on the list',
                    'auto_reply_body' => "Thanks for subscribing. We will send updates to {{email}}.",
                ],
            ],
            'support' => [
                'label' => 'Support request',
                'description' => 'Ticket-style message with attachment support.',
                'values' => [
                    'subject' => 'New support request',
                    'rate_limit_max' => '8',
                    'rate_limit_window' => '15',
                    'daily_limit' => '500',
                    'upload_max_file_size_mb' => '15',
                    'upload_max_files' => '5',
                    'uploads_enabled' => '1',
                    'upload_allowed_extensions' => "pdf\njpg\njpeg\npng\ntxt",
                    'auto_reply_enabled' => '1',
                    'auto_reply_subject' => 'We received your support request',
                    'auto_reply_body' => "Hi {{name}},\n\nYour request has been received. Our team will respond as soon as possible.",
                ],
            ],
            'application' => [
                'label' => 'Job application',
                'description' => 'Applicant details with résumé uploads.',
                'values' => [
                    'subject' => 'New job application',
                    'rate_limit_max' => '3',
                    'rate_limit_window' => '30',
                    'daily_limit' => '300',
                    'upload_max_file_size_mb' => '10',
                    'upload_max_files' => '2',
                    'uploads_enabled' => '1',
                    'upload_allowed_extensions' => "pdf\ndoc\ndocx",
                    'auto_reply_enabled' => '1',
                    'auto_reply_subject' => 'Application received',
                    'auto_reply_body' => "Hi {{name}},\n\nThank you for applying. We have received your application and will review it shortly.",
                ],
            ],
        ];
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public static function apply(array $input): array
    {
        if ((string) ($input['template_applied'] ?? '') === '1') {
            return $input;
        }

        $template = trim((string) ($input['template'] ?? ''));
        $definition = self::all()[$template] ?? null;

        if ($definition === null) {
            return $input;
        }

        foreach ($definition['values'] as $key => $value) {
            if (!array_key_exists($key, $input) || trim((string) $input[$key]) === '') {
                $input[$key] = $value;
            }
        }

        return $input;
    }
}
