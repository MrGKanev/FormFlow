<?php
/** @var string|null $error */
/** @var list<array{id: int, ip_or_cidr: string, note: ?string, created_at: string}> $entries */
/** @var list<string> $configuredIps */
/** @var string $csrfToken */
?>
<div class="page-header">
    <div>
        <p class="page-kicker">Security</p>
        <h1>IP whitelist</h1>
        <p class="page-meta">Control which IP addresses can access the admin console.</p>
    </div>
</div>
<?php if ($error !== null): ?>
    <p class="banner error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>

<div class="split-grid">
    <section class="panel">
        <div class="section-heading">
            <h2>Static</h2>
            <span class="badge warn">config/admin.php</span>
        </div>
        <ul class="chip-list">
            <?php foreach ($configuredIps as $ip): ?>
                <li><?= htmlspecialchars($ip, ENT_QUOTES, 'UTF-8') ?></li>
            <?php endforeach; ?>
            <?php if ($configuredIps === []): ?>
                <li>No static entries</li>
            <?php endif; ?>
        </ul>
    </section>

    <form method="POST" action="/admin/whitelist">
        <h2>Add entry</h2>
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="action" value="add">
        <label><span>IP or CIDR</span><input type="text" name="ip_or_cidr" placeholder="203.0.113.10" required></label>
        <label><span>Note</span><input type="text" name="note" placeholder="Office network"></label>
        <button type="submit">Add</button>
    </form>
</div>

<div class="section-heading">
    <h2>Dynamic</h2>
</div>
<div class="table-wrap">
    <table>
        <thead>
            <tr><th>IP/CIDR</th><th>Note</th><th>Added</th><th></th></tr>
        </thead>
        <tbody>
            <?php foreach ($entries as $entry): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($entry['ip_or_cidr'], ENT_QUOTES, 'UTF-8') ?></strong></td>
                    <td><?= $entry['note'] !== null && $entry['note'] !== '' ? htmlspecialchars((string) $entry['note'], ENT_QUOTES, 'UTF-8') : '<span class="muted">No note</span>' ?></td>
                    <td><?= htmlspecialchars($entry['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td class="actions">
                        <form method="POST" action="/admin/whitelist" class="inline">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="action" value="remove">
                            <input type="hidden" name="id" value="<?= (int) $entry['id'] ?>">
                            <button type="submit" class="secondary">Remove</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($entries === []): ?>
                <tr><td colspan="4" class="empty-state">No dynamic whitelist entries yet.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>
