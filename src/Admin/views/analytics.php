<?php
/** @var array{summary: array<string, int|float>, trend: list<array{date: string, total: int}>, statuses: list<array{status: string, total: int}>, forms: list<array{form_id: string, total: int}>} $analytics */
/** @var int $days */
/** @var string|null $formId */
/** @var list<string> $forms */
$summary = $analytics['summary'];
$trend = $analytics['trend'];
$trendTotals = array_map(static fn (array $row): int => $row['total'], $trend);
$formTotals = array_map(static fn (array $row): int => $row['total'], $analytics['forms']);
$maxTrend = $trendTotals === [] ? 1 : max(1, max($trendTotals));
$maxForm = $formTotals === [] ? 1 : max(1, max($formTotals));
?>
<div class="page-header">
    <div>
        <p class="page-kicker">Insights</p>
        <h1>Analytics</h1>
        <p class="page-meta">Submission volume, delivery health, and form performance.</p>
    </div>
    <form method="GET" action="/admin/analytics" class="analytics-controls utility-form">
        <label><span>Period</span><select name="days">
            <?php foreach ([7, 30, 90] as $option): ?>
                <option value="<?= $option ?>"<?= $days === $option ? ' selected' : '' ?>>Last <?= $option ?> days</option>
            <?php endforeach; ?>
        </select></label>
        <label><span>Form</span><select name="form_id"><option value="">All forms</option>
            <?php foreach ($forms as $option): ?>
                <option value="<?= htmlspecialchars($option, ENT_QUOTES, 'UTF-8') ?>"<?= $formId === $option ? ' selected' : '' ?>><?= htmlspecialchars($option, ENT_QUOTES, 'UTF-8') ?></option>
            <?php endforeach; ?>
        </select></label>
        <button type="submit" class="secondary">Apply</button>
    </form>
</div>

<div class="analytics-kpis">
    <article class="metric-card"><span>Submissions</span><strong><?= number_format((int) $summary['total']) ?></strong><small>Last <?= $days ?> days</small></article>
    <article class="metric-card"><span>Delivery rate</span><strong><?= number_format((float) $summary['delivery_rate'], 1) ?>%</strong><small><?= number_format((int) $summary['sent']) ?> sent · blocked excluded</small></article>
    <article class="metric-card"><span>Failed</span><strong><?= number_format((int) $summary['failed']) ?></strong><small>Needs attention</small></article>
    <article class="metric-card"><span>Blocked</span><strong><?= number_format((int) $summary['blocked']) ?></strong><small>Spam + honeypot</small></article>
</div>

<section class="panel analytics-chart-panel">
    <div class="section-heading"><div><h2>Submission volume</h2><p class="muted">Daily submissions over the selected period.</p></div></div>
    <div class="bar-chart" role="img" aria-label="Daily submission volume">
        <?php foreach ($trend as $index => $row): ?>
            <?php $height = $row['total'] > 0 ? max(6, (int) round(($row['total'] / $maxTrend) * 100)) : 2; ?>
            <div class="bar-column" title="<?= htmlspecialchars($row['date'], ENT_QUOTES, 'UTF-8') ?>: <?= $row['total'] ?>" aria-label="<?= htmlspecialchars($row['date'], ENT_QUOTES, 'UTF-8') ?>: <?= $row['total'] ?> submissions">
                <span class="bar-value"><?= $row['total'] > 0 ? $row['total'] : '' ?></span>
                <span class="bar" style="height: <?= $height ?>%"></span>
                <?php if ($days <= 7 || $index % max(1, (int) floor($days / 6)) === 0): ?><small><?= htmlspecialchars(substr($row['date'], 5), ENT_QUOTES, 'UTF-8') ?></small><?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<div class="analytics-grid">
    <section class="panel">
        <div class="section-heading"><h2>Top forms</h2></div>
        <?php if ($analytics['forms'] === []): ?><p class="empty-state">No submissions in this period.</p><?php endif; ?>
        <div class="rank-list">
            <?php foreach ($analytics['forms'] as $row): ?>
                <div><span><?= htmlspecialchars($row['form_id'], ENT_QUOTES, 'UTF-8') ?></span><span class="rank-track"><i style="width: <?= max(4, (int) round(($row['total'] / $maxForm) * 100)) ?>%"></i></span><strong><?= $row['total'] ?></strong></div>
            <?php endforeach; ?>
        </div>
    </section>
    <section class="panel">
        <div class="section-heading"><h2>Status breakdown</h2></div>
        <?php if ($analytics['statuses'] === []): ?><p class="empty-state">No statuses in this period.</p><?php endif; ?>
        <div class="status-breakdown">
            <?php foreach ($analytics['statuses'] as $row): ?>
                <div><span class="status-pill status-<?= htmlspecialchars(preg_replace('/[^a-z0-9_-]+/i', '-', $row['status']), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($row['status'], ENT_QUOTES, 'UTF-8') ?></span><strong><?= $row['total'] ?></strong></div>
            <?php endforeach; ?>
        </div>
    </section>
</div>
