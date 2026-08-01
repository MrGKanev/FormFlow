<?php

declare(strict_types=1);

namespace formflow;

final class CurlWebhookNotifier implements WebhookNotifierInterface
{
    private const MAX_ATTEMPTS = 2;

    private readonly WebhookTransportInterface $transport;

    public function __construct(
        private readonly ?string $discordUrl,
        private readonly ?string $slackUrl,
        private readonly ?string $genericWebhookUrl = null,
        private readonly ?string $telegramBotToken = null,
        private readonly ?string $telegramChatId = null,
        private readonly ?WebhookDeliveryRepositoryInterface $deliveries = null,
        ?WebhookTransportInterface $transport = null,
        private readonly bool $defer = false
    ) {
        $this->transport = $transport ?? new CurlWebhookTransport();
    }

    public function notify(string $formId, array $fields, ?array $channels = null, array $overrides = []): void
    {
        $summary = $this->summary($formId, $fields);
        $selectedChannels = $channels === null ? null : array_flip($channels);
        $discordUrl = $this->overrideValue($overrides, 'discord_webhook_url') ?? $this->discordUrl;
        $slackUrl = $this->overrideValue($overrides, 'slack_webhook_url') ?? $this->slackUrl;
        $genericWebhookUrl = $this->overrideValue($overrides, 'generic_webhook_url') ?? $this->genericWebhookUrl;
        $telegramBotToken = $this->overrideValue($overrides, 'telegram_bot_token') ?? $this->telegramBotToken;
        $telegramChatId = $this->overrideValue($overrides, 'telegram_chat_id') ?? $this->telegramChatId;

        if ($this->isSelected('discord', $selectedChannels) && $this->hasValue($discordUrl)) {
            $this->deliver($formId, 'discord', $discordUrl, ['content' => $summary]);
        }

        if ($this->isSelected('slack', $selectedChannels) && $this->hasValue($slackUrl)) {
            $this->deliver($formId, 'slack', $slackUrl, ['text' => $summary]);
        }

        if ($this->isSelected('generic', $selectedChannels) && $this->hasValue($genericWebhookUrl)) {
            $this->deliver($formId, 'generic', $genericWebhookUrl, [
                'form_id' => $formId,
                'fields' => $fields,
            ]);
        }

        if (
            $this->isSelected('telegram', $selectedChannels)
            &&
            $telegramBotToken !== null
            && $telegramBotToken !== ''
            && $telegramChatId !== null
            && $telegramChatId !== ''
        ) {
            $this->deliver(
                $formId,
                'telegram',
                'https://api.telegram.org/bot' . rawurlencode($telegramBotToken) . '/sendMessage',
                ['chat_id' => $telegramChatId, 'text' => $summary]
            );
        }
    }

    /** @param array<string, mixed> $fields */
    private function summary(string $formId, array $fields): string
    {
        $lines = ['New formflow submission: ' . $formId];

        foreach (array_slice(SubmissionPayloadFormatter::displayFields($fields), 0, 8, true) as $key => $value) {
            $lines[] = ucfirst(str_replace('_', ' ', (string) $key)) . ': ' . mb_substr($value, 0, 240);
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

    /** @param array<string, mixed> $overrides */
    private function overrideValue(array $overrides, string $key): ?string
    {
        $value = trim((string) ($overrides[$key] ?? ''));

        return $value !== '' ? $value : null;
    }

    /** @param array<string, mixed> $payload */
    private function deliver(string $formId, string $channel, string $url, array $payload): void
    {
        if ($this->defer) {
            $this->deliveries?->enqueue($formId, $channel, $url, $payload);
            return;
        }

        $error = null;

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                $error = $this->transport->postJson($url, $payload);
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

}
