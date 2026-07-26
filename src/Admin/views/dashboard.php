<?php
/** @var list<array<string, mixed>> $submissions */
/** @var int $total */
/** @var int $page */
/** @var int $perPage */
/** @var string|null $formId */
/** @var string|null $status */
/** @var array<string, string> $setupStatus */
$statusOptions = [
    '' => 'All statuses',
    'received' => 'Received',
    'sent' => 'Sent',
    'failed' => 'Failed',
    'blocked_spam' => 'Blocked spam',
    'blocked_honeypot' => 'Blocked honeypot',
];
if ($status !== null && !array_key_exists($status, $statusOptions)) {
    $statusOptions[$status] = $status;
}
$statusClass = static fn (string $value): string => 'status-' . preg_replace('/[^a-z0-9_-]+/i', '-', strtolower($value));
$pageCount = (int) max(1, ceil($total / $perPage));
$pageUrl = static function (int $targetPage) use ($formId, $status): string {
    $query = ['page' => $targetPage];

    if ($formId !== null) {
        $query['form_id'] = $formId;
    }

    if ($status !== null) {
        $query['status'] = $status;
    }

    return '/admin?' . http_build_query($query);
};
?>
<div class="page-header">
    <div>
        <p class="page-kicker">Admin console</p>
        <h1>Submissions</h1>
        <p class="page-meta">Review incoming form activity and delivery state.</p>
    </div>
</div>

<div class="dashboard-summary">
    <div class="stat-card">
        <p class="stat-label">Total</p>
        <p class="stat-value"><?= number_format($total) ?></p>
    </div>
    <div class="stat-card">
        <p class="stat-label">Page</p>
        <p class="stat-value"><?= $page ?> / <?= $pageCount ?></p>
    </div>
    <div class="stat-card">
        <p class="stat-label">Per page</p>
        <p class="stat-value"><?= $perPage ?></p>
    </div>
    <div class="stat-card">
        <p class="stat-label">Mail</p>
        <p class="stat-value"><?= htmlspecialchars($setupStatus['mail'] ?? 'Check', ENT_QUOTES, 'UTF-8') ?></p>
    </div>
    <div class="stat-card">
        <p class="stat-label">Turnstile</p>
        <p class="stat-value"><?= htmlspecialchars($setupStatus['turnstile'] ?? 'Check', ENT_QUOTES, 'UTF-8') ?></p>
    </div>
    <div class="stat-card">
        <p class="stat-label">Storage</p>
        <p class="stat-value"><?= htmlspecialchars($setupStatus['storage'] ?? 'Check', ENT_QUOTES, 'UTF-8') ?></p>
    </div>
</div>

<form method="GET" action="/admin" class="filter-form">
    <label>
        <span>Form ID</span>
        <input type="text" name="form_id" placeholder="contact" value="<?= htmlspecialchars((string) $formId, ENT_QUOTES, 'UTF-8') ?>">
    </label>
    <label>
        <span>Status</span>
        <select name="status">
            <?php foreach ($statusOptions as $value => $label): ?>
                <option value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>"<?= (string) $status === $value ? ' selected' : '' ?>>
                    <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                </option>
            <?php endforeach; ?>
        </select>
    </label>
    <div class="form-actions">
        <button type="submit">Filter</button>
        <a href="/admin" class="button secondary">Reset</a>
        <a href="/admin/export?<?= htmlspecialchars(http_build_query(['form_id' => $formId, 'status' => $status]), ENT_QUOTES, 'UTF-8') ?>" class="button secondary">Export CSV</a>
    </div>
</form>

<div class="table-wrap">
    <table>
        <thead>
            <tr><th>ID</th><th>Form</th><th>Status</th><th>Created</th><th>Action</th></tr>
        </thead>
        <tbody>
            <?php foreach ($submissions as $submission): ?>
                <?php $submissionStatus = (string) $submission['status']; ?>
                <?php $detailUrl = '/admin/submissions/' . (int) $submission['id']; ?>
                <tr>
                    <td><a class="row-link" href="<?= $detailUrl ?>">#<?= (int) $submission['id'] ?></a></td>
                    <td><?= htmlspecialchars((string) $submission['form_id'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><span class="status-pill <?= htmlspecialchars($statusClass($submissionStatus), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($submissionStatus, ENT_QUOTES, 'UTF-8') ?></span></td>
                    <td><time datetime="<?= htmlspecialchars((string) $submission['created_at'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string) $submission['created_at'], ENT_QUOTES, 'UTF-8') ?></time></td>
                    <td class="actions"><a class="button secondary compact" href="<?= $detailUrl ?>">Open</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($submissions === []): ?>
                <tr><td colspan="5" class="empty-state">No submissions match the current filters.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<div class="table-footer">
    <span><?= number_format($total) ?> total submissions</span>
    <div class="pager">
        <?php if ($page > 1): ?>
            <a class="button secondary" href="<?= htmlspecialchars($pageUrl($page - 1), ENT_QUOTES, 'UTF-8') ?>">Previous</a>
        <?php endif; ?>
        <?php if ($page < $pageCount): ?>
            <a class="button secondary" href="<?= htmlspecialchars($pageUrl($page + 1), ENT_QUOTES, 'UTF-8') ?>">Next</a>
        <?php endif; ?>
    </div>
</div>
