<?php
/** @var string|null $error */
/** @var string $csrfToken */
/** @var array<string, mixed> $values */
/** @var array<string, mixed> $integrationSettings */
/** @var array<string, array{label: string, description: string, values: array<string, mixed>}> $templates */
$value = static fn (string $key, string $default = ''): string => htmlspecialchars(
    (string) ($values[$key] ?? $default),
    ENT_QUOTES,
    'UTF-8'
);
$captchaProvider = (string) ($values['captcha_provider'] ?? 'none');
$captchaSelected = static fn (string $provider): string => $captchaProvider === $provider ? ' selected' : '';
$deliveryChannels = is_array($values['delivery_channels'] ?? null)
    ? $values['delivery_channels']
    : [];
$globalStatus = static fn (string $key): string => (string) ($integrationSettings[$key] ?? '') !== ''
    ? 'global configured'
    : 'no global default';
$captchaEnabled = $captchaProvider !== 'none';
$uploadsEnabled = !empty($values['uploads_enabled']);
$integrationsEnabled = $deliveryChannels !== [];
$autoReplyEnabled = !empty($values['auto_reply_enabled']);
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

<form method="POST" action="/admin/forms/new" class="guided-form">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="template_applied" value="0" data-template-applied>
    <input type="hidden" name="guided_create" value="1">

    <fieldset class="field-picker template-picker">
            <legend>Start with a template</legend>
            <p class="muted">Optional — choose a starting point or continue with a blank form.</p>
            <div class="template-grid">
                <?php foreach ($templates as $templateId => $template): ?>
                    <label class="template-option">
                        <input type="radio" name="template" value="<?= htmlspecialchars($templateId, ENT_QUOTES, 'UTF-8') ?>" data-template-choice>
                        <span><strong><?= htmlspecialchars($template['label'], ENT_QUOTES, 'UTF-8') ?></strong><small><?= htmlspecialchars($template['description'], ENT_QUOTES, 'UTF-8') ?></small></span>
                    </label>
                <?php endforeach; ?>
            </div>
    </fieldset>

    <section class="setup-section">
        <div class="setup-section-heading"><span class="step-number">1</span><div><h2>Essentials</h2><p>Only the information required to receive submissions.</p></div></div>
        <div class="form-grid">
        <label>
            <span>Form name</span>
            <input type="text" name="form_name" placeholder="Website contact form" value="<?= $value('form_name') ?>" required maxlength="120">
            <small>The technical endpoint ID will be generated automatically.</small>
        </label>
        <label>
            <span>Recipient email</span>
            <input type="email" name="recipient" placeholder="hello@example.com" value="<?= $value('recipient') ?>" required>
        </label>
        <label class="span-2">
            <span>Website URL</span>
            <input type="url" name="allowed_origins" placeholder="https://example.com" value="<?= $value('allowed_origins') ?>" required>
            <small>Only this website will be allowed to submit to the form.</small>
        </label>
        </div>
    </section>

    <section class="setup-section">
        <div class="setup-section-heading"><span class="step-number">2</span><div><h2>Choose features</h2><p>Enable only what this form actually needs.</p></div></div>
        <div class="feature-toggle-grid">
            <label class="feature-toggle"><input type="checkbox" name="captcha_enabled" value="1" data-feature-toggle="captcha-panel"<?= $captchaEnabled ? ' checked' : '' ?>><span><strong>Spam protection</strong><small>Add CAPTCHA validation</small></span></label>
            <label class="feature-toggle"><input type="checkbox" name="uploads_enabled" value="1" data-feature-toggle="uploads-panel"<?= $uploadsEnabled ? ' checked' : '' ?>><span><strong>File uploads</strong><small>Accept documents or images</small></span></label>
            <label class="feature-toggle"><input type="checkbox" data-feature-toggle="integrations-panel"<?= $integrationsEnabled ? ' checked' : '' ?>><span><strong>Notifications</strong><small>Slack, Discord, Telegram or webhook</small></span></label>
            <label class="feature-toggle"><input type="checkbox" name="auto_reply_enabled" value="1" data-feature-toggle="auto-reply-panel"<?= $autoReplyEnabled ? ' checked' : '' ?>><span><strong>Automatic reply</strong><small>Confirm receipt by email</small></span></label>
        </div>
    </section>

    <div class="feature-panels">
        <section class="feature-panel" id="captcha-panel" data-feature-panel<?= $captchaEnabled ? '' : ' hidden' ?>>
            <div class="section-heading"><div><h2>Spam protection</h2><p class="muted">Choose the CAPTCHA provider used by this form.</p></div></div>
            <label><span>CAPTCHA provider</span><select name="captcha_provider">
                <option value="none"<?= $captchaSelected('none') ?>>None</option>
                <option value="turnstile"<?= $captchaSelected('turnstile') ?>>Cloudflare Turnstile</option>
                <option value="hcaptcha"<?= $captchaSelected('hcaptcha') ?>>hCaptcha</option>
                <option value="recaptcha"<?= $captchaSelected('recaptcha') ?>>Google reCAPTCHA v2</option>
                <option value="friendlycaptcha"<?= $captchaSelected('friendlycaptcha') ?>>Friendly Captcha</option>
            </select></label>
        </section>

        <section class="feature-panel" id="uploads-panel" data-feature-panel<?= $uploadsEnabled ? '' : ' hidden' ?>>
            <div class="section-heading"><div><h2>File uploads</h2><p class="muted">Set safe limits for submitted files.</p></div></div>
            <div class="form-grid"><label><span>Maximum size (MB)</span><input type="number" name="upload_max_file_size_mb" min="1" max="100" value="<?= $value('upload_max_file_size_mb', '10') ?>"></label><label><span>Maximum files</span><input type="number" name="upload_max_files" min="1" max="20" value="<?= $value('upload_max_files', '3') ?>"></label><label class="span-2"><span>Allowed extensions</span><textarea name="upload_allowed_extensions" rows="3" placeholder="pdf&#10;jpg&#10;png"><?= $value('upload_allowed_extensions') ?></textarea></label></div>
        </section>

        <section class="feature-panel" id="integrations-panel" data-feature-panel<?= $integrationsEnabled ? '' : ' hidden' ?>>
            <div class="section-heading"><div><h2>Notifications</h2><p class="muted">Send new submissions to selected integrations.</p></div></div>
            <fieldset class="field-picker">
            <legend>Delivery channels</legend>
            <p class="muted">Only enabled integrations are used. Configure their endpoints in Settings → Integrations.</p>
            <div class="option-grid">
                <?php foreach (['discord' => 'Discord', 'slack' => 'Slack', 'telegram' => 'Telegram', 'generic' => 'Generic webhook'] as $channel => $label): ?>
                    <label class="checkbox-label option-check">
                        <input type="checkbox" name="delivery_channels[]" value="<?= $channel ?>"<?= in_array($channel, $deliveryChannels, true) ? ' checked' : '' ?>>
                        <span><?= $label ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
            </fieldset>
            <details class="nested-options"><summary>Custom destinations</summary>
            <h3>Per-form integration overrides</h3>
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
            </details>
        </section>

        <section class="feature-panel" id="auto-reply-panel" data-feature-panel<?= $autoReplyEnabled ? '' : ' hidden' ?>>
            <div class="section-heading"><div><h2>Automatic reply</h2><p class="muted">Use <code>{{name}}</code>, <code>{{email}}</code>, <code>{{form_id}}</code>, or another submitted field.</p></div></div>
            <div class="form-grid"><label class="span-2"><span>Reply subject</span><input type="text" name="auto_reply_subject" maxlength="180" value="<?= $value('auto_reply_subject') ?>" placeholder="We received your message"></label><label class="span-2"><span>Reply message</span><textarea name="auto_reply_body" rows="6" placeholder="Hi {{name}},&#10;&#10;Thanks for your message."><?= $value('auto_reply_body') ?></textarea></label></div>
        </section>
    </div>

    <details class="advanced-options"<?= $error !== null ? ' open' : '' ?>>
        <summary><span><strong>Advanced settings</strong><small>Subject, redirect, limits, API access and blocked patterns</small></span></summary>
        <div class="form-grid advanced-options-body">
            <label><span>Email subject</span><input type="text" name="subject" placeholder="New form submission" value="<?= $value('subject') ?>"></label>
            <label><span>Success redirect</span><input type="url" name="success_redirect" placeholder="https://example.com/thank-you" value="<?= $value('success_redirect') ?>"></label>
            <label><span>Per-IP maximum</span><input type="number" name="rate_limit_max" min="1" value="<?= $value('rate_limit_max', '5') ?>"></label>
            <label><span>Window minutes</span><input type="number" name="rate_limit_window" min="1" value="<?= $value('rate_limit_window', '10') ?>"></label>
            <label><span>Daily limit</span><input type="number" name="daily_limit" min="1" value="<?= $value('daily_limit', '200') ?>"></label>
            <label class="checkbox-label"><input type="checkbox" name="require_api_key" value="1"<?= $values === [] || !empty($values['require_api_key']) ? ' checked' : '' ?>><span>Require API key</span></label>
            <label class="span-2"><span>Blocked patterns</span><textarea name="blocked_patterns" rows="3" placeholder="viagra&#10;&lt;a href="><?= $value('blocked_patterns') ?></textarea></label>
        </div>
    </details>

    <div class="form-actions guided-form-actions">
        <button type="submit">Create form</button><span class="muted">You can change every setting later.</span>
    </div>
</form>
<script type="application/json" id="form-template-data"><?= json_encode(array_map(static fn (array $template): array => $template['values'], $templates), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?></script>
