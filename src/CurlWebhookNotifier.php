<?php

declare(strict_types=1);

namespace formflow;

final class CurlWebhookNotifier implements WebhookNotifierInterface
{
    private const MAX_ATTEMPTS = 3;

    public function __construct(
        private readonly ?string $discordUrl,
        private readonly ?string $slackUrl,
        private readonly ?string $genericWebhookUrl = null,
        private readonly ?string $telegramBotToken = null,
        private readonly ?string $telegramChatId = null,
        private readonly ?WebhookDeliveryRepositoryInterface $deliveries = null
    ) {
    }

    public function notify(string $formId, array $fields, ?array $channels = null): void
    {
        $summary = $this->summary($formId, $fields);
        $selectedChannels = $channels === null ? null : array_flip($channels);

        if ($this->isSelected('discord', $selectedChannels) && $this->hasValue($this->discordUrl)) {
            $this->deliver($formId, 'discord', $this->discordUrl, ['content' => $summary]);
        }

        if ($this->isSelected('slack', $selectedChannels) && $this->hasValue($this->slackUrl)) {
            $this->deliver($formId, 'slack', $this->slackUrl, ['text' => $summary]);
        }

        if ($this->isSelected('generic', $selectedChannels) && $this->hasValue($this->genericWebhookUrl)) {
            $this->deliver($formId, 'generic', $this->genericWebhookUrl, [
                'form_id' => $formId,
                'fields' => $fields,
            ]);
        }

        if (
            $this->isSelected('telegram', $selectedChannels)
            &&
            $this->telegramBotToken !== null
            && $this->telegramBotToken !== ''
            && $this->telegramChatId !== null
            && $this->telegramChatId !== ''
        ) {
            $this->deliver(
                $formId,
                'telegram',
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

    /** @param array<string, true>|null $selectedChannels */
    private function isSelected(string $channel, ?array $selectedChannels): bool
    {
        return $selectedChannels === null || isset($selectedChannels[$channel]);
    }

    private function hasValue(?string $value): bool
    {
        return $value !== null && $value !== '';
    }

    /** @param array<string, string|array<string, string>> $payload */
    private function deliver(string $formId, string $channel, string $url, array $payload): void
    {
        $error = null;

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                $error = $this->postJson($url, $payload);
            } catch (\Throwable $exception) {
                $error = $exception->getMessage();
            }

            if ($error === null) {
                $this->deliveries?->record($formId, $channel, 'sent', $attempt);
                return;
            }

            if ($attempt < self::MAX_ATTEMPTS) {
                usleep($attempt * 200000);
            }
        }

        $this->deliveries?->record($formId, $channel, 'failed', self::MAX_ATTEMPTS, $error);
    }

    /** @param array<string, string|array<string, string>> $payload */
    private function postJson(string $url, array $payload): ?string
    {
        if (!function_exists('curl_init')) {
            return 'cURL is unavailable.';
        }

        $handle = curl_init($url);

        if ($handle === false) {
            return 'Unable to initialize the webhook request.';
        }

        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => 5,
        ]);

        $result = curl_exec($handle);
        $error = curl_error($handle);
        $statusCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        if ($result === false) {
            return $error !== '' ? $error : 'Webhook request failed.';
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            return 'Webhook returned HTTP ' . $statusCode . '.';
        }

        return null;
    }
}
