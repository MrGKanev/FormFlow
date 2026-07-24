<?php
/** @var string|null $error */
/** @var string $csrfToken */
?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><title>formflow admin — login</title></head>
<body>
<h1>Admin login</h1>
<?php if ($error !== null): ?>
    <p style="color:red;"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>
<form method="POST" action="/admin/login">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
    <label>Username <input type="text" name="username" required></label>
    <label>Password <input type="password" name="password" required></label>
    <button type="submit">Log in</button>
</form>
</body>
</html>
