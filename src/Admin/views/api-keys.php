<?php
/** @var array<string, array{api_key: string, created_at: string, updated_at: string}|null> $keys */
/** @var string $csrfToken */
?>
<h1>API keys</h1>
<table>
    <tr><th>Form</th><th>API key</th><th>Last generated</th><th></th></tr>
    <?php foreach ($keys as $formId => $entry): ?>
    <tr>
        <td><?= htmlspecialchars($formId, ENT_QUOTES, 'UTF-8') ?></td>
        <td><?= $entry !== null ? '<code>' . htmlspecialchars($entry['api_key'], ENT_QUOTES, 'UTF-8') . '</code>' : '<em>not generated</em>' ?></td>
        <td><?= $entry !== null ? htmlspecialchars($entry['updated_at'], ENT_QUOTES, 'UTF-8') : '' ?></td>
        <td>
            <form method="POST" action="/admin/api-keys" class="inline">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="form_id" value="<?= htmlspecialchars($formId, ENT_QUOTES, 'UTF-8') ?>">
                <button type="submit" class="secondary"><?= $entry !== null ? 'Regenerate' : 'Generate' ?></button>
            </form>
        </td>
    </tr>
    <?php endforeach; ?>
</table>
