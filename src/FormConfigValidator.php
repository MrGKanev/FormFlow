<?php

declare(strict_types=1);

namespace formflow;

use InvalidArgumentException;

final class FormConfigValidator
{
    private const CAPTCHA_PROVIDERS = ['none', 'turnstile', 'hcaptcha', 'recaptcha', 'friendlycaptcha'];
    private const NOTIFICATION_CHANNELS = ['discord', 'slack', 'telegram', 'generic'];
    private const WEBHOOK_OVERRIDE_FIELDS = [
        'discord_webhook_url',
        'slack_webhook_url',
        'generic_webhook_url',
        'telegram_bot_token',
        'telegram_chat_id',
    ];

    /**
     * @param array<string, mixed> $input
     * @return array{0: string, 1: array<string, mixed>}
     */
    public static function fromAdminInput(array $input, ?string $fixedFormId = null): array
    {
        $formId = $fixedFormId ?? trim((string) ($input['form_id'] ?? ''));
        self::assertFormId($formId);

        $recipient = trim((string) ($input['recipient'] ?? ''));
        self::assertRecipient($recipient);

        $allowedOrigins = self::lines((string) ($input['allowed_origins'] ?? ''));

        if ($allowedOrigins === []) {
            throw new InvalidArgumentException('Add at least one allowed origin.');
        }

        self::assertHttpUrls($allowedOrigins, 'Allowed origins must be valid http or https URLs.');

        $successRedirect = trim((string) ($input['success_redirect'] ?? ''));

        if ($successRedirect !== '' && !self::isHttpUrl($successRedirect)) {
            throw new InvalidArgumentException('Success redirect must be a valid http or https URL.');
        }

        $captchaProvider = (string) ($input['captcha_provider'] ?? 'none');

        if (!isset($input['captcha_provider']) && isset($input['turnstile'])) {
            $captchaProvider = 'turnstile';
        }

        self::assertCaptchaProvider($captchaProvider);

        $config = [
            'recipient' => $recipient,
            'allowed_origins' => $allowedOrigins,
            'subject' => self::defaultSubject((string) ($input['subject'] ?? '')),
            'captcha_provider' => $captchaProvider,
            'turnstile' => $captchaProvider === 'turnstile',
            'require_api_key' => isset($input['require_api_key']),
            'rate_limit_per_ip' => [
                'max' => max(1, (int) ($input['rate_limit_max'] ?? 5)),
                'window_minutes' => max(1, (int) ($input['rate_limit_window'] ?? 10)),
            ],
            'daily_limit' => max(1, (int) ($input['daily_limit'] ?? 200)),
            'notification_channels' => self::notificationChannels($input['notification_channels'] ?? []),
            'uploads' => self::uploadsFromAdminInput($input),
        ];

        if ($successRedirect !== '') {
            $config['success_redirect'] = $successRedirect;
        }

        $notificationOverrides = self::notificationOverrides($input);

        if ($notificationOverrides !== []) {
            $config['notification_overrides'] = $notificationOverrides;
        }

        $blockedPatterns = self::lines((string) ($input['blocked_patterns'] ?? ''));

        if ($blockedPatterns !== []) {
            $config['blocked_patterns'] = $blockedPatterns;
        }

        return [$formId, $config];
    }

    /** @param array<string, mixed> $config @return array<string, mixed> */
    public static function normalize(string $formId, array $config, bool $requireAllowedOrigins = false): array
    {
        self::assertFormId($formId);

        $recipient = trim((string) ($config['recipient'] ?? ''));
        self::assertRecipient($recipient);

        $allowedOrigins = self::stringList($config['allowed_origins'] ?? []);

        if ($requireAllowedOrigins && $allowedOrigins === []) {
            throw new InvalidArgumentException('Add at least one allowed origin.');
        }

        self::assertHttpUrls($allowedOrigins, 'Allowed origins must be valid http or https URLs.');

        $successRedirect = trim((string) ($config['success_redirect'] ?? ''));

        if ($successRedirect !== '' && !self::isHttpUrl($successRedirect)) {
            throw new InvalidArgumentException('Success redirect must be a valid http or https URL.');
        }

        $captchaProvider = trim((string) ($config['captcha_provider'] ?? ''));

        if ($captchaProvider === '' && ($config['turnstile'] ?? false) === true) {
            $captchaProvider = 'turnstile';
        }

        if ($captchaProvider === '') {
            $captchaProvider = 'none';
        }

        self::assertCaptchaProvider($captchaProvider);

        $rateLimit = is_array($config['rate_limit_per_ip'] ?? null) ? $config['rate_limit_per_ip'] : [];
        $normalized = [
            'recipient' => $recipient,
            'allowed_origins' => $allowedOrigins,
            'subject' => self::defaultSubject((string) ($config['subject'] ?? '')),
            'captcha_provider' => $captchaProvider,
            'turnstile' => $captchaProvider === 'turnstile',
            'require_api_key' => !empty($config['require_api_key']),
            'rate_limit_per_ip' => [
                'max' => max(1, (int) ($rateLimit['max'] ?? 5)),
                'window_minutes' => max(1, (int) ($rateLimit['window_minutes'] ?? 10)),
            ],
            'daily_limit' => max(1, (int) ($config['daily_limit'] ?? 200)),
            'notification_channels' => self::notificationChannels($config['notification_channels'] ?? []),
            'uploads' => self::uploadsFromConfig($config['uploads'] ?? []),
        ];

        if ($successRedirect !== '') {
            $normalized['success_redirect'] = $successRedirect;
        }

        $blockedPatterns = self::stringList($config['blocked_patterns'] ?? []);

        if ($blockedPatterns !== []) {
            $normalized['blocked_patterns'] = $blockedPatterns;
        }

        $notificationOverrides = self::notificationOverrides(
            is_array($config['notification_overrides'] ?? null) ? $config['notification_overrides'] : []
        );

        if ($notificationOverrides !== []) {
            $normalized['notification_overrides'] = $notificationOverrides;
        }

        return $normalized;
    }

    /** @return array<string, mixed> */
    private static function uploadsFromAdminInput(array $input): array
    {
        $allowedExtensions = array_map(
            static fn (string $extension): string => strtolower(ltrim($extension, '.')),
            self::lines((string) ($input['upload_allowed_extensions'] ?? ''))
        );

        self::assertAllowedExtensions($allowedExtensions);

        return [
            'max_file_size_mb' => min(100, max(1, (int) ($input['upload_max_file_size_mb'] ?? 10))),
            'max_files' => min(20, max(1, (int) ($input['upload_max_files'] ?? 3))),
            'allowed_extensions' => array_values(array_unique($allowedExtensions)),
        ];
    }

    /** @return array<string, mixed> */
    private static function uploadsFromConfig(mixed $uploads): array
    {
        $uploads = is_array($uploads) ? $uploads : [];
        $allowedExtensions = array_map(
            static fn (string $extension): string => strtolower(ltrim($extension, '.')),
            self::stringList($uploads['allowed_extensions'] ?? [])
        );

        self::assertAllowedExtensions($allowedExtensions);

        return [
            'max_file_size_mb' => min(100, max(1, (int) ($uploads['max_file_size_mb'] ?? 10))),
            'max_files' => min(20, max(1, (int) ($uploads['max_files'] ?? 3))),
            'allowed_extensions' => array_values(array_unique($allowedExtensions)),
        ];
    }

    /** @return list<string> */
    private static function notificationChannels(mixed $channels): array
    {
        $channels = is_array($channels) ? $channels : [];

        return array_values(array_unique(array_filter(
            array_map(static fn (mixed $channel): string => (string) $channel, $channels),
            static fn (string $channel): bool => in_array($channel, self::NOTIFICATION_CHANNELS, true)
        )));
    }

    /** @param array<string, mixed> $input @return array<string, string> */
    private static function notificationOverrides(array $input): array
    {
        $overrides = [];

        foreach (self::WEBHOOK_OVERRIDE_FIELDS as $field) {
            $value = trim((string) ($input[$field] ?? ''));
            self::assertSafeValue($field, $value);

            if ($value !== '') {
                $overrides[$field] = $value;
            }
        }

        foreach (['discord_webhook_url', 'slack_webhook_url', 'generic_webhook_url'] as $field) {
            if (isset($overrides[$field]) && !self::isHttpUrl($overrides[$field])) {
                throw new InvalidArgumentException('Per-form webhook URLs must be valid http or https URLs.');
            }
        }

        return $overrides;
    }

    private static function assertFormId(string $formId): void
    {
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{1,63}$/', $formId)) {
            throw new InvalidArgumentException('Form ID must be 2-64 lowercase letters, numbers, dashes, or underscores.');
        }
    }

    private static function assertRecipient(string $recipient): void
    {
        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Recipient must be a valid email address.');
        }
    }

    private static function assertCaptchaProvider(string $provider): void
    {
        if (!in_array($provider, self::CAPTCHA_PROVIDERS, true)) {
            throw new InvalidArgumentException('CAPTCHA provider must be a supported option.');
        }
    }

    /** @param list<string> $urls */
    private static function assertHttpUrls(array $urls, string $message): void
    {
        foreach ($urls as $url) {
            if (!self::isHttpUrl($url)) {
                throw new InvalidArgumentException($message);
            }
        }
    }

    /** @param list<string> $extensions */
    private static function assertAllowedExtensions(array $extensions): void
    {
        foreach ($extensions as $extension) {
            if (!preg_match('/^[a-z0-9]{1,16}$/', $extension)) {
                throw new InvalidArgumentException('Allowed file extensions must contain only letters and numbers.');
            }
        }
    }

    private static function assertSafeValue(string $field, string $value): void
    {
        if (str_contains($value, "'") || str_contains($value, "\n") || str_contains($value, "\r")) {
            throw new InvalidArgumentException("\"{$field}\" cannot contain a single-quote or a line break.");
        }
    }

    private static function defaultSubject(string $subject): string
    {
        $subject = trim($subject);

        return $subject !== '' ? $subject : 'New form submission';
    }

    /** @return list<string> */
    private static function stringList(mixed $value): array
    {
        if (is_string($value)) {
            return self::lines($value);
        }

        if (!is_array($value)) {
            return [];
        }

        $result = [];

        foreach ($value as $entry) {
            $entry = trim((string) $entry);

            if ($entry === '' || in_array($entry, $result, true)) {
                continue;
            }

            $result[] = $entry;
        }

        return $result;
    }

    /** @return list<string> */
    private static function lines(string $value): array
    {
        $lines = preg_split('/\R/', $value) ?: [];
        $result = [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || in_array($line, $result, true)) {
                continue;
            }

            $result[] = $line;
        }

        return $result;
    }

    private static function isHttpUrl(string $value): bool
    {
        if (!filter_var($value, FILTER_VALIDATE_URL)) {
            return false;
        }

        $scheme = parse_url($value, PHP_URL_SCHEME);

        return $scheme === 'http' || $scheme === 'https';
    }
}
