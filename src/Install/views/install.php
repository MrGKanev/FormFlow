<?php
/** @var string|null $error */
/** @var array<string, mixed> $old */
/** @var list<array{label: string, detail: string, ok: bool}> $checks */
/** @var bool $checksPass */
/** @var string $csrfToken */
$value = static fn (string $field): string => htmlspecialchars((string) ($old[$field] ?? ''), ENT_QUOTES, 'UTF-8');
?>
<h1>formflow setup</h1>
<p class="tagline">This runs once, the first time <code>.env</code> doesn't exist yet.</p>

<h2>Preflight checks</h2>
<ul class="checks">
<?php foreach ($checks as $check): ?>
    <li class="<?= $check['ok'] ? 'ok' : 'fail' ?>">
        <?= htmlspecialchars($check['label'], ENT_QUOTES, 'UTF-8') ?>
        &mdash; <code><?= htmlspecialchars($check['detail'], ENT_QUOTES, 'UTF-8') ?></code>
    </li>
<?php endforeach; ?>
</ul>

<?php if (!$checksPass): ?>
    <p class="banner error">Fix the failing check(s) above (permissions: <code>chmod</code>/<code>chown</code>; extensions: enable them in <code>php.ini</code>), then reload this page.</p>
<?php else: ?>
    <?php if ($error !== null): ?>
        <p class="banner error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>
    <form method="POST" action="/install">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

        <label>Admin username <input type="text" name="admin_username" value="<?= $value('admin_username') ?>" required></label>
        <label>Admin password (min. 8 characters) <input type="password" name="admin_password" required></label>

        <p class="tagline">Everything below is optional - leave blank and configure later by editing <code>.env</code>. Email sending won't work until <code>SMTP DSN</code> and <code>From address</code> are set.</p>

        <label>App URL (recommended) <input type="url" name="app_url" value="<?= $value('app_url') ?>" placeholder="https://forms.example.com"></label>
        <label>SMTP DSN (recommended) <input type="text" name="mailer_dsn" value="<?= $value('mailer_dsn') ?>" placeholder="smtp://user:pass@host:587"></label>
        <label>From address (recommended) <input type="email" name="mail_from" value="<?= $value('mail_from') ?>" placeholder="forms@example.com"></label>
        <label>From name <input type="text" name="mail_from_name" value="<?= $value('mail_from_name') ?>" placeholder="formflow"></label>
        <label>Cloudflare Turnstile secret <input type="text" name="turnstile_secret" value="<?= $value('turnstile_secret') ?>"></label>

        <button type="submit">Install</button>
    </form>
<?php endif; ?>
