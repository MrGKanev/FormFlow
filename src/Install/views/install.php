<?php
/** @var string|null $error */
/** @var array<string, mixed> $old */
/** @var list<array{label: string, detail: string, ok: bool}> $checks */
/** @var bool $checksPass */
/** @var string $csrfToken */
$value = static fn (string $field): string => htmlspecialchars((string) ($old[$field] ?? ''), ENT_QUOTES, 'UTF-8');
$passedChecks = count(array_filter($checks, static fn (array $check): bool => $check['ok']));
?>
<div class="page-header">
    <div>
        <p class="page-kicker">First run</p>
        <h1>formflow setup</h1>
        <p class="page-meta">Create the first admin user and optional delivery settings.</p>
    </div>
</div>

<section class="panel">
    <div class="section-heading">
        <h2>Preflight checks</h2>
        <span class="badge <?= $checksPass ? 'good' : 'danger' ?>"><?= $passedChecks ?> / <?= count($checks) ?> passed</span>
    </div>
    <ul class="checks">
    <?php foreach ($checks as $check): ?>
        <li class="<?= $check['ok'] ? 'ok' : 'fail' ?>">
            <span>
                <span class="check-label"><?= htmlspecialchars($check['label'], ENT_QUOTES, 'UTF-8') ?></span>
                <span class="check-detail"><code><?= htmlspecialchars($check['detail'], ENT_QUOTES, 'UTF-8') ?></code></span>
            </span>
        </li>
    <?php endforeach; ?>
    </ul>
</section>

<?php if (!$checksPass): ?>
    <p class="banner error">Fix the failing check(s) above (permissions: <code>chmod</code>/<code>chown</code>; extensions: enable them in <code>php.ini</code>), then reload this page.</p>
<?php else: ?>
    <?php if ($error !== null): ?>
        <p class="banner error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>
    <form method="POST" action="/install">
        <h2>Admin account</h2>
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
        <div class="form-grid">
            <label><span>Admin username</span><input type="text" name="admin_username" value="<?= $value('admin_username') ?>" autocomplete="username" required></label>
            <label><span>Admin password</span><input type="password" name="admin_password" autocomplete="new-password" required></label>

            <div class="span-2">
                <h2>Delivery settings</h2>
                <p class="tagline">Optional values can stay blank and be edited later in <code>.env</code>.</p>
            </div>

            <label class="span-2"><span>App URL</span><input type="url" name="app_url" value="<?= $value('app_url') ?>" placeholder="https://forms.example.com"></label>
            <label>
                <span>SMTP host</span>
                <input type="text" name="smtp_host" value="<?= $value('smtp_host') ?>" placeholder="smtp.example.com">
            </label>
            <label>
                <span>SMTP port</span>
                <input type="number" name="smtp_port" min="1" max="65535" value="<?= $value('smtp_port') !== '' ? $value('smtp_port') : '587' ?>">
            </label>
            <label>
                <span>Encryption</span>
                <select name="smtp_encryption">
                    <option value="tls"<?= (($old['smtp_encryption'] ?? 'tls') === 'tls') ? ' selected' : '' ?>>TLS / STARTTLS</option>
                    <option value="ssl"<?= (($old['smtp_encryption'] ?? '') === 'ssl') ? ' selected' : '' ?>>SSL</option>
                    <option value="none"<?= (($old['smtp_encryption'] ?? '') === 'none') ? ' selected' : '' ?>>None</option>
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
            <label><span>From address</span><input type="email" name="mail_from" value="<?= $value('mail_from') ?>" placeholder="forms@example.com"></label>
            <label><span>From name</span><input type="text" name="mail_from_name" value="<?= $value('mail_from_name') ?>" placeholder="formflow"></label>
            <label class="span-2"><span>SMTP DSN override</span><input type="text" name="mailer_dsn" value="<?= $value('mailer_dsn') ?>" placeholder="smtp://user:pass@host:587"></label>
            <label class="span-2"><span>Cloudflare Turnstile secret</span><input type="text" name="turnstile_secret" value="<?= $value('turnstile_secret') ?>"></label>
        </div>

        <div class="form-actions">
            <button type="submit">Install</button>
        </div>
    </form>
<?php endif; ?>
