# formflow

Minimal self-hosted PHP backend for accepting HTML forms (like Web3Forms/Formspree), without a heavy framework.

## Highlights

- Self-hosted form endpoints for static sites, landing pages, portfolios, and client projects.
- Per-form recipients, redirects, rate limits, upload rules, API-key protection, and notification destinations.
- Selectable CAPTCHA providers: Cloudflare Turnstile, hCaptcha, Google reCAPTCHA v2, and Friendly Captcha.
- Built-in spam controls: honeypot field, keyword filtering, allowed origins/referers, daily caps, and global IP blocklist.
- Delivery integrations for email plus Discord, Slack, Telegram, and generic webhooks, with per-form webhook overrides.
- Responsive light/dark admin workspace with source-backed analytics, saved submission views, webhook inspection/replay, form templates, automatic replies, CSV export, audit log, users, backups, and config import/export.

## Requirements

- PHP 8.4.1+ (including PHP 8.5)
- Composer
- PHP extensions: `curl`, `fileinfo`, `json`, `mbstring`, `pdo`, `pdo_sqlite`

## Installation

```bash
composer install
```

`composer.lock` is the source of truth for installed packages. Run `composer install` after dependency changes and before tests or deployments; `vendor/` is generated runtime state.

Alternatively, skip the wizard and configure by hand:

```bash
cp .env.example .env
```

Edit `.env` with real SMTP/CAPTCHA data, or use `/admin/settings` after install. You can keep starter forms in `config/forms.php` or create new forms from `/admin/forms`.

## Running locally

```bash
php -S localhost:8080 -t public
```

`GET /` returns a short public home page with links to the GitHub repository and `/health`. The admin panel is intentionally shown there only for localhost development.

Health check page:

```bash
open http://localhost:8080/health
```

Machine-readable health output:

```bash
curl -H "Accept: application/json" http://localhost:8080/health
# or
curl http://localhost:8080/health?format=json
```

`/health` is a public liveness endpoint. It intentionally exposes only the service status and timestamp, not installation, database, mail, CAPTCHA, or PHP configuration details.

Test submission (the `contact` form from `config/forms.php`):

```bash
curl -X POST http://localhost:8080/contact \
    -H "Origin: https://example.com" \
    -H "Accept: application/json" \
    -F "name=Gabriel" \
    -F "email=gabriel@example.com" \
    -F "message=Test submission"
```

If `contact` has an API key, also add `-F "_key=<the generated key>"`. Database-backed forms receive a key automatically when created; copy it from their integration snippet on `/admin/forms`.

By default `contact` has `'captcha_provider' => 'turnstile'`, and the command above does not send `cf-turnstile-response` - expect `422 CAPTCHA validation failed`. For a local smoke test without a real Cloudflare token, temporarily change it to `'captcha_provider' => 'none'` in `config/forms.php`, or send a valid token obtained from a real widget.

## Admin panel

Minimal admin panel for reviewing submissions, protected with login + IP whitelist.

Routes:

- `/admin` - dashboard with paginated submissions, keyboard search (`/`), date range filters, browser-local saved views, page size control, select-all bulk actions, and form analytics.
- `/admin/analytics` - 7/30/90-day submission trends, delivery rate, status breakdown, and top forms, optionally filtered by form.
- `/admin/forms` - review configured forms and copy integration snippets with one click. Snippets use `APP_URL`, so forms can submit from separate websites to one hosted FormFlow instance.
- `/admin/forms/new` - create a new database-backed form endpoint from contact, newsletter, support, or application templates.
- `/admin/forms/{id}/edit` - edit an existing form, storing changes as a database-backed configuration.
- `/admin/system` - operational status for storage, delivery queues, trusted proxy setup, and runtime checks.
- `/admin/login` - login form.
- `/admin/logout` - logout, redirect to `/admin/login`.
- `/admin/submissions/{id}` - details for a specific submission, with review, resend, and delete actions.
- `/admin/settings` - global configuration, organised into General, Delivery, Protection, Admin access, and Maintenance tabs.
- `/admin/integrations` - notification integrations for Discord, Slack, Telegram, and generic webhooks.
- `/admin/whitelist` - IP/CIDR access control for the admin panel, grouped under the Settings sub-navigation.
- `/admin/users` - create/delete additional admin users, grouped under the Settings sub-navigation.
- `/admin/audit` - recent admin actions, grouped under the Settings sub-navigation.
- `/admin/delivery` - email and integration delivery logs, including redacted endpoint inspection, retained payload preview, retry attempts, failures, and replay for newly recorded webhook deliveries.
- `/admin/export` - CSV export of submissions, respecting dashboard search/date/status/form filters.
- `/admin/backup` - download a SQLite database backup.
- `/admin/config/export` and `/admin/config/import` - move settings/forms/security config as JSON.
- `/admin/recovery?token=...` - one-time bootstrap password recovery path after generating a recovery token from settings.

Requires:

- `ADMIN_USERNAME` and `ADMIN_PASSWORD_HASH` in `.env` (the hash is generated with `php -r "echo password_hash('...', PASSWORD_DEFAULT), PHP_EOL;"`).
- At least one IP (or CIDR) in `config/admin.php` → `allowed_ips`, or an entry in the dynamic whitelist (the `admin_ip_whitelist` table in SQLite, managed via `/admin/whitelist`).

## Tests

```bash
composer install
composer validate --strict
composer audit --no-interaction
vendor/bin/phpunit
```

## Releases and versioning

`composer.json` is the single source of truth for the FormFlow version. The same value is displayed in the admin interface and returned by:

```bash
php bin/formflow --version
```

For a release, update only the `version` field in `composer.json`, refresh the lock metadata, commit the change, and create a matching tag:

```bash
composer update --lock --no-install --no-scripts
git tag -a v0.2.0 -m "FormFlow 0.2.0"
git push origin v0.2.0
```

## Operations

Prune old submissions using `RETENTION_DAYS` or an explicit value:

```bash
php bin/formflow retention:prune --days=180
```

Retry failed email deliveries:

```bash
php bin/formflow mail:retry-failed --limit=100
```

Email delivery can also be queued:

```dotenv
MAIL_DELIVERY_MODE=queue
```

Then run the mail worker from cron:

```bash
php bin/formflow mail:work --limit=100
```

Each worker run records its status, summary, last run and last successful run under `storage/runtime`, which is shown on `/admin/system`. A successful worker run means the run completed without terminal delivery failures; pending retries and individual delivery outcomes are reported separately.

Webhook delivery can stay synchronous, or be queued by setting:

```dotenv
WEBHOOK_DELIVERY_MODE=queue
```

Then run the worker from cron:

```bash
php bin/formflow webhooks:retry --limit=100
```

Example cron entries:

```cron
* * * * * cd /var/www/formflow && php bin/formflow mail:work --limit=100 >/dev/null 2>&1
* * * * * cd /var/www/formflow && php bin/formflow webhooks:retry --limit=100 >/dev/null 2>&1
15 3 * * * cd /var/www/formflow && php bin/formflow retention:prune --days=180 >/dev/null 2>&1
```

In queue mode, webhooks have at most three attempts. Network failures, HTTP 408/429 and 5xx responses retry; other HTTP failures finish immediately. Retry delays use exponential equal jitter (30–60 seconds after the first attempt, 60–120 after the second), capped at one hour. Valid `Retry-After` seconds or HTTP dates increase the delay, bounded to 24 hours. Redirects are not followed. Malformed stored payloads, including nonempty top-level JSON arrays or numeric top-level keys, fail without an outbound request. See [HTTP Retry-After semantics](https://www.rfc-editor.org/rfc/rfc9110.html#section-10.2.3).

Queued requests include `X-FormFlow-Delivery-ID`. The persisted random ID stays the same for automatic retries and crash recovery; receivers should retain processed IDs to avoid duplicate effects. Manual replay creates a new ID and requests another delivery. Delivery is at least once: a crash after receiver acceptance can resend the same ID, and receivers must implement deduplication to obtain that protection.

Synchronous library use also sends a stable ID across its immediate attempts and stops on permanent HTTP failures or a positive `Retry-After`, recording a failure for manual replay. Queue mode is recommended for scheduled retries that respect receiver delays.

### Delivery monitoring

```bash
php bin/formflow delivery:status
php bin/formflow delivery:check
```

Both commands emit JSON and exit with `0` when healthy or `1` when unhealthy. The snapshot includes pending/failed counts, the oldest pending delivery and its age, worker status and the last successful run. Pending work alerts when its age reaches `DELIVERY_QUEUE_MAX_AGE_SECONDS` (default 900), or a successful worker run is missing/stale according to `DELIVERY_WORKER_MAX_AGE_SECONDS` (default 900). Failed deliveries alert at `DELIVERY_FAILED_THRESHOLD` (default 1); a failed worker run also alerts.

For external monitoring, set a private random `OPERATIONS_API_TOKEN` and use HTTPS:

```bash
curl 'https://forms.example.com/api/delivery-status' \
  -H "Authorization: Bearer $OPERATIONS_API_TOKEN"
```

The protected GET endpoint returns the same status, disables caching, and responds with HTTP 503 when unhealthy. An empty token disables access. `/health` remains the public liveness check.

Optional email alerts require configured SMTP and `DELIVERY_ALERT_EMAIL`. Schedule `php bin/formflow delivery:check --notify` to send alerts; notification is opt-in, repeated incidents are suppressed for one hour, and recovery sends an update. Monitor the command's exit status independently because SMTP delivery can fail during an incident.

## Deployment

Docker:

```bash
mkdir -p storage/runtime storage/uploads
# If you already have a configured .env:
# cp .env storage/runtime/.env
sudo chown -R 33:33 storage
docker compose up -d --build
```

The included image serves `public/` through Apache with a read-only root filesystem. The writable `storage/` mount holds SQLite/uploads and `storage/runtime/.env`; the image links `.env` to that persistent file so installation and Settings can save configuration atomically. The named `formflow-config` volume persists PHP configuration files. Do not remove that volume when recreating the container. Keep the host storage directory writable by the container's `www-data` user (UID/GID 33). Container environment variables can override `.env`, so avoid duplicating Settings-managed values there. For PHP-FPM/nginx installs, use `deploy/nginx.conf` as a starting point and keep `storage/` outside the web root.

## Configuration

- Forms can be created from `/admin/forms`. Starter/static forms can also live in `config/forms.php`.
- Per form: recipient, `allowed_origins`, `subject`, `success_redirect`, `captcha_provider`, `require_api_key`, `rate_limit_per_ip` (`max`, `window_minutes`), `daily_limit`, `blocked_patterns`, upload limits, `delivery_channels`, optional per-form integration overrides, and optional `auto_reply` (`enabled`, `subject`, `body`). Auto-reply templates support submitted-field placeholders such as `{{name}}` and `{{email}}`, plus `{{form_id}}`, and are sent only when the submission has a valid `email` field. Upload rules support a size limit (1–100 MB), file-count limit (1–20), and an optional allow-list of filename extensions. Form endpoints accept all submitted user fields; system fields such as `_key`, `_website`, `cf-turnstile-response`, `h-captcha-response`, `g-recaptcha-response`, `frc-captcha-response`, and `csrf_token` are not stored or emailed. A key is generated automatically with every new database-backed form and included in its integration snippet. New admin-created forms require the API key by default.
- Global app settings can be edited from `/admin/settings`. It writes selected values to `.env`, login-rate-limit values to `config/admin.php`, and the global IP blocklist to `config/security.php`. Saving one Settings tab preserves values configured in the other tabs.
- Mail can be configured with standard SMTP fields: `SMTP_HOST`, `SMTP_PORT`, `SMTP_ENCRYPTION` (`tls`, `ssl`, or `none`), `SMTP_USERNAME`, `SMTP_PASSWORD`, `MAIL_FROM`, and `MAIL_FROM_NAME`. `MAILER_DSN` is still supported as an advanced override; when set, it takes precedence over the individual SMTP fields.
- Notification integrations are configured on `/admin/integrations`:
  - Discord: `DISCORD_WEBHOOK_URL`
  - Slack: `SLACK_WEBHOOK_URL`
  - Telegram: `TELEGRAM_BOT_TOKEN` and `TELEGRAM_CHAT_ID`
  - Generic webhook: `GENERIC_WEBHOOK_URL`; receives `{"form_id":"…","fields":{…}}`, which works with Zapier, Make, n8n, or a custom service.
  Each form selects which delivery channels receive its submissions. New forms start with no selected delivery channels. A form can override the global Discord, Slack, Telegram, or generic webhook settings when it needs a form-specific destination; blank override fields use the global integration settings. If no delivery channels are selected, no integration notifications are sent. Webhooks use up to two total attempts in sync mode or three through `webhooks:retry` in queue mode, and the final result is visible in `/admin/delivery`. Delivery notifications never prevent a successfully stored submission from being accepted.
- File uploads are accepted from multipart forms and stored under `storage/uploads`; payloads store metadata only: original filename, stored basename, size, and MIME type. Admin users download uploads through the submission detail page instead of exposing filesystem paths. Deleting submissions or running retention cleanup removes attached upload files as well. Configure per-form size, count, and extension rules before exposing an upload field in the form markup. Dangerous executable/script extensions are rejected even without an allow-list, and common file types are checked with MIME detection when PHP's `fileinfo` support is available.
- CAPTCHA providers are configured on `/admin/settings?tab=protection`: Cloudflare Turnstile (`TURNSTILE_SECRET`, `TURNSTILE_SITE_KEY`), hCaptcha (`HCAPTCHA_SECRET`, `HCAPTCHA_SITE_KEY`), Google reCAPTCHA v2 (`RECAPTCHA_SECRET`, `RECAPTCHA_SITE_KEY`), and Friendly Captcha (`FRIENDLY_CAPTCHA_API_KEY`, `FRIENDLY_CAPTCHA_SITE_KEY`). Form snippets include the matching widget script when the selected provider has a site key.
- Admin 2FA uses TOTP secrets (`ADMIN_TOTP_SECRET` for the bootstrap user, optional TOTP secret for DB-backed users).
- `config/security.php` - global IP blocklist (exact IP addresses or IPv4 CIDR ranges).

## Protections

Allowed origins/referer, honeypot field (`_website`), selectable CAPTCHA providers, per-form API key (generated with each new form and sent as a hidden field `_key`), rate limiting by IP+form with a configurable window, daily cap on submissions per form, global IP blocklist, simple case-insensitive spam filter by keywords/phrases.

**Reverse proxy:** By default, IP-based protections use `REMOTE_ADDR`. Behind Cloudflare/nginx, either set up `real_ip` at the web-server level or configure `trusted_proxies` in `config/security.php`. FormFlow only reads `CF-Connecting-IP`, `X-Forwarded-For`, or `X-Real-IP` when `REMOTE_ADDR` matches a trusted proxy entry.


### Backup

Create a complete data archive outside the public web root:

```bash
php bin/formflow backup:create --output=/private/backups/formflow.tar
php bin/formflow backup:restore --input=/private/backups/formflow.tar --target=/empty/restore-directory
```

All CLI commands accept `--root=/application` to select the installation. Stop incoming submissions and workers while creating the archive so the SQLite snapshot, uploaded files and configuration represent the same point in time. The archive includes the database, uploads, configuration and environment credentials. Keep it private, restrict access and apply a retention policy; it is not encrypted.

Restore requires an empty target and validates the archive before extracting. The archive contains application data, not application code or dependencies: deploy the matching application version separately, place the restored data/configuration in that installation, and set ownership for the web/worker user before starting services. For Docker, restore persistent configuration and storage mounts as well as the environment file.

Regularly restore into an isolated empty directory and verify a known submission, its upload and the configuration before trusting a backup. Automated tests cover archive creation and restoration, including data/configuration round trips and unsafe archives. The admin SQLite download remains a database-only backup.

### Data retention / GDPR

Submissions can contain personal data. Recommended practices:

- don't store raw IP addresses - use a monthly-rotating hash (formflow already does this via `IP_HASH_SECRET`);
- define a retention period and delete submissions older than it;
- don't collect fields you don't need;
- restrict access to the SQLite file;
- use HTTPS;
- describe the processing in your privacy policy.

Example: delete records older than 180 days:

```bash
php bin/formflow retention:prune --days=180
```

## Alternatives

If you want a more feature-rich solution, consider:

### Self-hosted

- [FormLander](https://github.com/karloscodes/formlander) - Go backend, more features, but not PHP friendly and needs Docker for easier deployment.

### Hosted

- [Web3Forms](https://web3forms.com/) - hosted, free tier, more features.
- [Formspree](https://formspree.io/) - hosted, free tier, more features.

### Signup and submission counts

In **Admin → Analytics**, select a form and the last 7, 30, or 90 calendar days
(UTC), then apply the filters or choose **Export CSV**. The per-form table and CSV
include total submissions, accepted submissions (blocked spam/honeypot excluded),
unique emails, and sent notification emails.

Unique emails are deduplicated after trimming whitespace and lowercasing the
payload's `email` field. Missing or empty emails are excluded; repeated submissions
still count toward submission totals. Overall unique emails are deduplicated across
forms, so per-form unique counts need not sum to the overall count. Newsletter
counts represent signups, not verified or currently active subscriptions. Deleted
or expired submissions are not counted.

For server-to-server access, set `ANALYTICS_API_TOKEN` in `.env` to a random secret
(for example, generate one with `php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'`).
An empty token disables access. This token can read aggregate counts for all forms;
keep it on your server and use HTTPS.

```sh
curl 'https://forms.example.com/api/analytics?form_id=newsletter&days=30' \
  -H "Authorization: Bearer $ANALYTICS_API_TOKEN"
```

The read-only GET endpoint returns `days`, `form_id`, and `analytics` containing
`summary`, `trend`, `statuses`, and `forms`. Summary counters include `total`,
`accepted`, `unique_emails`, `sent`, `failed`, `blocked`, and `delivery_rate`.
Omit `form_id` for all forms. Invalid filters return 422, missing/invalid tokens
return 401, and authenticated non-GET requests return 405. No individual email
addresses or submission payloads are returned.

## Development checks

```bash
composer install
vendor/bin/phpunit
composer analyse
```

PHPStan runs at level 6 with explicit collection and result types. The PHPUnit suite includes separate-process SQLite concurrency checks for overlapping workers, queue claims and abandoned-worker recovery, plus backup restoration, monitoring and webhook retry regressions. These tests exercise file-backed databases and process contention rather than only in-memory simulations.

To run the production Docker operations test locally:

```bash
docker build --load -t formflow-test .
python3 tests/Smoke/docker_operations.py --image=formflow-test
```

This test creates isolated named volumes, uses the null email transport, checks API authorization and queue health, restores a full backup, verifies restart persistence, and removes its temporary container and volumes. CI runs it after the PHP 8.4/8.5 test matrix passes.
