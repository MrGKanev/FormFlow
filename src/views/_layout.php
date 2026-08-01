<?php
/** @var string $title */
/** @var bool $withNav */
/** @var string $content */
$currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$containerClass = isset($containerClass)
    ? ' ' . trim((string) $containerClass)
    : ($withNav ? '' : ' narrow');
$bodyClass = $withNav ? 'with-nav' : 'without-nav';

$navItems = [
    '/admin' => 'Submissions',
    '/admin/forms' => 'Forms',
    '/admin/delivery' => 'Delivery',
    '/admin/settings' => 'Settings',
    '/admin/system' => 'System',
];

$isActive = static function (string $href) use ($currentPath): bool {
    if ($href === '/admin') {
        return $currentPath === '/admin' || str_starts_with($currentPath, '/admin/submissions/');
    }

    if ($href === '/admin/settings') {
        return in_array($currentPath, ['/admin/settings', '/admin/integrations', '/admin/whitelist', '/admin/users', '/admin/audit'], true);
    }

    return $currentPath === $href || str_starts_with($currentPath, $href . '/');
};
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>formflow admin - <?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'%3E%3Ctext y='.9em' font-size='90'%3E%F0%9F%93%A8%3C/text%3E%3C/svg%3E">
<script>
try {
    document.documentElement.dataset.theme = localStorage.getItem('formflow-theme') === 'dark' ? 'dark' : 'light';
} catch (error) {
    document.documentElement.dataset.theme = 'light';
}
</script>
<link rel="stylesheet" href="/assets/style.css">
<script src="/assets/theme.js" defer></script>
</head>
<body class="<?= $bodyClass ?>">
<?php if ($withNav): ?>
<nav class="top" aria-label="Admin navigation">
    <a href="/admin" class="nav-brand" aria-label="formflow admin">formflow</a>
    <div class="nav-links">
        <?php foreach ($navItems as $href => $label): ?>
            <a href="<?= $href ?>"<?= $isActive($href) ? ' aria-current="page"' : '' ?>>
                <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
            </a>
        <?php endforeach; ?>
    </div>
    <div class="nav-actions">
        <button type="button" class="theme-toggle" data-theme-toggle aria-label="Switch to dark theme" aria-pressed="false">
            <span class="theme-toggle-icon" aria-hidden="true"></span>
            <span data-theme-label>Dark</span>
        </button>
        <form method="POST" action="/admin/logout" class="inline">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
            <button type="submit" class="nav-logout">Log out</button>
        </form>
    </div>
</nav>
<?php else: ?>
<div class="theme-corner">
    <button type="button" class="theme-toggle" data-theme-toggle aria-label="Switch to dark theme" aria-pressed="false">
        <span class="theme-toggle-icon" aria-hidden="true"></span>
        <span data-theme-label>Dark</span>
    </button>
</div>
<?php endif; ?>
<main class="container<?= htmlspecialchars($containerClass, ENT_QUOTES, 'UTF-8') ?>">
<?= $content ?>
</main>
</body>
</html>
