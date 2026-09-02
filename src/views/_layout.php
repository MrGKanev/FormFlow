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
    '/admin' => ['label' => 'Submissions', 'icon' => 'inbox'],
    '/admin/analytics' => ['label' => 'Analytics', 'icon' => 'chart'],
    '/admin/forms' => ['label' => 'Forms', 'icon' => 'forms'],
    '/admin/delivery' => ['label' => 'Delivery', 'icon' => 'send'],
    '/admin/settings' => ['label' => 'Settings', 'icon' => 'settings'],
    '/admin/system' => ['label' => 'System', 'icon' => 'activity'],
];

$icons = [
    'inbox' => '<path d="M4 5.5h16v13H4z"/><path d="M4 13h4l2 2h4l2-2h4"/>',
    'forms' => '<rect x="5" y="3" width="14" height="18" rx="2"/><path d="M9 8h6M9 12h6M9 16h4"/>',
    'send' => '<path d="m21 3-7.5 18-4.2-7.8L3 9.5 21 3Z"/><path d="m9.3 13.2 5.2-4.7"/>',
    'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .34 1.88l.06.06-2.83 2.83-.06-.06A1.7 1.7 0 0 0 15 19.4a1.7 1.7 0 0 0-1 .6 1.7 1.7 0 0 0-.4 1v.1h-4v-.1a1.7 1.7 0 0 0-1.1-1.6 1.7 1.7 0 0 0-1.88.34l-.06.06-2.83-2.83.06-.06A1.7 1.7 0 0 0 4.6 15a1.7 1.7 0 0 0-.6-1 1.7 1.7 0 0 0-1-.4h-.1v-4H3A1.7 1.7 0 0 0 4.6 8.5a1.7 1.7 0 0 0-.34-1.88l-.06-.06 2.83-2.83.06.06A1.7 1.7 0 0 0 9 4.6a1.7 1.7 0 0 0 1-.6 1.7 1.7 0 0 0 .4-1v-.1h4V3A1.7 1.7 0 0 0 15.5 4.6a1.7 1.7 0 0 0 1.88-.34l.06-.06 2.83 2.83-.06.06A1.7 1.7 0 0 0 19.4 9c.14.37.36.7.6 1 .28.28.64.4 1 .4h.1v4H21a1.7 1.7 0 0 0-1.6.6Z"/>',
    'activity' => '<path d="M3 12h4l2.4-6 4.2 12 2.4-6h5"/>',
    'chart' => '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
];

$icon = static fn (string $name): string => '<svg class="nav-icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' . ($icons[$name] ?? '') . '</svg>';

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
<a class="skip-link" href="#main-content">Skip to content</a>
<?php if ($withNav): ?>
<div class="app-shell">
    <nav class="top" aria-label="Admin navigation" data-app-nav>
        <div class="nav-head">
            <a href="/admin" class="nav-brand" aria-label="formflow admin">
                <span class="brand-mark" aria-hidden="true"><span></span><span></span><span></span></span>
                <span>formflow</span>
            </a>
            <button type="button" class="nav-close icon-button" data-nav-close aria-label="Close navigation">×</button>
        </div>
        <p class="nav-section-label">Workspace</p>
        <div class="nav-links">
            <?php foreach ($navItems as $href => $item): ?>
                <a href="<?= $href ?>"<?= $isActive($href) ? ' aria-current="page"' : '' ?>>
                    <?= $icon($item['icon']) ?>
                    <span><?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?></span>
                </a>
            <?php endforeach; ?>
        </div>
        <div class="nav-spacer"></div>
        <div class="nav-status"><span class="status-dot"></span><span>Self-hosted workspace</span><span class="nav-version">v<?= htmlspecialchars(\formflow\AppVersion::current(), ENT_QUOTES, 'UTF-8') ?></span></div>
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
    <button class="nav-overlay" type="button" data-nav-close aria-label="Close navigation"></button>
    <div class="app-main">
        <header class="mobile-topbar">
            <a href="/admin" class="nav-brand" aria-label="formflow admin">
                <span class="brand-mark" aria-hidden="true"><span></span><span></span><span></span></span>
                <span>formflow</span>
            </a>
            <button type="button" class="icon-button menu-button" data-nav-open aria-label="Open navigation" aria-expanded="false">☰</button>
        </header>
        <main id="main-content" class="container<?= htmlspecialchars($containerClass, ENT_QUOTES, 'UTF-8') ?>">
        <?= $content ?>
        </main>
    </div>
</div>
<?php else: ?>
<div class="theme-corner">
    <button type="button" class="theme-toggle" data-theme-toggle aria-label="Switch to dark theme" aria-pressed="false">
        <span class="theme-toggle-icon" aria-hidden="true"></span>
        <span data-theme-label>Dark</span>
    </button>
</div>
<main id="main-content" class="container<?= htmlspecialchars($containerClass, ENT_QUOTES, 'UTF-8') ?>">
    <?= $content ?>
</main>
<span class="standalone-version">v<?= htmlspecialchars(\formflow\AppVersion::current(), ENT_QUOTES, 'UTF-8') ?></span>
<?php endif; ?>
</body>
</html>
