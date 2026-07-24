<?php
/** @var string|null $error */
/** @var list<array{id: int, ip_or_cidr: string, note: ?string, created_at: string}> $entries */
/** @var list<string> $configuredIps */
/** @var string $csrfToken */
?>
<h1>IP whitelist</h1>
<?php if ($error !== null): ?>
    <p class="banner error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>

<h2>Static (config/admin.php, read-only)</h2>
<ul>
<?php foreach ($configuredIps as $ip): ?>
    <li><?= htmlspecialchars($ip, ENT_QUOTES, 'UTF-8') ?></li>
<?php endforeach; ?>
</ul>

<h2>Dynamic</h2>
<table>
    <tr><th>IP/CIDR</th><th>Note</th><th>Added</th><th></th></tr>
    <?php foreach ($entries as $entry): ?>
    <tr>
        <td><?= htmlspecialchars($entry['ip_or_cidr'], ENT_QUOTES, 'UTF-8') ?></td>
        <td><?= htmlspecialchars((string) $entry['note'], ENT_QUOTES, 'UTF-8') ?></td>
        <td><?= htmlspecialchars($entry['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
        <td>
            <form method="POST" action="/admin/whitelist" class="inline">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="remove">
                <input type="hidden" name="id" value="<?= (int) $entry['id'] ?>">
                <button type="submit" class="secondary">Remove</button>
            </form>
        </td>
    </tr>
    <?php endforeach; ?>
</table>

<h2>Add entry</h2>
<form method="POST" action="/admin/whitelist">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="action" value="add">
    <label>IP or CIDR <input type="text" name="ip_or_cidr" required></label>
    <label>Note <input type="text" name="note"></label>
    <button type="submit">Add</button>
</form>
