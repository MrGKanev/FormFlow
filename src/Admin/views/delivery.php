<?php
/** @var list<array<string, mixed>> $entries */
$statusClass = static fn (string $value): string => 'status-' . preg_replace('/[^a-z0-9_-]+/i', '-', strtolower($value));
?>
<div class="page-header">
    <div>
        <p class="page-kicker">Delivery</p>
        <h1>Delivery log</h1>
        <p class="page-meta">Recent delivery states and failed-send errors.</p>
    </div>
</div>

<div class="table-wrap">
    <table>
        <thead>
            <tr><th>ID</th><th>Form</th><th>Status</th><th>Created</th><th>Sent</th><th>Error</th></tr>
        </thead>
        <tbody>
        <?php foreach ($entries as $entry): ?>
            <?php $status = (string) $entry['status']; ?>
            <tr>
                <td><a class="row-link" href="/admin/submissions/<?= (int) $entry['id'] ?>">#<?= (int) $entry['id'] ?></a></td>
                <td><?= htmlspecialchars((string) $entry['form_id'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><span class="status-pill <?= htmlspecialchars($statusClass($status), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?></span></td>
                <td><?= htmlspecialchars((string) $entry['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= !empty($entry['sent_at']) ? htmlspecialchars((string) $entry['sent_at'], ENT_QUOTES, 'UTF-8') : '<span class="muted">Not sent</span>' ?></td>
                <td><?= !empty($entry['error_message']) ? htmlspecialchars((string) $entry['error_message'], ENT_QUOTES, 'UTF-8') : '<span class="muted">None</span>' ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($entries === []): ?>
            <tr><td colspan="6" class="empty-state">No delivery entries yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
