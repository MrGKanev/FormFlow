<?php
/** @var array<string, string|int> $status */
$badge = static function (string|int $value): string {
    if (in_array($value, ['Present', 'Writable', 'sync', 'queue'], true) || (is_int($value) && $value === 0)) {
        return 'good';
    }

    if (is_int($value)) {
        return $value > 0 ? 'warn' : 'good';
    }

    return 'danger';
};
$isLongValue = static fn (string $label): bool => in_array($label, ['database_path'], true);
?>
<div class="page-header">
    <div>
        <p class="page-kicker">Operations</p>
        <h1>System status</h1>
        <p class="page-meta">Runtime checks for storage, delivery queues, and deployment configuration.</p>
    </div>
</div>

<section class="panel">
    <div class="section-heading">
        <h2>Runtime</h2>
        <span class="badge good">PHP <?= htmlspecialchars((string) $status['php_version'], ENT_QUOTES, 'UTF-8') ?></span>
    </div>
    <div class="detail-grid">
        <?php foreach ($status as $label => $value): ?>
            <div>
                <span><?= htmlspecialchars(ucwords(str_replace('_', ' ', $label)), ENT_QUOTES, 'UTF-8') ?></span>
                <strong>
                    <span class="<?= $isLongValue((string) $label) ? 'system-value' : 'badge ' . htmlspecialchars($badge($value), ENT_QUOTES, 'UTF-8') ?>">
                        <?= htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') ?>
                    </span>
                </strong>
            </div>
        <?php endforeach; ?>
    </div>
</section>
