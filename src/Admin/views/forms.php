<?php
/** @var string|null $error */
/** @var array<string, array<string, mixed>> $forms */
/** @var list<string> $dynamicFormIds */
/** @var array<string, array<string, mixed>> $apiKeys */
/** @var string $turnstileSiteKey */
/** @var string $csrfToken */
/** @var array<string, mixed> $values */
?>
<div class="page-header">
    <div>
        <p class="page-kicker">Configuration</p>
        <h1>Forms</h1>
        <p class="page-meta">Review existing endpoints, integration snippets, and per-form controls.</p>
    </div>
    <div class="header-actions">
        <a href="/admin/forms/new" class="button">New form</a>
    </div>
</div>

<?php if ($error !== null): ?>
    <div class="banner error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<section class="panel">
    <div class="section-heading">
        <h2>Configured forms</h2>
        <span class="badge good"><?= count($forms) ?></span>
    </div>

    <ul class="form-list prominent">
        <?php foreach ($forms as $formId => $config): ?>
            <?php
            $endpoint = '/' . $formId;
            $apiKey = $apiKeys[$formId]['api_key'] ?? '';
            $snippet = '<form method="POST" action="' . $endpoint . '" enctype="multipart/form-data">' . PHP_EOL
                . '  <input type="hidden" name="_key" value="' . $apiKey . '">' . PHP_EOL
                . '  <input type="text" name="_website" tabindex="-1" autocomplete="off" hidden>' . PHP_EOL
                . '  <input type="email" name="email">' . PHP_EOL
                . '  <textarea name="message"></textarea>' . PHP_EOL
                . '  <input type="file" name="attachment">' . PHP_EOL
                . (!empty($config['turnstile']) && $turnstileSiteKey !== '' ? '  <div class="cf-turnstile" data-sitekey="' . $turnstileSiteKey . '"></div>' . PHP_EOL : '')
                . '  <button type="submit">Send</button>' . PHP_EOL
                . '</form>' . (!empty($config['turnstile']) && $turnstileSiteKey !== '' ? PHP_EOL . '<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>' : '');
            ?>
            <li>
                <strong><?= htmlspecialchars($formId, ENT_QUOTES, 'UTF-8') ?></strong>
                <span><?= htmlspecialchars((string) ($config['recipient'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                <code>/<?= htmlspecialchars($formId, ENT_QUOTES, 'UTF-8') ?></code>
                <span><?= !empty($config['require_api_key']) ? 'API key required' : 'API key optional' ?></span>
                <textarea readonly rows="7"><?= htmlspecialchars($snippet, ENT_QUOTES, 'UTF-8') ?></textarea>
                <span class="form-actions">
                    <a class="button secondary compact" href="/admin/forms/<?= rawurlencode((string) $formId) ?>/edit">Edit</a>
                    <?php if (in_array((string) $formId, $dynamicFormIds, true)): ?>
                        <form method="POST" action="/admin/forms/<?= rawurlencode((string) $formId) ?>/delete" class="inline">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                            <button type="submit" class="secondary">Delete</button>
                        </form>
                    <?php endif; ?>
                </span>
            </li>
        <?php endforeach; ?>
        <?php if ($forms === []): ?>
            <li class="empty-state">No forms are configured yet.</li>
        <?php endif; ?>
    </ul>
</section>
