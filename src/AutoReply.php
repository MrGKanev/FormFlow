<?php

declare(strict_types=1);

namespace formflow;

final class AutoReply
{
    /** @param array<string, mixed> $fields @return array{recipient: string, subject: string, body: string}|null */
    public static function message(string $formId, array $config, array $fields): ?array
    {
        $settings = is_array($config['auto_reply'] ?? null) ? $config['auto_reply'] : [];
        $recipient = trim((string) ($fields['email'] ?? ''));

        if (empty($settings['enabled']) || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        $values = ['form_id' => $formId];
        foreach (SubmissionPayloadFormatter::displayFields($fields) as $key => $value) {
            $values[(string) $key] = (string) $value;
        }

        $render = static function (string $template) use ($values): string {
            return (string) preg_replace_callback(
                '/\{\{\s*([a-zA-Z0-9_-]+)\s*\}\}/',
                static fn (array $matches): string => $values[$matches[1]] ?? '',
                $template
            );
        };

        return [
            'recipient' => $recipient,
            'subject' => $render((string) ($settings['subject'] ?? 'We received your message')),
            'body' => $render((string) ($settings['body'] ?? 'Thank you. We received your message and will reply soon.')),
        ];
    }

    /** @param array<string, mixed> $config @param array<string, mixed> $fields */
    public static function send(MailSenderInterface $sender, string $formId, array $config, array $fields): void
    {
        if (!$sender instanceof AutoReplySenderInterface) {
            return;
        }

        $message = self::message($formId, $config, $fields);

        if ($message !== null) {
            $sender->sendAutoReply($message['recipient'], $message['subject'], $message['body']);
        }
    }
}
