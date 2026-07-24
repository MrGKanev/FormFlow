<?php
/** @var array<string, mixed> $submission */
$payload = json_decode((string) $submission['payload'], true) ?? [];
?>
<h1>Submission #<?= (int) $submission['id'] ?></h1>
<p><a href="/admin">Back to list</a></p>
<table>
    <tr><th>Form</th><td><?= htmlspecialchars((string) $submission['form_id'], ENT_QUOTES, 'UTF-8') ?></td></tr>
    <tr><th>Status</th><td><?= htmlspecialchars((string) $submission['status'], ENT_QUOTES, 'UTF-8') ?></td></tr>
    <tr><th>Created</th><td><?= htmlspecialchars((string) $submission['created_at'], ENT_QUOTES, 'UTF-8') ?></td></tr>
    <tr><th>Sent</th><td><?= htmlspecialchars((string) ($submission['sent_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td></tr>
    <tr><th>Error</th><td><?= htmlspecialchars((string) ($submission['error_message'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td></tr>
</table>
<h2>Payload</h2>
<table>
    <?php foreach ($payload as $field => $value): ?>
    <tr>
        <th><?= htmlspecialchars((string) $field, ENT_QUOTES, 'UTF-8') ?></th>
        <td><?= nl2br(htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8')) ?></td>
    </tr>
    <?php endforeach; ?>
</table>
