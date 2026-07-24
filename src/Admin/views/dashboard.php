<?php
/** @var list<array<string, mixed>> $submissions */
/** @var int $total */
/** @var int $page */
/** @var int $perPage */
/** @var string|null $formId */
/** @var string|null $status */
?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><title>formflow admin — submissions</title></head>
<body>
<h1>Submissions</h1>
<p><a href="/admin/whitelist">Manage IP whitelist</a> | <a href="/admin/logout">Log out</a></p>
<form method="GET" action="/admin">
    <input type="text" name="form_id" placeholder="form id" value="<?= htmlspecialchars((string) $formId, ENT_QUOTES, 'UTF-8') ?>">
    <input type="text" name="status" placeholder="status" value="<?= htmlspecialchars((string) $status, ENT_QUOTES, 'UTF-8') ?>">
    <button type="submit">Filter</button>
</form>
<table border="1" cellpadding="4">
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
</body>
</html>
