<?php
/** @var string $token */
/** @var string|null $error */
/** @var string $csrfToken */
?>
<div class="auth-panel">
<p class="page-kicker">formflow recovery</p>
<h1>Reset admin password</h1>
<?php if ($error !== null): ?>
    <p class="banner error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>
<form method="POST" action="/admin/recovery">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
    <label><span>New password</span><input type="password" name="password" required autocomplete="new-password"></label>
    <div class="form-actions"><button type="submit">Reset password</button></div>
</form>
</div>
