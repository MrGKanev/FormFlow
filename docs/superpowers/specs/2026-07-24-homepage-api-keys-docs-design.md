# Homepage, per-form API keys, and documentation cleanup - design

Date: 2026-07-24
Status: approved

## Purpose

Four independent pieces of work, bundled into one spec/plan cycle because
they're small and were requested together:

1. A minimal public homepage at `/` (currently undefined - only `health`,
   `admin*`, and configured form IDs are routed).
2. Move per-form API keys out of `config/forms.php` and into the admin
   panel, backed by SQLite, so each form/site gets its own key that an
   admin can generate and rotate without touching code.
3. Translate `README.md` to English.
4. Remove documentation that's now redundant now that v1 and the admin
   panel are fully implemented: the two completed plan files, and
   `formflow.md` (the original pre-implementation design doc), folding
   any still-relevant operational content into `README.md`.

Out of scope: user accounts, per-form config editing from the admin UI,
multi-tenancy - none of that was requested.

## 1. Homepage

`public/index.php` special-cases `health` and `admin`/`admin/*` before
falling through to form handling. Add one more special case: when the
path is empty (`/`), return a static HTML page directly from the front
controller - no template engine, no new file dependency, consistent with
how `health` is handled inline today.

Content: project name, one-paragraph description ("self-hosted PHP form
backend, no framework required"), a short feature list (Turnstile,
rate limiting, spam filter, IP blocklist, admin panel with per-form API
keys), and links to `/admin` and `/health`. Inline `<style>`, no external
assets. Not configurable - this is a fixed static page, not a per-form
landing page.

## 2. Per-form API keys via the admin panel

### Storage

New SQLite table, created lazily like `admin_ip_whitelist`:

```sql
CREATE TABLE IF NOT EXISTS form_api_keys (
    form_id    TEXT PRIMARY KEY,
    api_key    TEXT NOT NULL UNIQUE,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
)
```

`form_id` matches the keys of `config/forms.php` (`contact`, `support`,
...). Forms themselves stay defined in config - only the key moves to
the database.

### Repository

`FormApiKeyRepositoryInterface`:

```php
interface FormApiKeyRepositoryInterface
{
    public function get(string $formId): ?string;
    public function regenerate(string $formId): string; // creates or replaces, returns the new key
    public function all(): array; // [form_id => ['api_key' => ..., 'created_at' => ..., 'updated_at' => ...]]
}
```

`SqliteFormApiKeyRepository` implements it, following
`SqliteAdminWhitelistRepository`'s constructor pattern (own PDO handle,
`mkdir` storage dir if needed, WAL + busy_timeout pragmas, schema
creation in the constructor). `regenerate()` generates
`bin2hex(random_bytes(32))` and upserts (`INSERT ... ON CONFLICT(form_id)
DO UPDATE SET api_key = ..., updated_at = ...`).

### FormHandler

Remove the `api_key` config field entirely from `config/forms.php`.
`FormHandler` gains a constructor dependency on
`FormApiKeyRepositoryInterface`. `assertApiKey()` changes from reading
`$config['api_key']` to:

```php
$expectedKey = $this->apiKeys->get($formId);

if ($expectedKey === null) {
    return; // no key generated yet for this form - not enforced
}

if (!hash_equals($expectedKey, (string) ($_POST['_key'] ?? ''))) {
    throw new InvalidArgumentException('Invalid API key.');
}
```

This preserves today's behavior for forms with no key (submissions pass
freely) and only starts enforcing once an admin visits the new page and
generates a key for that form - confirmed with the user as the desired
transition (no big-bang break for `support`, and `contact`'s current
placeholder config key stops being a real secret without a forced
migration step).

### Admin UI

New route `/admin/api-keys` (`AdminController`):

- `GET`: renders a table with one row per key in `config/forms.php`,
  showing form ID, key (monospace, full value - same trust boundary as
  the rest of the admin panel) or "not generated" if `repository->get()`
  returns null, and generated/rotated timestamp.
- `POST` (CSRF-protected, same pattern as `/admin/whitelist`): `action`
  is always "regenerate", `form_id` must be one of the configured form
  IDs (reject otherwise); calls `repository->regenerate($formId)` and
  redirects back to `/admin/api-keys`.

Linked from the dashboard nav next to the existing "IP whitelist" link.

### Tests

- `SqliteFormApiKeyRepositoryTest`: schema creation, `get()` returns null
  before generation, `regenerate()` returns a new key and persists it,
  calling `regenerate()` again replaces the key (old key not accepted
  anymore, `get()` reflects the new one).
- `FormHandlerTest`: replace the config-driven `api_key` assertions with
  a fake `FormApiKeyRepositoryInterface` (no key → passes; key set +
  wrong `_key` → 422; key set + correct `_key` → passes).
- `AdminControllerTest`: `GET /admin/api-keys` lists configured forms
  with/without keys; `POST` regenerate requires CSRF, rejects unknown
  `form_id`, and changes the stored key.

## 3. README to English

Translate `README.md` in place - same structure and links, English
prose. `formflow.md` is being removed (see below) rather than
translated, since it's superseded by the actual implementation and by
`docs/superpowers/specs/*`.

## 4. Documentation cleanup

Remove, now that both are fully implemented and committed (confirmed via
`.superpowers/sdd/progress.md` - all tasks for both plans complete):

- `docs/superpowers/plans/2026-07-23-formflow-v1-implementation.md`
- `docs/superpowers/plans/2026-07-24-admin-panel-implementation.md`

Remove `formflow.md` entirely. It's the original pre-implementation
design/planning document (1831 lines): rationale, code sketches that
predate the real `src/` implementation, and decisions already superseded
by `docs/superpowers/specs/2026-07-24-admin-panel-design.md` and the
code itself. Before deleting, fold the operational reference material
that isn't duplicated anywhere else into `README.md` as new English
sections:

- Nginx and Apache reverse-proxy config examples
- `storage/` directory permissions and required PHP extensions checklist
- Backup guidance
- Data retention / GDPR notes
- The deployment checklist (updated to mention per-form API keys instead
  of the removed `config/forms.php['api_key']`)

Everything else in `formflow.md` (original feature narrative, v1/v2
scope discussion, code sketches for classes that now exist in `src/`,
the "additions to the plan" section) is dropped - it's either redundant
with the code, or with the admin panel spec.

`docs/superpowers/specs/*` are untouched (design rationale, not task
checklists).

## Migration note

Deploying this change requires operators to visit `/admin/api-keys` and
generate a key for any form that should require one - the old
`config/forms.php['contact']['api_key']` value is dropped, not migrated,
since it was a placeholder value in the shipped config anyway.
