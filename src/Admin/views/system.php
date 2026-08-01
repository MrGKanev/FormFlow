<?php
/** @var array<string, string|int> $status */
/** @var list<string> $warnings */
$badge = static function (string $label, string|int $value): string {
    if (in_array($value, ['Present', 'Present at request start', 'Writable', 'production', 'sync', 'queue', 'Outside public root', 'Deny rule present', 'No pending work', 'Not required'], true) || (is_int($value) && $value === 0)) {
        return 'good';
    }

    if (str_ends_with($label, '_queue_lag')) {
        return $value === 'Unknown' ? 'danger' : 'warn';
    }

    if (str_ends_with($label, '_worker_last_run')) {
        return $value === 'Never' ? 'warn' : 'good';
    }

    if (is_int($value)) {
        return $value > 0 ? 'warn' : 'good';
    }

    return 'danger';
};
$isLongValue = static fn (string $label): bool => in_array($label, ['database_path', 'mail_worker_last_run', 'webhook_worker_last_run'], true);
?>
<div class="page-header">
    <div>
        <p class="page-kicker">Operations</p>
        <h1>System status</h1>
        <p class="page-meta">Runtime checks for storage, delivery queues, and deployment configuration.</p>
    </div>
</div>

<?php if ($warnings !== []): ?>
    <section class="panel">
        <div class="section-heading">
            <h2>Warnings</h2>
            <span class="badge warn"><?= count($warnings) ?></span>
        </div>
        <ul class="status-list">
            <?php foreach ($warnings as $warning): ?>
                <li><?= htmlspecialchars($warning, ENT_QUOTES, 'UTF-8') ?></li>
            <?php endforeach; ?>
        </ul>
    </section>
<?php endif; ?>

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
                    <span class="<?= $isLongValue((string) $label) ? 'system-value' : 'badge ' . htmlspecialchars($badge((string) $label, $value), ENT_QUOTES, 'UTF-8') ?>">
                        <?= htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') ?>
                    </span>
                </strong>
            </div>
        <?php endforeach; ?>
    </div>
</section>
