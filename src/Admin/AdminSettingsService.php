<?php

declare(strict_types=1);

namespace formflow\Admin;

use InvalidArgumentException;

final class AdminSettingsService
{
    /** @param list<string> $configuredIps */
    public function __construct(
        private readonly array $configuredIps,
        private readonly ?string $envPath = null,
        private readonly ?string $adminConfigPath = null,
        private readonly ?string $securityConfigPath = null
    ) {
    }

    /** @return array<string, mixed> */
    public function currentSettings(): array
    {
        $env = $this->readEnvFile();
        $securityConfig = $this->securityConfig();

        return array_merge([
            'app_env' => $env['APP_ENV'] ?? (getenv('APP_ENV') ?: 'production'),
            'app_url' => $env['APP_URL'] ?? (getenv('APP_URL') ?: ''),
            'mailer_dsn' => $env['MAILER_DSN'] ?? (getenv('MAILER_DSN') ?: ''),
            'smtp_host' => $env['SMTP_HOST'] ?? (getenv('SMTP_HOST') ?: ''),
            'smtp_port' => $env['SMTP_PORT'] ?? (getenv('SMTP_PORT') ?: '587'),
            'smtp_encryption' => $env['SMTP_ENCRYPTION'] ?? (getenv('SMTP_ENCRYPTION') ?: 'tls'),
            'smtp_username' => $env['SMTP_USERNAME'] ?? (getenv('SMTP_USERNAME') ?: ''),
            'smtp_password' => $env['SMTP_PASSWORD'] ?? (getenv('SMTP_PASSWORD') ?: ''),
            'mail_from' => $env['MAIL_FROM'] ?? (getenv('MAIL_FROM') ?: ''),
            'mail_from_name' => $env['MAIL_FROM_NAME'] ?? (getenv('MAIL_FROM_NAME') ?: 'formflow'),
            'turnstile_secret' => $env['TURNSTILE_SECRET'] ?? (getenv('TURNSTILE_SECRET') ?: ''),
            'turnstile_site_key' => $env['TURNSTILE_SITE_KEY'] ?? (getenv('TURNSTILE_SITE_KEY') ?: ''),
            'hcaptcha_secret' => $env['HCAPTCHA_SECRET'] ?? (getenv('HCAPTCHA_SECRET') ?: ''),
            'hcaptcha_site_key' => $env['HCAPTCHA_SITE_KEY'] ?? (getenv('HCAPTCHA_SITE_KEY') ?: ''),
            'recaptcha_secret' => $env['RECAPTCHA_SECRET'] ?? (getenv('RECAPTCHA_SECRET') ?: ''),
            'recaptcha_site_key' => $env['RECAPTCHA_SITE_KEY'] ?? (getenv('RECAPTCHA_SITE_KEY') ?: ''),
            'friendly_captcha_api_key' => $env['FRIENDLY_CAPTCHA_API_KEY'] ?? (getenv('FRIENDLY_CAPTCHA_API_KEY') ?: ''),
            'friendly_captcha_site_key' => $env['FRIENDLY_CAPTCHA_SITE_KEY'] ?? (getenv('FRIENDLY_CAPTCHA_SITE_KEY') ?: ''),
            'discord_webhook_url' => $env['DISCORD_WEBHOOK_URL'] ?? (getenv('DISCORD_WEBHOOK_URL') ?: ''),
            'slack_webhook_url' => $env['SLACK_WEBHOOK_URL'] ?? (getenv('SLACK_WEBHOOK_URL') ?: ''),
            'generic_webhook_url' => $env['GENERIC_WEBHOOK_URL'] ?? (getenv('GENERIC_WEBHOOK_URL') ?: ''),
            'telegram_bot_token' => $env['TELEGRAM_BOT_TOKEN'] ?? (getenv('TELEGRAM_BOT_TOKEN') ?: ''),
            'telegram_chat_id' => $env['TELEGRAM_CHAT_ID'] ?? (getenv('TELEGRAM_CHAT_ID') ?: ''),
            'mail_delivery_mode' => $env['MAIL_DELIVERY_MODE'] ?? (getenv('MAIL_DELIVERY_MODE') ?: 'sync'),
            'webhook_delivery_mode' => $env['WEBHOOK_DELIVERY_MODE'] ?? (getenv('WEBHOOK_DELIVERY_MODE') ?: 'sync'),
            'database_path' => $env['DATABASE_PATH'] ?? (getenv('DATABASE_PATH') ?: 'storage/submissions.sqlite'),
            'ip_hash_secret' => $env['IP_HASH_SECRET'] ?? (getenv('IP_HASH_SECRET') ?: ''),
            'retention_days' => $env['RETENTION_DAYS'] ?? (getenv('RETENTION_DAYS') ?: '180'),
            'admin_totp_secret' => $env['ADMIN_TOTP_SECRET'] ?? (getenv('ADMIN_TOTP_SECRET') ?: ''),
            'recovery_token_hash' => $env['RECOVERY_TOKEN_HASH'] ?? (getenv('RECOVERY_TOKEN_HASH') ?: ''),
            'recovery_token_expires_at' => $env['RECOVERY_TOKEN_EXPIRES_AT'] ?? (getenv('RECOVERY_TOKEN_EXPIRES_AT') ?: ''),
            'admin_username' => $env['ADMIN_USERNAME'] ?? (getenv('ADMIN_USERNAME') ?: 'admin'),
            'login_rate_limit_max' => (string) 5,
            'login_rate_limit_window' => (string) 15,
            'blocked_ips' => implode(PHP_EOL, $securityConfig['blocked_ips'] ?? []),
            'trusted_proxies' => implode(PHP_EOL, $securityConfig['trusted_proxies'] ?? []),
            'trusted_ip_headers' => implode(PHP_EOL, $securityConfig['trusted_ip_headers'] ?? [
                'HTTP_CF_CONNECTING_IP',
                'HTTP_X_FORWARDED_FOR',
                'HTTP_X_REAL_IP',
            ]),
        ], $this->adminRateLimitSettings());
    }

    public function snapshot(): SettingsSnapshot
    {
        return new SettingsSnapshot($this->currentSettings());
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function settingsFromInput(array $input): array
    {
        $appEnv = (string) ($input['app_env'] ?? 'production');

        if (!in_array($appEnv, ['production', 'local', 'development', 'testing'], true)) {
            throw new InvalidArgumentException('APP_ENV must be production, local, development, or testing.');
        }

        $rawFields = [
            'app_url',
            'mailer_dsn',
            'smtp_host',
            'smtp_port',
            'smtp_encryption',
            'smtp_username',
            'smtp_password',
            'mail_from',
            'mail_from_name',
            'turnstile_secret',
            'turnstile_site_key',
            'hcaptcha_secret',
            'hcaptcha_site_key',
            'recaptcha_secret',
            'recaptcha_site_key',
            'friendly_captcha_api_key',
            'friendly_captcha_site_key',
            'discord_webhook_url',
            'slack_webhook_url',
            'generic_webhook_url',
            'telegram_bot_token',
            'telegram_chat_id',
            'mail_delivery_mode',
            'webhook_delivery_mode',
            'database_path',
            'ip_hash_secret',
            'retention_days',
            'admin_totp_secret',
            'admin_username',
        ];

        foreach ($rawFields as $field) {
            $this->assertSafeEnvValue($field, (string) ($input[$field] ?? ''));
        }

        $appUrl = trim((string) ($input['app_url'] ?? ''));

        if ($appUrl !== '' && !$this->isHttpUrl($appUrl)) {
            throw new InvalidArgumentException('App URL must be a valid http or https URL.');
        }

        foreach (['discord_webhook_url', 'slack_webhook_url', 'generic_webhook_url'] as $urlField) {
            $url = trim((string) ($input[$urlField] ?? ''));

            if ($url !== '' && !$this->isHttpUrl($url)) {
                throw new InvalidArgumentException('Webhook URLs must be valid http or https URLs.');
            }
        }

        $mailFrom = trim((string) ($input['mail_from'] ?? ''));

        if ($mailFrom !== '' && !filter_var($mailFrom, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('From address must be a valid email address.');
        }

        $smtpPortInput = trim((string) ($input['smtp_port'] ?? '587'));

        if ($smtpPortInput === '' || !ctype_digit($smtpPortInput) || (int) $smtpPortInput < 1 || (int) $smtpPortInput > 65535) {
            throw new InvalidArgumentException('SMTP port must be between 1 and 65535.');
        }

        $smtpPort = (int) $smtpPortInput;
        $smtpEncryption = strtolower(trim((string) ($input['smtp_encryption'] ?? 'tls')));

        if (!in_array($smtpEncryption, ['tls', 'ssl', 'none'], true)) {
            throw new InvalidArgumentException('SMTP encryption must be TLS, SSL, or None.');
        }

        $mailDeliveryMode = strtolower(trim((string) ($input['mail_delivery_mode'] ?? 'sync')));
        $webhookDeliveryMode = strtolower(trim((string) ($input['webhook_delivery_mode'] ?? 'sync')));

        if (!in_array($mailDeliveryMode, ['sync', 'queue'], true) || !in_array($webhookDeliveryMode, ['sync', 'queue'], true)) {
            throw new InvalidArgumentException('Delivery modes must be sync or queue.');
        }

        $databasePath = trim((string) ($input['database_path'] ?? ''));

        if ($databasePath === '') {
            throw new InvalidArgumentException('Database path is required.');
        }

        $ipHashSecret = trim((string) ($input['ip_hash_secret'] ?? ''));

        if (strlen($ipHashSecret) < 16) {
            throw new InvalidArgumentException('IP hash secret must be at least 16 characters.');
        }

        $retentionDays = max(1, (int) ($input['retention_days'] ?? 180));
        $adminUsername = trim((string) ($input['admin_username'] ?? ''));

        if ($adminUsername === '') {
            throw new InvalidArgumentException('Admin username is required.');
        }

        $newPassword = (string) ($input['admin_password'] ?? '');

        if ($newPassword !== '' && strlen($newPassword) < 8) {
            throw new InvalidArgumentException('New admin password must be at least 8 characters.');
        }

        return [
            'env' => [
                'APP_ENV' => $appEnv,
                'APP_URL' => $appUrl,
                'MAILER_DSN' => trim((string) ($input['mailer_dsn'] ?? '')),
                'SMTP_HOST' => trim((string) ($input['smtp_host'] ?? '')),
                'SMTP_PORT' => (string) $smtpPort,
                'SMTP_ENCRYPTION' => $smtpEncryption,
                'SMTP_USERNAME' => trim((string) ($input['smtp_username'] ?? '')),
                'SMTP_PASSWORD' => trim((string) ($input['smtp_password'] ?? '')),
                'MAIL_FROM' => $mailFrom,
                'MAIL_FROM_NAME' => trim((string) ($input['mail_from_name'] ?? '')),
                'TURNSTILE_SECRET' => trim((string) ($input['turnstile_secret'] ?? '')),
                'TURNSTILE_SITE_KEY' => trim((string) ($input['turnstile_site_key'] ?? '')),
                'HCAPTCHA_SECRET' => trim((string) ($input['hcaptcha_secret'] ?? '')),
                'HCAPTCHA_SITE_KEY' => trim((string) ($input['hcaptcha_site_key'] ?? '')),
                'RECAPTCHA_SECRET' => trim((string) ($input['recaptcha_secret'] ?? '')),
                'RECAPTCHA_SITE_KEY' => trim((string) ($input['recaptcha_site_key'] ?? '')),
                'FRIENDLY_CAPTCHA_API_KEY' => trim((string) ($input['friendly_captcha_api_key'] ?? '')),
                'FRIENDLY_CAPTCHA_SITE_KEY' => trim((string) ($input['friendly_captcha_site_key'] ?? '')),
                'DISCORD_WEBHOOK_URL' => trim((string) ($input['discord_webhook_url'] ?? '')),
                'SLACK_WEBHOOK_URL' => trim((string) ($input['slack_webhook_url'] ?? '')),
                'GENERIC_WEBHOOK_URL' => trim((string) ($input['generic_webhook_url'] ?? '')),
                'TELEGRAM_BOT_TOKEN' => trim((string) ($input['telegram_bot_token'] ?? '')),
                'TELEGRAM_CHAT_ID' => trim((string) ($input['telegram_chat_id'] ?? '')),
                'MAIL_DELIVERY_MODE' => $mailDeliveryMode,
                'WEBHOOK_DELIVERY_MODE' => $webhookDeliveryMode,
                'DATABASE_PATH' => $databasePath,
                'IP_HASH_SECRET' => $ipHashSecret,
                'RETENTION_DAYS' => (string) $retentionDays,
                'ADMIN_TOTP_SECRET' => trim((string) ($input['admin_totp_secret'] ?? '')),
                'ADMIN_USERNAME' => $adminUsername,
            ],
            'new_admin_password' => $newPassword,
            'login_rate_limit' => [
                'max' => max(1, (int) ($input['login_rate_limit_max'] ?? 5)),
                'window_minutes' => max(1, (int) ($input['login_rate_limit_window'] ?? 15)),
            ],
            'blocked_ips' => $this->validatedIpEntries((string) ($input['blocked_ips'] ?? '')),
            'trusted_proxies' => $this->validatedIpEntries((string) ($input['trusted_proxies'] ?? '')),
            'trusted_ip_headers' => $this->validatedTrustedHeaders((string) ($input['trusted_ip_headers'] ?? '')),
        ];
    }

    /** @param array<string, mixed> $settings */
    public function writeSettings(array $settings): void
    {
        $envUpdates = $settings['env'];

        if ($settings['new_admin_password'] !== '') {
            $envUpdates['ADMIN_PASSWORD_HASH'] = password_hash((string) $settings['new_admin_password'], PASSWORD_DEFAULT);
        }

        $this->writeEnvFile($envUpdates);
        $this->writeAdminConfig($settings['login_rate_limit']);
        $this->writeSecurityConfig(
            $settings['blocked_ips'],
            $settings['trusted_proxies'],
            $settings['trusted_ip_headers']
        );
    }

    /** @param array<string, mixed> $input @return array<string, string> */
    public function integrationSettingsFromInput(array $input): array
    {
        $fields = [
            'discord_webhook_url' => 'DISCORD_WEBHOOK_URL',
            'slack_webhook_url' => 'SLACK_WEBHOOK_URL',
            'generic_webhook_url' => 'GENERIC_WEBHOOK_URL',
            'telegram_bot_token' => 'TELEGRAM_BOT_TOKEN',
            'telegram_chat_id' => 'TELEGRAM_CHAT_ID',
        ];

        $values = [];

        foreach ($fields as $field => $envKey) {
            $value = trim((string) ($input[$field] ?? ''));
            $this->assertSafeEnvValue($field, $value);
            $values[$envKey] = $value;
        }

        foreach (['discord_webhook_url', 'slack_webhook_url', 'generic_webhook_url'] as $field) {
            $url = $values[$fields[$field]];

            if ($url !== '' && !$this->isHttpUrl($url)) {
                throw new InvalidArgumentException('Webhook URLs must be valid http or https URLs.');
            }
        }

        return $values;
    }

    /** @param array<string, string> $updates */
    public function writeEnvFile(array $updates): void
    {
        if ($this->envPath === null) {
            return;
        }

        $lines = is_file($this->envPath) ? file($this->envPath, FILE_IGNORE_NEW_LINES) : [];
        $lines = $lines === false ? [] : $lines;
        $written = [];

        foreach ($lines as $index => $line) {
            if (preg_match('/^\s*([A-Z0-9_]+)\s*=/', $line, $matches) !== 1) {
                continue;
            }

            $key = $matches[1];

            if (array_key_exists($key, $updates)) {
                $lines[$index] = $this->envLine($key, (string) $updates[$key]);
                $written[] = $key;
            }
        }

        foreach ($updates as $key => $value) {
            if (!in_array($key, $written, true)) {
                $lines[] = $this->envLine($key, (string) $value);
            }
        }

        $this->atomicWrite($this->envPath, implode(PHP_EOL, $lines) . PHP_EOL);
    }

    /** @return array<string, mixed> */
    public function securityConfig(): array
    {
        if ($this->securityConfigPath === null || !is_file($this->securityConfigPath)) {
            return ['blocked_ips' => []];
        }

        $config = require $this->securityConfigPath;

        return is_array($config) ? $config : [];
    }

    /**
     * @param array<string, mixed> $config
     * @return array{blocked_ips: list<string>, trusted_proxies: list<string>, trusted_ip_headers: list<string>}
     */
    public function securityFromConfig(array $config): array
    {
        return [
            'blocked_ips' => $this->validatedIpEntries(implode(PHP_EOL, $this->stringList($config['blocked_ips'] ?? []))),
            'trusted_proxies' => $this->validatedIpEntries(implode(PHP_EOL, $this->stringList($config['trusted_proxies'] ?? []))),
            'trusted_ip_headers' => $this->validatedTrustedHeaders(implode(PHP_EOL, $this->stringList($config['trusted_ip_headers'] ?? []))),
        ];
    }

    /**
     * @param list<string> $blockedIps
     * @param list<string>|null $trustedProxies
     * @param list<string>|null $trustedHeaders
     */
    public function writeSecurityConfig(array $blockedIps, ?array $trustedProxies = null, ?array $trustedHeaders = null): void
    {
        if ($this->securityConfigPath === null) {
            return;
        }

        $existing = $this->securityConfig();
        $trustedProxies ??= array_values(array_map('strval', $existing['trusted_proxies'] ?? []));
        $trustedHeaders ??= array_values(array_map('strval', $existing['trusted_ip_headers'] ?? [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
        ]));

        $content = "<?php\n\n";
        $content .= "declare(strict_types=1);\n\n";
        $content .= "return [\n";
        $content .= "    'blocked_ips' => [\n";

        foreach ($blockedIps as $ip) {
            $content .= "        '" . addslashes($ip) . "',\n";
        }

        $content .= "    ],\n";
        $content .= "\n";
        $content .= "    'trusted_proxies' => [\n";

        foreach ($trustedProxies as $ip) {
            $content .= "        '" . addslashes($ip) . "',\n";
        }

        $content .= "    ],\n";
        $content .= "\n";
        $content .= "    'trusted_ip_headers' => [\n";

        foreach ($trustedHeaders as $header) {
            $content .= "        '" . addslashes($header) . "',\n";
        }

        $content .= "    ],\n";
        $content .= "];\n";

        $this->atomicWrite($this->securityConfigPath, $content);

        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($this->securityConfigPath, true);
        }
    }

    private function assertSafeEnvValue(string $field, string $value): void
    {
        if (str_contains($value, "'") || str_contains($value, "\n") || str_contains($value, "\r")) {
            throw new InvalidArgumentException("\"{$field}\" cannot contain a single-quote or a line break.");
        }
    }

    /** @return array<string, string> */
    private function readEnvFile(): array
    {
        if ($this->envPath === null || !is_file($this->envPath)) {
            return [];
        }

        $lines = file($this->envPath, FILE_IGNORE_NEW_LINES);
        $values = [];

        foreach ($lines === false ? [] : $lines as $line) {
            if (preg_match('/^\s*([A-Z0-9_]+)\s*=\s*(.*)\s*$/', $line, $matches) !== 1) {
                continue;
            }

            $value = trim($matches[2]);

            if (
                strlen($value) >= 2
                && (($value[0] === "'" && substr($value, -1) === "'") || ($value[0] === '"' && substr($value, -1) === '"'))
            ) {
                $value = substr($value, 1, -1);
            }

            $values[$matches[1]] = $value;
        }

        return $values;
    }

    private function envLine(string $key, string $value): string
    {
        return $key . "='" . $value . "'";
    }

    /** @return array<string, mixed> */
    private function adminConfig(): array
    {
        if ($this->adminConfigPath === null || !is_file($this->adminConfigPath)) {
            return ['allowed_ips' => $this->configuredIps, 'login_rate_limit' => ['max' => 5, 'window_minutes' => 15]];
        }

        $config = require $this->adminConfigPath;

        return is_array($config) ? $config : [];
    }

    /** @return array<string, string> */
    private function adminRateLimitSettings(): array
    {
        $loginRateLimit = $this->adminConfig()['login_rate_limit'] ?? [];

        return [
            'login_rate_limit_max' => (string) (int) ($loginRateLimit['max'] ?? 5),
            'login_rate_limit_window' => (string) (int) ($loginRateLimit['window_minutes'] ?? 15),
        ];
    }

    /** @param array{max: int, window_minutes: int} $loginRateLimit */
    private function writeAdminConfig(array $loginRateLimit): void
    {
        if ($this->adminConfigPath === null) {
            return;
        }

        $config = $this->adminConfig();
        $allowedIps = $config['allowed_ips'] ?? $this->configuredIps;

        $content = "<?php\n\n";
        $content .= "declare(strict_types=1);\n\n";
        $content .= "return [\n";
        $content .= "    'allowed_ips' => [\n";

        foreach ($allowedIps as $ip) {
            $content .= "        '" . addslashes((string) $ip) . "',\n";
        }

        $content .= "    ],\n\n";
        $content .= "    'login_rate_limit' => [\n";
        $content .= "        'max' => " . (int) $loginRateLimit['max'] . ",\n";
        $content .= "        'window_minutes' => " . (int) $loginRateLimit['window_minutes'] . ",\n";
        $content .= "    ],\n";
        $content .= "];\n";

        $this->atomicWrite($this->adminConfigPath, $content);

        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($this->adminConfigPath, true);
        }
    }

    /** @return list<string> */
    private function validatedIpEntries(string $value): array
    {
        $entries = $this->lines($value);

        foreach ($entries as $entry) {
            if (str_contains($entry, '/')) {
                [$subnet, $prefix] = explode('/', $entry, 2);
                $maxPrefix = match (true) {
                    filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false => 32,
                    filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false => 128,
                    default => null,
                };

                if ($maxPrefix === null || !ctype_digit($prefix) || (int) $prefix > $maxPrefix) {
                    throw new InvalidArgumentException('Blocked IP entries must be exact IPs or CIDR ranges.');
                }

                continue;
            }

            if (filter_var($entry, FILTER_VALIDATE_IP) === false) {
                throw new InvalidArgumentException('Blocked IP entries must be exact IPs or CIDR ranges.');
            }
        }

        return $entries;
    }

    /** @return list<string> */
    private function validatedTrustedHeaders(string $value): array
    {
        $headers = $this->lines($value);
        $allowed = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
        ];

        foreach ($headers as $header) {
            if (!in_array($header, $allowed, true)) {
                throw new InvalidArgumentException('Trusted IP headers must be CF-Connecting-IP, X-Forwarded-For, or X-Real-IP server keys.');
            }
        }

        return $headers;
    }

    /** @return list<string> */
    private function lines(string $value): array
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

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if (is_string($value)) {
            return $this->lines($value);
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

    private function isHttpUrl(string $value): bool
    {
        if (!filter_var($value, FILTER_VALIDATE_URL)) {
            return false;
        }

        $scheme = parse_url($value, PHP_URL_SCHEME);

        return $scheme === 'http' || $scheme === 'https';
    }

    private function atomicWrite(string $path, string $content): void
    {
        $directory = dirname($path);
        $temporaryPath = tempnam($directory, basename($path) . '.tmp.');

        if ($temporaryPath === false) {
            throw new InvalidArgumentException('Unable to create a temporary config file.');
        }

        try {
            file_put_contents($temporaryPath, $content);
            rename($temporaryPath, $path);
        } finally {
            if (is_file($temporaryPath)) {
                unlink($temporaryPath);
            }
        }
    }
}
