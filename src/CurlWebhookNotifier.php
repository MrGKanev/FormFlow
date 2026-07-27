<?php

declare(strict_types=1);

namespace formflow;

final class CurlWebhookNotifier implements WebhookNotifierInterface
{
    public function __construct(
        private readonly ?string $discordUrl,
        private readonly ?string $slackUrl,
        private readonly ?string $genericWebhookUrl = null,
        private readonly ?string $telegramBotToken = null,
        private readonly ?string $telegramChatId = null
    ) {
    }

    public function notify(string $formId, array $fields): void
    {
        $summary = $this->summary($formId, $fields);

        if ($this->discordUrl !== null && $this->discordUrl !== '') {
            $this->postJson($this->discordUrl, ['content' => $summary]);
        }

        if ($this->slackUrl !== null && $this->slackUrl !== '') {
            $this->postJson($this->slackUrl, ['text' => $summary]);
        }

        if ($this->genericWebhookUrl !== null && $this->genericWebhookUrl !== '') {
            $this->postJson($this->genericWebhookUrl, [
                'form_id' => $formId,
                'fields' => $fields,
            ]);
        }

        if (
            $this->telegramBotToken !== null
            && $this->telegramBotToken !== ''
            && $this->telegramChatId !== null
            && $this->telegramChatId !== ''
        ) {
            $this->postJson(
                'https://api.telegram.org/bot' . rawurlencode($this->telegramBotToken) . '/sendMessage',
                ['chat_id' => $this->telegramChatId, 'text' => $summary]
            );
        }
    }

    /** @param array<string, string> $fields */
    private function summary(string $formId, array $fields): string
    {
        $lines = ['New formflow submission: ' . $formId];

        foreach (array_slice($fields, 0, 8, true) as $key => $value) {
            $lines[] = ucfirst(str_replace('_', ' ', (string) $key)) . ': ' . mb_substr((string) $value, 0, 240);
        }

        return implode(PHP_EOL, $lines);
    }

    /** @param array<string, string> $payload */
    private function postJson(string $url, array $payload): void
    {
        if (!function_exists('curl_init')) {
            return;
        }

        $handle = curl_init($url);

        if ($handle === false) {
            return;
        }

        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
        ]);

        curl_exec($handle);
        curl_close($handle);
    }
}
