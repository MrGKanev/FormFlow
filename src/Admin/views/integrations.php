<?php
/** @var string|null $error */
/** @var bool $saved */
/** @var array<string, mixed> $settings */
/** @var string $csrfToken */
$value = static fn (string $key): string => htmlspecialchars((string) ($settings[$key] ?? ''), ENT_QUOTES, 'UTF-8');
?>
<div class="page-header">
    <div>
        <p class="page-kicker">Settings</p>
        <h1>Integrations</h1>
        <p class="page-meta">Send new submissions to the tools your team already uses.</p>
    </div>
</div>

<?php $settingsSection = 'integrations'; require __DIR__ . '/_settings-nav.php'; ?>

<?php if ($saved): ?>
    <p class="banner success">Integrations saved.</p>
<?php endif; ?>

<?php if ($error !== null): ?>
    <p class="banner error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>

<form method="POST" action="/admin/integrations" class="settings-form">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
    <div class="settings-grid">
        <section class="panel">
            <div class="section-heading"><h2>Discord</h2></div>
            <label>
                <span>Webhook URL</span>
                <input type="url" name="discord_webhook_url" value="<?= $value('discord_webhook_url') ?>" placeholder="https://discord.com/api/webhooks/...">
            </label>
        </section>
        <section class="panel">
            <div class="section-heading"><h2>Slack</h2></div>
            <label>
                <span>Incoming webhook URL</span>
                <input type="url" name="slack_webhook_url" value="<?= $value('slack_webhook_url') ?>" placeholder="https://hooks.slack.com/services/...">
            </label>
        </section>
        <section class="panel">
            <div class="section-heading"><h2>Telegram</h2></div>
            <div class="form-grid">
                <label class="span-2"><span>Bot token</span><input type="password" name="telegram_bot_token" value="<?= $value('telegram_bot_token') ?>" autocomplete="new-password"></label>
                <label class="span-2"><span>Chat ID</span><input type="text" name="telegram_chat_id" value="<?= $value('telegram_chat_id') ?>" placeholder="-1001234567890"></label>
            </div>
        </section>
        <section class="panel">
            <div class="section-heading"><h2>Generic webhook</h2></div>
            <label>
                <span>Webhook URL</span>
                <input type="url" name="generic_webhook_url" value="<?= $value('generic_webhook_url') ?>" placeholder="https://hooks.example.com/formflow">
            </label>
            <p class="muted">Receives JSON with <code>form_id</code> and <code>fields</code>; useful for Zapier, Make, n8n, or custom services.</p>
        </section>
    </div>
    <div class="form-actions settings-actions"><button type="submit">Save integrations</button></div>
</form>
