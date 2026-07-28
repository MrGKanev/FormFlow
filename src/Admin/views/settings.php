<?php
/** @var string|null $error */
/** @var bool $saved */
/** @var string|null $notice */
/** @var array<string, mixed> $settings */
/** @var array<string, string> $setupStatus */
/** @var string $csrfToken */
/** @var string|null $totpQrSvg */
/** @var string $totpProvisioningUri */
/** @var 'general'|'delivery'|'protection'|'admin'|'maintenance' $activeTab */
$value = static fn (string $key, string $default = ''): string => htmlspecialchars(
    (string) ($settings[$key] ?? $default),
    ENT_QUOTES,
    'UTF-8'
);
$selected = static fn (string $key, string $value): string => (string) ($settings[$key] ?? '') === $value ? ' selected' : '';
$setupBadge = static function (string $value): string {
    return match ($value) {
        'Configured', 'Writable' => 'good',
        'Optional' => 'warn',
        default => 'danger',
    };
};
?>
<div class="page-header">
    <div>
        <p class="page-kicker">Runtime configuration</p>
        <h1>Settings</h1>
        <p class="page-meta">Manage global delivery, security, storage, and admin options.</p>
    </div>
</div>

<?php $settingsSection = 'general'; require __DIR__ . '/_settings-nav.php'; ?>

<?php
$settingsTabs = [
    'general' => 'General',
    'delivery' => 'Delivery',
    'protection' => 'Protection',
    'admin' => 'Admin access',
    'maintenance' => 'Maintenance',
];
?>
<nav class="settings-tabs" aria-label="Settings categories">
    <?php foreach ($settingsTabs as $tab => $label): ?>
        <a href="/admin/settings?tab=<?= $tab ?>"<?= $activeTab === $tab ? ' aria-current="page"' : '' ?>>
            <?= $label ?>
        </a>
    <?php endforeach; ?>
</nav>

<?php if ($saved): ?>
    <p class="banner success">Settings saved.</p>
<?php endif; ?>

<?php if (($notice ?? null) !== null): ?>
    <p class="banner success"><?= htmlspecialchars((string) $notice, ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>

<?php if ($error !== null): ?>
    <p class="banner error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>

<?php if ($activeTab === 'general'): ?>
    <section class="panel setup-panel">
        <div class="section-heading">
            <h2>Setup status</h2>
            <span class="badge <?= htmlspecialchars($setupBadge($setupStatus['storage'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                <?= htmlspecialchars($setupStatus['storage'] ?? 'Check storage', ENT_QUOTES, 'UTF-8') ?>
            </span>
        </div>
        <ul class="setup-list">
            <li><span><strong>Mail</strong><small>SMTP host or DSN plus sender address</small></span><span class="badge <?= htmlspecialchars($setupBadge($setupStatus['mail'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($setupStatus['mail'] ?? 'Needs SMTP', ENT_QUOTES, 'UTF-8') ?></span></li>
            <li><span><strong>CAPTCHA</strong><small>Provider secrets validate submissions; site keys appear in snippets</small></span><span class="badge <?= htmlspecialchars($setupBadge($setupStatus['captcha'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($setupStatus['captcha'] ?? 'Optional', ENT_QUOTES, 'UTF-8') ?></span></li>
            <li><span><strong>Storage</strong><small>Database directory must be writable</small></span><span class="badge <?= htmlspecialchars($setupBadge($setupStatus['storage'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($setupStatus['storage'] ?? 'Check storage', ENT_QUOTES, 'UTF-8') ?></span></li>
        </ul>
    </section>

    <form method="POST" action="/admin/settings" class="settings-form">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="tab" value="general">
        <section class="panel">
            <div class="section-heading"><h2>Application</h2></div>
            <div class="form-grid">
                <label><span>Environment</span><select name="app_env"><option value="production"<?= $selected('app_env', 'production') ?>>Production</option><option value="local"<?= $selected('app_env', 'local') ?>>Local</option><option value="development"<?= $selected('app_env', 'development') ?>>Development</option><option value="testing"<?= $selected('app_env', 'testing') ?>>Testing</option></select></label>
                <label><span>App URL</span><input type="url" name="app_url" value="<?= $value('app_url') ?>" placeholder="https://forms.example.com"></label>
                <label class="span-2"><span>Database path</span><input type="text" name="database_path" value="<?= $value('database_path', 'storage/submissions.sqlite') ?>" required></label>
                <label class="span-2"><span>IP hash secret</span><input type="text" name="ip_hash_secret" value="<?= $value('ip_hash_secret') ?>" required></label>
                <label><span>Retention days</span><input type="number" name="retention_days" min="1" value="<?= $value('retention_days', '180') ?>"></label>
            </div>
        </section>
        <div class="form-actions settings-actions"><input type="hidden" name="action" value="save"><button type="submit">Save general settings</button></div>
    </form>
<?php endif; ?>

<?php if ($activeTab === 'delivery'): ?>
    <form method="POST" action="/admin/settings" class="settings-form">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="tab" value="delivery">
        <section class="panel">
            <div class="section-heading"><h2>Email delivery and notifications</h2></div>
            <div class="form-grid">
                <label><span>SMTP host</span><input type="text" name="smtp_host" value="<?= $value('smtp_host') ?>" placeholder="smtp.example.com"></label>
                <label><span>SMTP port</span><input type="number" name="smtp_port" min="1" max="65535" value="<?= $value('smtp_port', '587') ?>"></label>
                <label><span>Encryption</span><select name="smtp_encryption"><option value="tls"<?= $selected('smtp_encryption', 'tls') ?>>TLS / STARTTLS</option><option value="ssl"<?= $selected('smtp_encryption', 'ssl') ?>>SSL</option><option value="none"<?= $selected('smtp_encryption', 'none') ?>>None</option></select></label>
                <label><span>SMTP username</span><input type="text" name="smtp_username" value="<?= $value('smtp_username') ?>" autocomplete="username"></label>
                <label class="span-2"><span>SMTP password</span><input type="password" name="smtp_password" value="<?= $value('smtp_password') ?>" autocomplete="new-password"></label>
                <label><span>From address</span><input type="email" name="mail_from" value="<?= $value('mail_from') ?>" placeholder="forms@example.com"></label>
                <label><span>From name</span><input type="text" name="mail_from_name" value="<?= $value('mail_from_name', 'formflow') ?>" placeholder="formflow"></label>
                <label class="span-2"><span>SMTP DSN override</span><input type="text" name="mailer_dsn" value="<?= $value('mailer_dsn') ?>" placeholder="smtp://user:pass@host:587"></label>
                <label><span>Email delivery mode</span><select name="mail_delivery_mode"><option value="sync"<?= $selected('mail_delivery_mode', 'sync') ?>>Sync</option><option value="queue"<?= $selected('mail_delivery_mode', 'queue') ?>>Queue</option></select></label>
                <label><span>Webhook delivery mode</span><select name="webhook_delivery_mode"><option value="sync"<?= $selected('webhook_delivery_mode', 'sync') ?>>Sync</option><option value="queue"<?= $selected('webhook_delivery_mode', 'queue') ?>>Queue</option></select></label>
            </div>
        </section>
        <div class="form-actions settings-actions"><input type="hidden" name="action" value="save"><button type="submit">Save delivery settings</button></div>
    </form>
    <section class="panel">
        <div class="section-heading"><h2>Test email</h2></div>
        <form method="POST" action="/admin/settings" class="utility-form"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="tab" value="delivery"><input type="hidden" name="action" value="test_email"><label><span>Recipient</span><input type="email" name="test_email_to" value="<?= $value('mail_from') ?>" required></label><div class="form-actions"><button type="submit" class="secondary">Send test email</button></div></form>
    </section>
<?php endif; ?>

<?php if ($activeTab === 'protection'): ?>
    <form method="POST" action="/admin/settings" class="settings-form">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="tab" value="protection">
        <div class="settings-grid">
            <section class="panel"><div class="section-heading"><h2>CAPTCHA providers</h2></div><div class="form-grid"><label><span>Turnstile secret</span><input type="text" name="turnstile_secret" value="<?= $value('turnstile_secret') ?>"></label><label><span>Turnstile site key</span><input type="text" name="turnstile_site_key" value="<?= $value('turnstile_site_key') ?>"></label><label><span>hCaptcha secret</span><input type="text" name="hcaptcha_secret" value="<?= $value('hcaptcha_secret') ?>"></label><label><span>hCaptcha site key</span><input type="text" name="hcaptcha_site_key" value="<?= $value('hcaptcha_site_key') ?>"></label><label><span>reCAPTCHA secret</span><input type="text" name="recaptcha_secret" value="<?= $value('recaptcha_secret') ?>"></label><label><span>reCAPTCHA site key</span><input type="text" name="recaptcha_site_key" value="<?= $value('recaptcha_site_key') ?>"></label><label><span>Friendly Captcha API key</span><input type="text" name="friendly_captcha_api_key" value="<?= $value('friendly_captcha_api_key') ?>"></label><label><span>Friendly Captcha site key</span><input type="text" name="friendly_captcha_site_key" value="<?= $value('friendly_captcha_site_key') ?>"></label></div></section>
            <section class="panel"><div class="section-heading"><h2>Global blocklist</h2><span class="badge warn">config/security.php</span></div><label><span>Blocked IPs or CIDR ranges</span><textarea name="blocked_ips" rows="8" placeholder="203.0.113.5&#10;198.51.100.0/24"><?= $value('blocked_ips') ?></textarea></label></section>
            <section class="panel"><div class="section-heading"><h2>Trusted proxies</h2><span class="badge warn">config/security.php</span></div><label><span>Proxy IPs or CIDR ranges</span><textarea name="trusted_proxies" rows="5" placeholder="127.0.0.1&#10;10.0.0.0/8"><?= $value('trusted_proxies') ?></textarea></label><label><span>Trusted header server keys</span><textarea name="trusted_ip_headers" rows="4"><?= $value('trusted_ip_headers', "HTTP_CF_CONNECTING_IP\nHTTP_X_FORWARDED_FOR\nHTTP_X_REAL_IP") ?></textarea></label></section>
        </div>
        <div class="form-actions settings-actions"><input type="hidden" name="action" value="save"><button type="submit">Save protection settings</button></div>
    </form>
<?php endif; ?>

<?php if ($activeTab === 'admin'): ?>
    <form method="POST" action="/admin/settings" class="settings-form">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="tab" value="admin">
        <section class="panel"><div class="section-heading"><h2>Bootstrap admin account</h2></div><div class="form-grid"><label><span>Admin username</span><input type="text" name="admin_username" value="<?= $value('admin_username', 'admin') ?>" required autocomplete="username"></label><label><span>New password</span><input type="password" name="admin_password" autocomplete="new-password" placeholder="Leave blank to keep current"></label><label class="span-2"><span>Bootstrap TOTP secret</span><input type="text" name="admin_totp_secret" value="<?= $value('admin_totp_secret') ?>"></label><label><span>Login attempts</span><input type="number" name="login_rate_limit_max" min="1" value="<?= $value('login_rate_limit_max', '5') ?>"></label><label><span>Window minutes</span><input type="number" name="login_rate_limit_window" min="1" value="<?= $value('login_rate_limit_window', '15') ?>"></label></div></section>
        <div class="form-actions settings-actions"><input type="hidden" name="action" value="save"><button type="submit">Save admin settings</button></div>
    </form>
    <div class="settings-grid">
        <section class="panel"><div class="section-heading"><h2>Recovery</h2></div><form method="POST" action="/admin/settings" class="utility-form"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="tab" value="admin"><input type="hidden" name="action" value="generate_recovery"><div class="form-actions"><button type="submit" class="secondary">Generate recovery token</button></div></form></section>
        <section class="panel"><div class="section-heading"><h2>2FA</h2></div><?php if (($totpQrSvg ?? null) !== null): ?><div class="totp-setup"><div class="totp-qr-frame"><?= $totpQrSvg ?></div><div><p class="muted">Scan this QR code with an authenticator app.</p><code><?= htmlspecialchars($totpProvisioningUri, ENT_QUOTES, 'UTF-8') ?></code></div></div><?php endif; ?><form method="POST" action="/admin/settings" class="utility-form"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="tab" value="admin"><input type="hidden" name="action" value="generate_totp"><div class="form-actions"><button type="submit" class="secondary">Generate TOTP secret</button></div></form></section>
    </div>
<?php endif; ?>

<?php if ($activeTab === 'maintenance'): ?>
    <div class="settings-grid">
        <section class="panel"><div class="section-heading"><h2>Retention cleanup</h2></div><form method="POST" action="/admin/settings" class="utility-form"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="tab" value="maintenance"><input type="hidden" name="action" value="cleanup"><label><span>Delete submissions older than days</span><input type="number" name="retention_days" min="1" value="<?= $value('retention_days', '180') ?>"></label><div class="form-actions"><button type="submit" class="secondary">Run cleanup</button></div></form></section>
        <section class="panel"><div class="section-heading"><h2>Backup</h2></div><div class="form-actions"><a href="/admin/backup" class="button secondary">Download database</a><a href="/admin/config/export" class="button secondary">Export config</a></div></section>
        <section class="panel"><div class="section-heading"><h2>Import config</h2></div><form method="POST" action="/admin/config/import" class="utility-form"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>"><label><span>Config JSON</span><textarea name="config_json" rows="7"></textarea></label><div class="form-actions"><button type="submit" class="secondary">Import config</button></div></form></section>
    </div>
<?php endif; ?>
