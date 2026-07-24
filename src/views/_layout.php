<?php
/** @var string $title */
/** @var bool $withNav */
/** @var string $content */
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>formflow admin - <?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>
<link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<?php if ($withNav): ?>
<nav class="top">
    <a href="/admin">Submissions</a>
    <a href="/admin/api-keys">API keys</a>
    <a href="/admin/whitelist">IP whitelist</a>
    <a href="/admin/logout">Log out</a>
</nav>
<?php endif; ?>
<div class="container<?= $withNav ? '' : ' narrow' ?>">
<?= $content ?>
</div>
</body>
</html>
