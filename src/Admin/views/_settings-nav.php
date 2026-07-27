<?php
/** @var 'general'|'integrations'|'whitelist'|'users'|'audit' $settingsSection */
$settingsLinks = [
    'general' => ['/admin/settings', 'General'],
    'integrations' => ['/admin/integrations', 'Integrations'],
    'whitelist' => ['/admin/whitelist', 'IP whitelist'],
    'users' => ['/admin/users', 'Users'],
    'audit' => ['/admin/audit', 'Audit'],
];
?>
<nav class="settings-subnav" aria-label="Settings sections">
    <?php foreach ($settingsLinks as $section => [$href, $label]): ?>
        <a href="<?= $href ?>"<?= $settingsSection === $section ? ' aria-current="page"' : '' ?>>
            <?= $label ?>
        </a>
    <?php endforeach; ?>
</nav>
