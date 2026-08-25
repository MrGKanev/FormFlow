<?php
/** @var array<string, mixed> $submission */
/** @var string|null $error */
$payload = json_decode((string) $submission['payload'], true) ?? [];
$displayPayload = \formflow\SubmissionPayloadFormatter::displayFields(is_array($payload) ? $payload : []);
$uploads = [];

if (is_array($payload)) {
    foreach ($payload as $field => $value) {
        if (is_array($value) && ($value['type'] ?? null) === 'upload') {
            $uploads[(string) $field] = $value;
        }
    }
}

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

<?php if (($error ?? null) !== null): ?>
    <p class="banner error"><?= htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>

<div class="panel">
    <div class="section-heading">
        <h2>Actions</h2>
    </div>
    <div class="form-actions">
        <form method="POST" action="/admin/submissions/<?= (int) $submission['id'] ?>/action" class="inline">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="review">
            <button type="submit" class="secondary">Mark reviewed</button>
        </form>
        <form method="POST" action="/admin/submissions/<?= (int) $submission['id'] ?>/action" class="inline">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="resend">
            <button type="submit" class="secondary">Resend email</button>
        </form>
        <form method="POST" action="/admin/submissions/<?= (int) $submission['id'] ?>/action" class="inline">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="delete">
            <button type="submit" class="secondary">Delete</button>
        </form>
    </div>
</div>

<div class="table-wrap">
    <table class="key-value">
        <tbody>
            <tr><th>Form</th><td><?= htmlspecialchars((string) $submission['form_id'], ENT_QUOTES, 'UTF-8') ?></td></tr>
            <tr><th>Status</th><td><span class="status-pill <?= htmlspecialchars($statusClass, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($submissionStatus, ENT_QUOTES, 'UTF-8') ?></span></td></tr>
            <tr><th>Created</th><td><time datetime="<?= htmlspecialchars((string) $submission['created_at'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string) $submission['created_at'], ENT_QUOTES, 'UTF-8') ?></time></td></tr>
            <tr><th>Sent</th><td><?= !empty($submission['sent_at']) ? htmlspecialchars((string) $submission['sent_at'], ENT_QUOTES, 'UTF-8') : '<span class="muted">Not sent</span>' ?></td></tr>
            <tr><th>Reviewed</th><td><?= !empty($submission['reviewed_at']) ? htmlspecialchars((string) $submission['reviewed_at'], ENT_QUOTES, 'UTF-8') : '<span class="muted">Not reviewed</span>' ?></td></tr>
            <tr><th>Error</th><td><?= !empty($submission['error_message']) ? htmlspecialchars((string) $submission['error_message'], ENT_QUOTES, 'UTF-8') : '<span class="muted">None</span>' ?></td></tr>
        </tbody>
    </table>
</div>

<?php if ($uploads !== []): ?>
    <div class="section-heading">
        <h2>Uploads</h2>
    </div>
    <div class="table-wrap">
        <table class="key-value">
            <tbody>
                <?php foreach ($uploads as $field => $upload): ?>
                    <tr>
                        <th><?= htmlspecialchars($field, ENT_QUOTES, 'UTF-8') ?></th>
                        <td>
                            <a class="button secondary" href="/admin/submissions/<?= (int) $submission['id'] ?>/uploads/<?= rawurlencode($field) ?>">
                                Download <?= htmlspecialchars((string) ($upload['original_name'] ?? 'upload'), ENT_QUOTES, 'UTF-8') ?>
                            </a>
                            <?php if (isset($upload['size_bytes'])): ?>
                                <span class="muted"><?= htmlspecialchars(\formflow\SubmissionPayloadFormatter::displayValue($upload), ENT_QUOTES, 'UTF-8') ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<div class="section-heading">
    <h2>Payload</h2>
</div>
<div class="table-wrap">
    <table class="key-value">
        <tbody>
            <?php foreach ($displayPayload as $field => $value): ?>
                <tr>
                    <th><?= htmlspecialchars((string) $field, ENT_QUOTES, 'UTF-8') ?></th>
                    <td><?= nl2br(htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8')) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($displayPayload === []): ?>
                <tr><td colspan="2" class="empty-state">No payload fields were stored for this submission.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>
