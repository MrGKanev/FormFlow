<?php
/** @var string|null $error */
/** @var bool $saved */
/** @var string|null $notice */
/** @var array<string, mixed> $settings */
/** @var string $csrfToken */
$value = static fn (string $key, string $default = ''): string => htmlspecialchars(
    (string) ($settings[$key] ?? $default),
    ENT_QUOTES,
    'UTF-8'
);
$selected = static fn (string $key, string $value): string => (string) ($settings[$key] ?? '') === $value ? ' selected' : '';
?>
<div class="page-header">
    <div>
        <p class="page-kicker">Runtime configuration</p>
        <h1>Settings</h1>
        <p class="page-meta">Manage global delivery, security, storage, and admin options.</p>
    </div>
</div>

<?php if ($saved): ?>
    <p class="banner success">Settings saved.</p>
<?php endif; ?>

<?php if (($notice ?? null) !== null): ?>
    <p class="banner success"><?= htmlspecialchars((string) $notice, ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>

<?php if ($error !== null): ?>
    <p class="banner error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>

<form method="POST" action="/admin/settings" class="settings-form">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

    <div class="settings-grid">
        <section class="panel">
            <div class="section-heading">
                <h2>Application</h2>
            </div>

            <div class="form-grid">
                <label>
                    <span>Environment</span>
                    <select name="app_env">
                        <option value="production"<?= $selected('app_env', 'production') ?>>Production</option>
                        <option value="local"<?= $selected('app_env', 'local') ?>>Local</option>
                        <option value="development"<?= $selected('app_env', 'development') ?>>Development</option>
                        <option value="testing"<?= $selected('app_env', 'testing') ?>>Testing</option>
                    </select>
                </label>
                <label>
                    <span>App URL</span>
                    <input type="url" name="app_url" value="<?= $value('app_url') ?>" placeholder="https://forms.example.com">
                </label>
                <label class="span-2">
                    <span>Database path</span>
                    <input type="text" name="database_path" value="<?= $value('database_path', 'storage/submissions.sqlite') ?>" required>
                </label>
                <label class="span-2">
                    <span>IP hash secret</span>
                    <input type="text" name="ip_hash_secret" value="<?= $value('ip_hash_secret') ?>" required>
                </label>
                <label>
                    <span>Retention days</span>
                    <input type="number" name="retention_days" min="1" value="<?= $value('retention_days', '180') ?>">
                </label>
            </div>
        </section>

        <section class="panel">
            <div class="section-heading">
                <h2>Delivery</h2>
            </div>

            <div class="form-grid">
                <label>
                    <span>SMTP host</span>
                    <input type="text" name="smtp_host" value="<?= $value('smtp_host') ?>" placeholder="smtp.example.com">
                </label>
                <label>
                    <span>SMTP port</span>
                    <input type="number" name="smtp_port" min="1" max="65535" value="<?= $value('smtp_port', '587') ?>">
                </label>
                <label>
                    <span>Encryption</span>
                    <select name="smtp_encryption">
                        <option value="tls"<?= $selected('smtp_encryption', 'tls') ?>>TLS / STARTTLS</option>
                        <option value="ssl"<?= $selected('smtp_encryption', 'ssl') ?>>SSL</option>
                        <option value="none"<?= $selected('smtp_encryption', 'none') ?>>None</option>
                    </select>
                </label>
                <label>
                    <span>SMTP username</span>
                    <input type="text" name="smtp_username" value="<?= $value('smtp_username') ?>" autocomplete="username">
                </label>
                <label class="span-2">
                    <span>SMTP password</span>
                    <input type="password" name="smtp_password" value="<?= $value('smtp_password') ?>" autocomplete="new-password">
                </label>
                <label>
                    <span>From address</span>
                    <input type="email" name="mail_from" value="<?= $value('mail_from') ?>" placeholder="forms@example.com">
                </label>
                <label>
                    <span>From name</span>
                    <input type="text" name="mail_from_name" value="<?= $value('mail_from_name', 'formflow') ?>" placeholder="formflow">
                </label>
                <label class="span-2">
                    <span>SMTP DSN override</span>
                    <input type="text" name="mailer_dsn" value="<?= $value('mailer_dsn') ?>" placeholder="smtp://user:pass@host:587">
                </label>
                <label class="span-2">
                    <span>Cloudflare Turnstile secret</span>
                    <input type="text" name="turnstile_secret" value="<?= $value('turnstile_secret') ?>">
                </label>
            </div>
        </section>

        <section class="panel">
            <div class="section-heading">
                <h2>Admin access</h2>
            </div>

            <div class="form-grid">
                <label>
                    <span>Admin username</span>
                    <input type="text" name="admin_username" value="<?= $value('admin_username', 'admin') ?>" required autocomplete="username">
                </label>
                <label>
                    <span>New password</span>
                    <input type="password" name="admin_password" autocomplete="new-password" placeholder="Leave blank to keep current">
                </label>
                <label>
                    <span>Login attempts</span>
                    <input type="number" name="login_rate_limit_max" min="1" value="<?= $value('login_rate_limit_max', '5') ?>">
                </label>
                <label>
                    <span>Window minutes</span>
                    <input type="number" name="login_rate_limit_window" min="1" value="<?= $value('login_rate_limit_window', '15') ?>">
                </label>
            </div>
        </section>

        <section class="panel">
            <div class="section-heading">
                <h2>Global blocklist</h2>
                <span class="badge warn">config/security.php</span>
            </div>

            <label>
                <span>Blocked IPs or CIDR ranges</span>
                <textarea name="blocked_ips" rows="8" placeholder="203.0.113.5&#10;198.51.100.0/24"><?= $value('blocked_ips') ?></textarea>
            </label>
        </section>
    </div>

    <div class="form-actions settings-actions">
        <input type="hidden" name="action" value="save">
        <button type="submit">Save settings</button>
    </div>
</form>

<div class="settings-grid">
    <section class="panel">
        <div class="section-heading">
            <h2>Test email</h2>
        </div>
        <form method="POST" action="/admin/settings" class="utility-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="test_email">
            <label>
                <span>Recipient</span>
                <input type="email" name="test_email_to" value="<?= $value('mail_from') ?>" required>
            </label>
            <div class="form-actions"><button type="submit" class="secondary">Send test email</button></div>
        </form>
    </section>

    <section class="panel">
        <div class="section-heading">
            <h2>Retention cleanup</h2>
        </div>
        <form method="POST" action="/admin/settings" class="utility-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="cleanup">
            <label>
                <span>Delete submissions older than days</span>
                <input type="number" name="retention_days" min="1" value="<?= $value('retention_days', '180') ?>">
            </label>
            <div class="form-actions"><button type="submit" class="secondary">Run cleanup</button></div>
        </form>
    </section>
</div>
