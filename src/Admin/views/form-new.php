<?php
/** @var string|null $error */
/** @var string $csrfToken */
/** @var array<string, mixed> $values */
$value = static fn (string $key, string $default = ''): string => htmlspecialchars(
    (string) ($values[$key] ?? $default),
    ENT_QUOTES,
    'UTF-8'
);
$checked = array_key_exists('turnstile', $values) || $values === [];
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
        <label class="checkbox-label">
            <input type="checkbox" name="turnstile" value="1"<?= $checked ? ' checked' : '' ?>>
            <span>Require Turnstile</span>
        </label>
        <label class="checkbox-label">
            <input type="checkbox" name="require_api_key" value="1"<?= array_key_exists('require_api_key', $values) ? ' checked' : '' ?>>
            <span>Require API key</span>
        </label>
        <label class="span-2">
            <span>Blocked patterns</span>
            <textarea name="blocked_patterns" rows="3" placeholder="viagra&#10;&lt;a href="><?= $value('blocked_patterns') ?></textarea>
        </label>
    </div>

    <div class="form-actions">
        <button type="submit">Create form</button>
    </div>
</form>
