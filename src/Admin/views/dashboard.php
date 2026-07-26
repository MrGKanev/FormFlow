<?php
/** @var list<array<string, mixed>> $submissions */
/** @var int $total */
/** @var int $page */
/** @var int $perPage */
/** @var string|null $formId */
/** @var string|null $status */
/** @var string|null $search */
/** @var string|null $dateFrom */
/** @var string|null $dateTo */
/** @var list<array<string, mixed>> $analytics */
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
$pageUrl = static function (int $targetPage) use ($formId, $status, $search, $dateFrom, $dateTo, $perPage): string {
    $query = ['page' => $targetPage, 'per_page' => $perPage];

    if ($formId !== null) {
        $query['form_id'] = $formId;
    }

    if ($status !== null) {
        $query['status'] = $status;
    }

    if ($search !== null) {
        $query['q'] = $search;
    }

    if ($dateFrom !== null) {
        $query['date_from'] = $dateFrom;
    }

    if ($dateTo !== null) {
        $query['date_to'] = $dateTo;
    }

    return '/admin?' . http_build_query($query);
};
$exportQuery = http_build_query([
    'form_id' => $formId,
    'status' => $status,
    'q' => $search,
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
]);
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
</div>

<form method="GET" action="/admin" class="filter-form">
    <label class="filter-field">
        <span>Search</span>
        <input type="search" name="q" placeholder="email, name, message, error" value="<?= htmlspecialchars((string) $search, ENT_QUOTES, 'UTF-8') ?>">
    </label>
    <label class="filter-field">
        <span>Form ID</span>
        <input type="text" name="form_id" placeholder="contact" value="<?= htmlspecialchars((string) $formId, ENT_QUOTES, 'UTF-8') ?>">
    </label>
    <label class="filter-field">
        <span>Status</span>
        <select name="status">
            <?php foreach ($statusOptions as $value => $label): ?>
                <option value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>"<?= (string) $status === $value ? ' selected' : '' ?>>
                    <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                </option>
            <?php endforeach; ?>
        </select>
    </label>
    <label class="filter-field">
        <span>From</span>
        <input type="date" name="date_from" value="<?= htmlspecialchars((string) $dateFrom, ENT_QUOTES, 'UTF-8') ?>">
    </label>
    <label class="filter-field">
        <span>To</span>
        <input type="date" name="date_to" value="<?= htmlspecialchars((string) $dateTo, ENT_QUOTES, 'UTF-8') ?>">
    </label>
    <label class="filter-field">
        <span>Per page</span>
        <select name="per_page">
            <?php foreach ([20, 50, 100] as $option): ?>
                <option value="<?= $option ?>"<?= $perPage === $option ? ' selected' : '' ?>><?= $option ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <div class="form-actions">
        <button type="submit">Filter</button>
        <a href="/admin" class="button secondary">Reset</a>
        <a href="/admin/export?<?= htmlspecialchars($exportQuery, ENT_QUOTES, 'UTF-8') ?>" class="button secondary">Export CSV</a>
    </div>
</form>

<?php if ($analytics !== []): ?>
<section class="panel">
    <div class="section-heading">
        <h2>Form analytics</h2>
    </div>
    <div class="table-wrap embedded">
        <table>
            <thead>
                <tr><th>Form</th><th>Today</th><th>7d</th><th>30d</th><th>Total</th><th>Failed</th><th>Blocked</th><th>Last</th></tr>
            </thead>
            <tbody>
            <?php foreach ($analytics as $row): ?>
                <tr>
                    <td><?= htmlspecialchars((string) $row['form_id'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= (int) $row['day_count'] ?></td>
                    <td><?= (int) $row['week_count'] ?></td>
                    <td><?= (int) $row['month_count'] ?></td>
                    <td><?= (int) $row['total'] ?></td>
                    <td><?= (int) $row['failed_count'] ?></td>
                    <td><?= (int) $row['blocked_count'] ?></td>
                    <td><?= htmlspecialchars((string) $row['last_submission'], ENT_QUOTES, 'UTF-8') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>

<form method="POST" action="/admin/submissions/bulk" class="utility-form">
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
<div class="table-wrap">
    <table>
        <thead>
            <tr><th><span class="muted">Select</span></th><th>ID</th><th>Form</th><th>Status</th><th>Created</th><th>Action</th></tr>
        </thead>
        <tbody>
            <?php foreach ($submissions as $submission): ?>
                <?php $submissionStatus = (string) $submission['status']; ?>
                <?php $detailUrl = '/admin/submissions/' . (int) $submission['id']; ?>
                <tr>
                    <td><input type="checkbox" name="submission_ids[]" value="<?= (int) $submission['id'] ?>"></td>
                    <td><a class="row-link" href="<?= $detailUrl ?>">#<?= (int) $submission['id'] ?></a></td>
                    <td><?= htmlspecialchars((string) $submission['form_id'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><span class="status-pill <?= htmlspecialchars($statusClass($submissionStatus), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($submissionStatus, ENT_QUOTES, 'UTF-8') ?></span></td>
                    <td><time datetime="<?= htmlspecialchars((string) $submission['created_at'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string) $submission['created_at'], ENT_QUOTES, 'UTF-8') ?></time></td>
                    <td class="actions"><a class="button secondary compact" href="<?= $detailUrl ?>">Open</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($submissions === []): ?>
                <tr><td colspan="6" class="empty-state">No submissions match the current filters.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>
<div class="table-footer">
    <div class="form-actions">
        <select name="bulk_action" aria-label="Bulk action">
            <option value="review">Mark reviewed</option>
            <option value="resend">Resend failed</option>
            <option value="export">Export selected</option>
            <option value="delete">Delete selected</option>
        </select>
        <button type="submit" class="secondary">Apply</button>
    </div>
</div>
</form>

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
