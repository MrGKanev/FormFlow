<?php
/** @var list<array<string, mixed>> $entries */
/** @var list<array<string, mixed>> $webhookEntries */
$statusClass = static fn (string $value): string => 'status-' . preg_replace('/[^a-z0-9_-]+/i', '-', strtolower($value));
$displayEndpoint = static function (?string $url): string {
    if ($url === null || trim($url) === '') {
        return 'Not retained';
    }

    $parts = parse_url($url);
    $host = (string) ($parts['host'] ?? 'webhook');
    return $host . '/••••';
};
?>
<div class="page-header">
    <div>
        <p class="page-kicker">Delivery</p>
        <h1>Delivery log</h1>
        <p class="page-meta">Recent delivery states and failed-send errors.</p>
    </div>
</div>

<div class="page-header compact">
    <div>
        <h2>Integration deliveries</h2>
        <p class="page-meta">Each configured integration is retried up to three times before a failure is recorded.</p>
    </div>
</div>

<div class="page-header compact">
    <div>
        <h2>Email deliveries</h2>
        <p class="page-meta">Submission email status remains separate from optional integration notifications.</p>
    </div>
</div>

<div class="table-wrap">
    <table>
        <thead>
            <tr><th>Form</th><th>Integration</th><th>Status</th><th>Attempts</th><th>Created</th><th>Inspector</th></tr>
        </thead>
        <tbody>
        <?php foreach ($webhookEntries as $entry): ?>
            <?php $status = (string) $entry['status']; ?>
            <tr>
                <td><?= htmlspecialchars((string) $entry['form_id'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars(ucfirst((string) $entry['channel']), ENT_QUOTES, 'UTF-8') ?></td>
                <td><span class="status-pill <?= htmlspecialchars($statusClass($status), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?></span></td>
                <td><?= (int) $entry['attempts'] ?></td>
                <td><?= htmlspecialchars((string) $entry['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
                <td class="delivery-inspector">
                    <details>
                        <summary>Inspect</summary>
                        <div class="inspector-panel">
                            <span class="inspector-label">Endpoint</span>
                            <code><?= htmlspecialchars($displayEndpoint(isset($entry['url']) ? (string) $entry['url'] : null), ENT_QUOTES, 'UTF-8') ?></code>
                            <?php if (!empty($entry['error_message'])): ?><span class="inspector-label">Last error</span><p class="error-text"><?= htmlspecialchars((string) $entry['error_message'], ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
                            <span class="inspector-label">Payload</span>
                            <?php $payload = !empty($entry['payload_json']) ? json_decode((string) $entry['payload_json'], true) : null; ?>
                            <pre><?= htmlspecialchars($payload === null ? 'Payload was not retained for this delivery.' : (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8') ?></pre>
                            <?php if (!empty($entry['url']) && !empty($entry['payload_json'])): ?>
                                <form method="POST" action="/admin/delivery/<?= (int) $entry['id'] ?>/replay" class="inline">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                    <button type="submit" class="secondary compact" data-confirm="Queue a new replay of this webhook delivery?">Replay</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </details>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($webhookEntries === []): ?>
            <tr><td colspan="6" class="empty-state">No integration deliveries yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<div class="table-wrap">
    <table>
        <thead>
            <tr><th>ID</th><th>Form</th><th>Status</th><th>Created</th><th>Sent</th><th>Error</th></tr>
        </thead>
        <tbody>
        <?php foreach ($entries as $entry): ?>
            <?php $status = (string) $entry['status']; ?>
            <tr>
                <td><a class="row-link" href="/admin/submissions/<?= (int) $entry['id'] ?>">#<?= (int) $entry['id'] ?></a></td>
                <td><?= htmlspecialchars((string) $entry['form_id'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><span class="status-pill <?= htmlspecialchars($statusClass($status), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?></span></td>
                <td><?= htmlspecialchars((string) $entry['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= !empty($entry['sent_at']) ? htmlspecialchars((string) $entry['sent_at'], ENT_QUOTES, 'UTF-8') : '<span class="muted">Not sent</span>' ?></td>
                <td><?= !empty($entry['error_message']) ? htmlspecialchars((string) $entry['error_message'], ENT_QUOTES, 'UTF-8') : '<span class="muted">None</span>' ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($entries === []): ?>
            <tr><td colspan="6" class="empty-state">No delivery entries yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
