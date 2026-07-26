<?php
/** @var string|null $error */
/** @var list<array<string, mixed>> $users */
/** @var string $csrfToken */
/** @var string $bootstrapUsername */
?>
<div class="page-header">
    <div>
        <p class="page-kicker">Access control</p>
        <h1>Admin users</h1>
        <p class="page-meta">Additional admin users can only be created from this panel.</p>
    </div>
</div>

<?php if ($error !== null): ?>
    <p class="banner error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>

<div class="split-grid">
    <form method="POST" action="/admin/users">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="action" value="create">
        <h2>New admin user</h2>
        <label><span>Username</span><input type="text" name="username" required autocomplete="username"></label>
        <label><span>Password</span><input type="password" name="password" required autocomplete="new-password"></label>
        <label><span>TOTP secret</span><input type="text" name="totp_secret" placeholder="Optional"></label>
        <div class="form-actions"><button type="submit">Create user</button></div>
    </form>

    <section class="panel">
        <div class="section-heading">
            <h2>Users</h2>
            <span class="badge good"><?= count($users) + 1 ?></span>
        </div>
        <ul class="form-list">
            <li>
                <strong><?= htmlspecialchars($bootstrapUsername, ENT_QUOTES, 'UTF-8') ?></strong>
                <span>Bootstrap .env user</span>
                <code>.env</code>
            </li>
            <?php foreach ($users as $user): ?>
                <li>
                    <strong><?= htmlspecialchars((string) $user['username'], ENT_QUOTES, 'UTF-8') ?></strong>
                    <span><?= htmlspecialchars((string) $user['created_at'], ENT_QUOTES, 'UTF-8') ?></span>
                    <form method="POST" action="/admin/users" class="inline">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= (int) $user['id'] ?>">
                        <button type="submit" class="secondary">Delete</button>
                    </form>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>
</div>
