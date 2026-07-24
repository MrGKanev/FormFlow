<?php
/** @var array<string, array{api_key: string, created_at: string, updated_at: string}|null> $keys */
/** @var string $csrfToken */
?>
<div class="page-header">
    <div>
        <p class="page-kicker">Access control</p>
        <h1>API keys</h1>
        <p class="page-meta">Rotate per-form keys without changing configured form IDs.</p>
    </div>
</div>

<div class="table-wrap">
    <table>
        <thead>
            <tr><th>Form</th><th>API key</th><th>Last generated</th><th></th></tr>
        </thead>
        <tbody>
            <?php foreach ($keys as $formId => $entry): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($formId, ENT_QUOTES, 'UTF-8') ?></strong></td>
                    <td><?= $entry !== null ? '<code class="key-code">' . htmlspecialchars($entry['api_key'], ENT_QUOTES, 'UTF-8') . '</code>' : '<em>not generated</em>' ?></td>
                    <td><?= $entry !== null ? htmlspecialchars($entry['updated_at'], ENT_QUOTES, 'UTF-8') : '<span class="muted">Never</span>' ?></td>
                    <td class="actions">
                        <form method="POST" action="/admin/api-keys" class="inline">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="form_id" value="<?= htmlspecialchars($formId, ENT_QUOTES, 'UTF-8') ?>">
                            <button type="submit" class="secondary"><?= $entry !== null ? 'Regenerate' : 'Generate' ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($keys === []): ?>
                <tr><td colspan="4" class="empty-state">No forms are configured yet.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>
