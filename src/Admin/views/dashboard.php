<?php
/** @var list<array<string, mixed>> $submissions */
/** @var int $total */
/** @var int $page */
/** @var int $perPage */
/** @var string|null $formId */
/** @var string|null $status */
?>
<h1>Submissions</h1>
<form method="GET" action="/admin">
    <input type="text" name="form_id" placeholder="form id" value="<?= htmlspecialchars((string) $formId, ENT_QUOTES, 'UTF-8') ?>">
    <input type="text" name="status" placeholder="status" value="<?= htmlspecialchars((string) $status, ENT_QUOTES, 'UTF-8') ?>">
    <button type="submit">Filter</button>
</form>
<table>
    <tr><th>ID</th><th>Form</th><th>Status</th><th>Created</th></tr>
    <?php foreach ($submissions as $submission): ?>
    <tr>
        <td><a href="/admin/submissions/<?= (int) $submission['id'] ?>"><?= (int) $submission['id'] ?></a></td>
        <td><?= htmlspecialchars((string) $submission['form_id'], ENT_QUOTES, 'UTF-8') ?></td>
        <td><?= htmlspecialchars((string) $submission['status'], ENT_QUOTES, 'UTF-8') ?></td>
        <td><?= htmlspecialchars((string) $submission['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
    </tr>
    <?php endforeach; ?>
</table>
<p>Total: <?= $total ?> | Page <?= $page ?> of <?= (int) max(1, ceil($total / $perPage)) ?></p>
