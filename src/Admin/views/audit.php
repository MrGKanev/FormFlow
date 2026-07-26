<?php
/** @var list<array<string, mixed>> $entries */
?>
<div class="page-header">
    <div>
        <p class="page-kicker">Security</p>
        <h1>Audit log</h1>
        <p class="page-meta">Recent admin actions.</p>
    </div>
</div>

<div class="table-wrap">
    <table>
        <thead>
            <tr><th>Time</th><th>User</th><th>Action</th><th>Detail</th></tr>
        </thead>
        <tbody>
        <?php foreach ($entries as $entry): ?>
            <tr>
                <td><?= htmlspecialchars((string) $entry['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= !empty($entry['username']) ? htmlspecialchars((string) $entry['username'], ENT_QUOTES, 'UTF-8') : '<span class="muted">System</span>' ?></td>
                <td><code><?= htmlspecialchars((string) $entry['action'], ENT_QUOTES, 'UTF-8') ?></code></td>
                <td><?= htmlspecialchars((string) $entry['detail'], ENT_QUOTES, 'UTF-8') ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($entries === []): ?>
            <tr><td colspan="4" class="empty-state">No audit entries yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
