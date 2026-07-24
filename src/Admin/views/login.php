<?php
/** @var string|null $error */
/** @var string $csrfToken */
/** @var bool $isLocal */
?>
<div class="auth-panel">
<div class="auth-mark" aria-hidden="true"></div>
<p class="page-kicker">formflow admin</p>
<h1>Welcome back</h1>
<p class="tagline">Sign in to review submissions, rotate API keys, and manage admin access.</p>
<?php if ($error !== null): ?>
    <p class="banner error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>
<form method="POST" action="/admin/login" class="auth-form">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
    <label><span>Username</span><input type="text" name="username" autocomplete="username" required></label>
    <label><span>Password</span><input type="password" name="password" autocomplete="current-password" required></label>
    <button type="submit">Log in</button>
</form>
<?php if ($isLocal): ?>
<form method="POST" action="/admin/login" class="inline dev-login">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="dev_bypass" value="1">
    <button type="submit" class="secondary">Log in (localhost)</button>
</form>
<?php endif; ?>
</div>
