<?php
/** @var string|null $error */
/** @var string $csrfToken */
/** @var array<string, mixed> $values */
/** @var array<string, mixed> $integrationSettings */
$value = static fn (string $key, string $default = ''): string => htmlspecialchars(
    (string) ($values[$key] ?? $default),
    ENT_QUOTES,
    'UTF-8'
);
$turnstileConfigured = (string) ($integrationSettings['turnstile_secret'] ?? '') !== ''
    && (string) ($integrationSettings['turnstile_site_key'] ?? '') !== '';
$captchaProvider = (string) ($values['captcha_provider'] ?? ($values === [] && $turnstileConfigured ? 'turnstile' : 'none'));
$captchaSelected = static fn (string $provider): string => $captchaProvider === $provider ? ' selected' : '';
$notificationChannels = is_array($values['notification_channels'] ?? null)
    ? $values['notification_channels']
    : [];
$globalStatus = static fn (string $key): string => (string) ($integrationSettings[$key] ?? '') !== ''
    ? 'global configured'
    : 'no global default';
?>
<div class="page-header">
    <div>
        <p class="page-kicker">Configuration</p>
        <h1>New form</h1>
        <p class="page-meta">Create a new endpoint that accepts submitted form fields.</p>
    </div>
    <div class="header-actions">
        <a href="/admin/forms" class="button secondary">Back to forms</a>
    </div>
</div>

<?php if ($error !== null): ?>
    <div class="banner error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<form method="POST" action="/admin/forms/new">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
    <div class="form-grid">
        <label>
            <span>Form ID</span>
            <input type="text" name="form_id" placeholder="newsletter" value="<?= $value('form_id') ?>" required pattern="[a-z0-9][a-z0-9_-]{1,63}">
        </label>
        <label>
            <span>Recipient email</span>
            <input type="email" name="recipient" placeholder="hello@example.com" value="<?= $value('recipient') ?>" required>
        </label>
        <label class="span-2">
            <span>Allowed origins</span>
            <textarea name="allowed_origins" rows="3" placeholder="https://example.com" required><?= $value('allowed_origins') ?></textarea>
        </label>
        <label>
            <span>Email subject</span>
            <input type="text" name="subject" placeholder="New form submission" value="<?= $value('subject') ?>">
        </label>
        <label>
            <span>Success redirect</span>
            <input type="url" name="success_redirect" placeholder="https://example.com/thank-you" value="<?= $value('success_redirect') ?>">
        </label>
        <label>
            <span>Per-IP max</span>
            <input type="number" name="rate_limit_max" min="1" value="<?= $value('rate_limit_max', '5') ?>">
        </label>
        <label>
            <span>Window minutes</span>
            <input type="number" name="rate_limit_window" min="1" value="<?= $value('rate_limit_window', '10') ?>">
        </label>
        <label>
            <span>Daily limit</span>
            <input type="number" name="daily_limit" min="1" value="<?= $value('daily_limit', '200') ?>">
        </label>
        <label>
            <span>CAPTCHA provider</span>
            <select name="captcha_provider">
                <option value="none"<?= $captchaSelected('none') ?>>None</option>
                <option value="turnstile"<?= $captchaSelected('turnstile') ?>>Cloudflare Turnstile</option>
                <option value="hcaptcha"<?= $captchaSelected('hcaptcha') ?>>hCaptcha</option>
                <option value="recaptcha"<?= $captchaSelected('recaptcha') ?>>Google reCAPTCHA v2</option>
                <option value="friendlycaptcha"<?= $captchaSelected('friendlycaptcha') ?>>Friendly Captcha</option>
            </select>
        </label>
        <label class="checkbox-label">
            <input type="checkbox" name="require_api_key" value="1"<?= $values === [] || !empty($values['require_api_key']) ? ' checked' : '' ?>>
            <span>Require API key</span>
        </label>
        <label class="span-2">
            <span>Blocked patterns</span>
            <textarea name="blocked_patterns" rows="3" placeholder="viagra&#10;&lt;a href="><?= $value('blocked_patterns') ?></textarea>
        </label>
        <label>
            <span>Max upload size (MB)</span>
            <input type="number" name="upload_max_file_size_mb" min="1" max="100" value="<?= $value('upload_max_file_size_mb', '10') ?>">
        </label>
        <label>
            <span>Max uploaded files</span>
            <input type="number" name="upload_max_files" min="1" max="20" value="<?= $value('upload_max_files', '3') ?>">
        </label>
        <label class="span-2">
            <span>Allowed file extensions</span>
            <textarea name="upload_allowed_extensions" rows="3" placeholder="pdf&#10;jpg&#10;png"><?= $value('upload_allowed_extensions') ?></textarea>
        </label>
        <fieldset class="field-picker span-2">
            <legend>Send notifications to</legend>
            <p class="muted">Only enabled integrations are used. Configure their endpoints in Settings → Integrations.</p>
            <div class="option-grid">
                <?php foreach (['discord' => 'Discord', 'slack' => 'Slack', 'telegram' => 'Telegram', 'generic' => 'Generic webhook'] as $channel => $label): ?>
                    <label class="checkbox-label option-check">
                        <input type="checkbox" name="notification_channels[]" value="<?= $channel ?>"<?= in_array($channel, $notificationChannels, true) ? ' checked' : '' ?>>
                        <span><?= $label ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
        </fieldset>
        <fieldset class="field-picker span-2">
            <legend>Per-form integration overrides</legend>
            <p class="muted">Leave blank to use the global endpoint for a selected channel.</p>
            <div class="form-grid">
                <label>
                    <span>Discord webhook URL <small><?= $globalStatus('discord_webhook_url') ?></small></span>
                    <input type="url" name="discord_webhook_url" value="<?= $value('discord_webhook_url') ?>" placeholder="https://discord.com/api/webhooks/...">
                </label>
                <label>
                    <span>Slack webhook URL <small><?= $globalStatus('slack_webhook_url') ?></small></span>
                    <input type="url" name="slack_webhook_url" value="<?= $value('slack_webhook_url') ?>" placeholder="https://hooks.slack.com/services/...">
                </label>
                <label>
                    <span>Generic webhook URL <small><?= $globalStatus('generic_webhook_url') ?></small></span>
                    <input type="url" name="generic_webhook_url" value="<?= $value('generic_webhook_url') ?>" placeholder="https://hooks.example.com/formflow">
                </label>
                <label>
                    <span>Telegram bot token <small><?= $globalStatus('telegram_bot_token') ?></small></span>
                    <input type="text" name="telegram_bot_token" value="<?= $value('telegram_bot_token') ?>">
                </label>
                <label>
                    <span>Telegram chat ID <small><?= $globalStatus('telegram_chat_id') ?></small></span>
                    <input type="text" name="telegram_chat_id" value="<?= $value('telegram_chat_id') ?>">
                </label>
            </div>
        </fieldset>
    </div>

    <div class="form-actions">
        <button type="submit">Create form</button>
    </div>
</form>
