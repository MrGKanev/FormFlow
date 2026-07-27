<?php
/** @var string|null $error */
/** @var string $formId */
/** @var string $csrfToken */
/** @var array<string, mixed> $values */
$value = static fn (string $key, string $default = ''): string => htmlspecialchars(
    (string) ($values[$key] ?? $default),
    ENT_QUOTES,
    'UTF-8'
);
$notificationChannels = is_array($values['notification_channels'] ?? null)
    ? $values['notification_channels']
    : ['discord', 'slack', 'telegram', 'generic'];
?>
<div class="page-header">
    <div>
        <p class="page-kicker">Configuration</p>
        <h1>Edit <?= htmlspecialchars($formId, ENT_QUOTES, 'UTF-8') ?></h1>
        <p class="page-meta">Changes are stored as a database-backed form configuration.</p>
    </div>
    <div class="header-actions">
        <a href="/admin/forms" class="button secondary">Back to forms</a>
    </div>
</div>

<?php if ($error !== null): ?>
    <div class="banner error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<form method="POST" action="/admin/forms/<?= rawurlencode($formId) ?>/edit">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
    <div class="form-grid">
        <label>
            <span>Recipient email</span>
            <input type="email" name="recipient" value="<?= $value('recipient') ?>" required>
        </label>
        <label>
            <span>Email subject</span>
            <input type="text" name="subject" value="<?= $value('subject') ?>">
        </label>
        <label class="span-2">
            <span>Allowed origins</span>
            <textarea name="allowed_origins" rows="3" required><?= $value('allowed_origins') ?></textarea>
        </label>
        <label>
            <span>Success redirect</span>
            <input type="url" name="success_redirect" value="<?= $value('success_redirect') ?>">
        </label>
        <label>
            <span>Daily limit</span>
            <input type="number" name="daily_limit" min="1" value="<?= $value('daily_limit', '200') ?>">
        </label>
        <label>
            <span>Per-IP max</span>
            <input type="number" name="rate_limit_max" min="1" value="<?= $value('rate_limit_max', '5') ?>">
        </label>
        <label>
            <span>Window minutes</span>
            <input type="number" name="rate_limit_window" min="1" value="<?= $value('rate_limit_window', '10') ?>">
        </label>
        <label class="checkbox-label">
            <input type="checkbox" name="turnstile" value="1"<?= !empty($values['turnstile']) ? ' checked' : '' ?>>
            <span>Require Turnstile</span>
        </label>
        <label class="checkbox-label">
            <input type="checkbox" name="require_api_key" value="1"<?= !empty($values['require_api_key']) ? ' checked' : '' ?>>
            <span>Require API key</span>
        </label>
        <label class="span-2">
            <span>Blocked patterns</span>
            <textarea name="blocked_patterns" rows="3"><?= $value('blocked_patterns') ?></textarea>
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
            <textarea name="upload_allowed_extensions" rows="3"><?= $value('upload_allowed_extensions') ?></textarea>
        </label>
        <fieldset class="field-picker span-2">
            <legend>Send notifications to</legend>
            <p class="muted">Only enabled integrations are used. Configure their endpoints in Settings → Integrations.</p>
            <?php foreach (['discord' => 'Discord', 'slack' => 'Slack', 'telegram' => 'Telegram', 'generic' => 'Generic webhook'] as $channel => $label): ?>
                <label class="checkbox-label option-check">
                    <input type="checkbox" name="notification_channels[]" value="<?= $channel ?>"<?= in_array($channel, $notificationChannels, true) ? ' checked' : '' ?>>
                    <span><?= $label ?></span>
                </label>
            <?php endforeach; ?>
        </fieldset>
    </div>
    <div class="form-actions">
        <button type="submit">Save form</button>
    </div>
</form>
