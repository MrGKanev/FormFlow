<?php
/** @var array<string, mixed> $submission */
$payload = json_decode((string) $submission['payload'], true) ?? [];
$submissionStatus = (string) $submission['status'];
$statusClass = 'status-' . preg_replace('/[^a-z0-9_-]+/i', '-', strtolower($submissionStatus));
?>
<div class="page-header">
    <div>
        <p class="page-kicker">Submission detail</p>
        <h1>Submission #<?= (int) $submission['id'] ?></h1>
        <p class="page-meta"><?= htmlspecialchars((string) $submission['form_id'], ENT_QUOTES, 'UTF-8') ?></p>
    </div>
    <div class="header-actions">
        <a href="/admin" class="button secondary">Back to list</a>
    </div>
</div>

<div class="table-wrap">
    <table class="key-value">
        <tbody>
            <tr><th>Form</th><td><?= htmlspecialchars((string) $submission['form_id'], ENT_QUOTES, 'UTF-8') ?></td></tr>
            <tr><th>Status</th><td><span class="status-pill <?= htmlspecialchars($statusClass, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($submissionStatus, ENT_QUOTES, 'UTF-8') ?></span></td></tr>
            <tr><th>Created</th><td><time datetime="<?= htmlspecialchars((string) $submission['created_at'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string) $submission['created_at'], ENT_QUOTES, 'UTF-8') ?></time></td></tr>
            <tr><th>Sent</th><td><?= !empty($submission['sent_at']) ? htmlspecialchars((string) $submission['sent_at'], ENT_QUOTES, 'UTF-8') : '<span class="muted">Not sent</span>' ?></td></tr>
            <tr><th>Error</th><td><?= !empty($submission['error_message']) ? htmlspecialchars((string) $submission['error_message'], ENT_QUOTES, 'UTF-8') : '<span class="muted">None</span>' ?></td></tr>
        </tbody>
    </table>
</div>

<div class="section-heading">
    <h2>Payload</h2>
</div>
<div class="table-wrap">
    <table class="key-value">
        <tbody>
            <?php foreach ($payload as $field => $value): ?>
                <tr>
                    <th><?= htmlspecialchars((string) $field, ENT_QUOTES, 'UTF-8') ?></th>
                    <td><?= nl2br(htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8')) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($payload === []): ?>
                <tr><td colspan="2" class="empty-state">No payload fields were stored for this submission.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>
