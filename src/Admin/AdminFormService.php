<?php

declare(strict_types=1);

namespace formflow\Admin;

use formflow\FormApiKeyRepositoryInterface;
use formflow\FormConfigRepositoryInterface;
use formflow\FormConfigValidator;
use InvalidArgumentException;

final class AdminFormService
{
    /** @param array<string, array<string, mixed>> $forms */
    public function __construct(
        private readonly array $forms,
        private readonly FormConfigRepositoryInterface $formRepository,
        private readonly FormApiKeyRepositoryInterface $apiKeys
    ) {
    }

    public function create(array $input): string
    {
        [$formId, $config] = FormConfigValidator::fromAdminInput($input);

        if (isset($this->forms[$formId]) || $this->formRepository->exists($formId)) {
            throw new InvalidArgumentException('A form with this ID already exists.');
        }

        $this->formRepository->create($formId, $config);
        $this->apiKeys->regenerate($formId);

        return $formId;
    }

    public function update(string $formId, array $input): void
    {
        [, $config] = FormConfigValidator::fromAdminInput($input, $formId);
        $this->formRepository->update($formId, $config);
    }

    public function delete(string $formId): void
    {
        $this->formRepository->delete($formId);
    }

    /** @return list<string> */
    public function dynamicFormIds(): array
    {
        return array_keys($this->formRepository->all());
    }

    /** @param array<string, mixed> $config @return array<string, mixed> */
    public function valuesFromConfig(string $formId, array $config): array
    {
        return [
            'form_id' => $formId,
            'recipient' => (string) ($config['recipient'] ?? ''),
            'allowed_origins' => implode(PHP_EOL, $config['allowed_origins'] ?? []),
            'subject' => (string) ($config['subject'] ?? ''),
            'success_redirect' => (string) ($config['success_redirect'] ?? ''),
            'rate_limit_max' => (string) (int) (($config['rate_limit_per_ip']['max'] ?? 5)),
            'rate_limit_window' => (string) (int) (($config['rate_limit_per_ip']['window_minutes'] ?? 10)),
            'daily_limit' => (string) (int) ($config['daily_limit'] ?? 200),
            'captcha_provider' => (string) ($config['captcha_provider'] ?? 'none'),
            'require_api_key' => !empty($config['require_api_key']) ? '1' : '',
            'blocked_patterns' => implode(PHP_EOL, $config['blocked_patterns'] ?? []),
            'upload_max_file_size_mb' => (string) (int) ($config['uploads']['max_file_size_mb'] ?? 10),
            'upload_max_files' => (string) (int) ($config['uploads']['max_files'] ?? 3),
            'upload_allowed_extensions' => implode(PHP_EOL, $config['uploads']['allowed_extensions'] ?? []),
            'delivery_channels' => is_array($config['delivery_channels'] ?? null)
                ? $config['delivery_channels']
                : [],
            'discord_webhook_url' => (string) ($config['notification_overrides']['discord_webhook_url'] ?? ''),
            'slack_webhook_url' => (string) ($config['notification_overrides']['slack_webhook_url'] ?? ''),
            'generic_webhook_url' => (string) ($config['notification_overrides']['generic_webhook_url'] ?? ''),
            'telegram_bot_token' => (string) ($config['notification_overrides']['telegram_bot_token'] ?? ''),
            'telegram_chat_id' => (string) ($config['notification_overrides']['telegram_chat_id'] ?? ''),
        ];
    }
}
