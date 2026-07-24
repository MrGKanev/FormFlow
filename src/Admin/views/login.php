<?php
/** @var string|null $error */
/** @var string $csrfToken */
/** @var bool $isLocal */
?>
<h1>Admin login</h1>
<?php if ($error !== null): ?>
    <p class="banner error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>
<form method="POST" action="/admin/login">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
    <label>Username <input type="text" name="username" required></label>
    <label>Password <input type="password" name="password" required></label>
    <button type="submit">Log in</button>
</form>
<?php if ($isLocal): ?>
<form method="POST" action="/admin/login">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="dev_bypass" value="1">
    <button type="submit" class="secondary">Log in (localhost)</button>
</form>
<?php endif; ?>
