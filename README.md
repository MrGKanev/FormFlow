# formflow

Minimal self-hosted PHP backend for accepting HTML forms (like Web3Forms/Formspree), without a heavy framework.

## Highlights

- Self-hosted form endpoints for static sites, landing pages, portfolios, and client projects.
- Per-form recipients, redirects, rate limits, upload rules, API-key protection, and notification destinations.
- Selectable CAPTCHA providers: Cloudflare Turnstile, hCaptcha, Google reCAPTCHA v2, and Friendly Captcha.
- Built-in spam controls: honeypot field, keyword filtering, allowed origins/referers, daily caps, and global IP blocklist.
- Delivery integrations for email plus Discord, Slack, Telegram, and generic webhooks, with per-form webhook overrides.
- Admin panel for submissions, resend/review/delete actions, CSV export, delivery logs, audit log, users, backups, and config import/export.

## Requirements

- PHP 8.2+
- Composer
- PHP extensions: `curl`, `mbstring`, `pdo_sqlite`

## Installation

```bash
composer install
```

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

By default `contact` has `'turnstile' => true`, and the command above does not send `cf-turnstile-response` - expect `422 CAPTCHA validation failed`. For a local smoke test without a real Cloudflare token, temporarily change it to `'turnstile' => false` in `config/forms.php`, or send a valid token obtained from a real widget.

## Admin panel

Minimal admin panel for reviewing submissions, protected with login + IP whitelist.

Routes:

- `/admin` - dashboard with paginated submissions, search, date range filters, page size control, bulk actions, and form analytics.
- `/admin/forms` - review configured forms and copy integration snippets. Snippets use `APP_URL`, so forms can submit from separate websites to one hosted FormFlow instance.
- `/admin/forms/new` - create a new database-backed form endpoint.
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
- `/admin/delivery` - email and integration delivery logs, including webhook retry attempts and failures.
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
vendor/bin/phpunit
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
0 3 * * * sqlite3 /var/www/formflow/storage/submissions.sqlite ".backup '/backup/formflow-$(date +\%F).sqlite'"
```

## Deployment

Docker:

```bash
docker compose up -d --build
```

The included image serves `public/` through Apache and mounts `storage/` for SQLite/uploads. For PHP-FPM/nginx installs, use `deploy/nginx.conf` as a starting point and keep `storage/` outside the web root.

## Configuration

- Forms can be created from `/admin/forms`. Starter/static forms can also live in `config/forms.php`.
- Per form: recipient, `allowed_origins`, `subject`, `success_redirect`, `captcha_provider`, `require_api_key`, `rate_limit_per_ip` (`max`, `window_minutes`), `daily_limit`, `blocked_patterns`, upload limits, selected notification channels, and optional per-form integration overrides. Legacy configs with `turnstile: true` still behave as `captcha_provider: turnstile`. Upload rules support a size limit (1–100 MB), file-count limit (1–20), and an optional allow-list of filename extensions. Form endpoints accept all submitted user fields; system fields such as `_key`, `_website`, `cf-turnstile-response`, `h-captcha-response`, `g-recaptcha-response`, `frc-captcha-response`, and `csrf_token` are not stored or emailed. A key is generated automatically with every new database-backed form and included in its integration snippet.
- Global app settings can be edited from `/admin/settings`. It writes selected values to `.env`, login-rate-limit values to `config/admin.php`, and the global IP blocklist to `config/security.php`. Saving one Settings tab preserves values configured in the other tabs.
- Mail can be configured with standard SMTP fields: `SMTP_HOST`, `SMTP_PORT`, `SMTP_ENCRYPTION` (`tls`, `ssl`, or `none`), `SMTP_USERNAME`, `SMTP_PASSWORD`, `MAIL_FROM`, and `MAIL_FROM_NAME`. `MAILER_DSN` is still supported as an advanced override; when set, it takes precedence over the individual SMTP fields.
- Notification integrations are configured on `/admin/integrations`:
  - Discord: `DISCORD_WEBHOOK_URL`
  - Slack: `SLACK_WEBHOOK_URL`
  - Telegram: `TELEGRAM_BOT_TOKEN` and `TELEGRAM_CHAT_ID`
  - Generic webhook: `GENERIC_WEBHOOK_URL`; receives `{"form_id":"…","fields":{…}}`, which works with Zapier, Make, n8n, or a custom service.
  Each form selects which channels receive its submissions. New forms start with no selected notification channels. A form can override the global Discord, Slack, Telegram, or generic webhook settings when it needs a form-specific destination; blank override fields use the global integration settings. Existing runtime configs without a saved selection still notify every configured global integration. Every webhook delivery is attempted up to three times in sync mode, or by `webhooks:retry` in queue mode, and the final result is visible in `/admin/delivery`. Notifications never prevent a successfully stored submission from being accepted.
- File uploads are accepted from multipart forms and stored under `storage/uploads`; payloads store the original filename plus local storage path. Configure per-form size, count, and extension rules before exposing an upload field in the form markup. Dangerous executable/script extensions are rejected even without an allow-list, and common file types are checked with MIME detection when PHP's `fileinfo` support is available.
- CAPTCHA providers are configured on `/admin/settings?tab=protection`: Cloudflare Turnstile (`TURNSTILE_SECRET`, `TURNSTILE_SITE_KEY`), hCaptcha (`HCAPTCHA_SECRET`, `HCAPTCHA_SITE_KEY`), Google reCAPTCHA v2 (`RECAPTCHA_SECRET`, `RECAPTCHA_SITE_KEY`), and Friendly Captcha (`FRIENDLY_CAPTCHA_API_KEY`, `FRIENDLY_CAPTCHA_SITE_KEY`). Form snippets include the matching widget script when the selected provider has a site key.
- Admin 2FA uses TOTP secrets (`ADMIN_TOTP_SECRET` for the bootstrap user, optional TOTP secret for DB-backed users).
- `config/security.php` - global IP blocklist (exact IP addresses or IPv4 CIDR ranges).

## Protections

Allowed origins/referer, honeypot field (`_website`), selectable CAPTCHA providers, per-form API key (generated with each new form and sent as a hidden field `_key`), rate limiting by IP+form with a configurable window, daily cap on submissions per form, global IP blocklist, simple case-insensitive spam filter by keywords/phrases.

**Reverse proxy:** By default, IP-based protections use `REMOTE_ADDR`. Behind Cloudflare/nginx, either set up `real_ip` at the web-server level or configure `trusted_proxies` in `config/security.php`. FormFlow only reads `CF-Connecting-IP`, `X-Forwarded-For`, or `X-Real-IP` when `REMOTE_ADDR` matches a trusted proxy entry.


### Backup

Minimal backup:

```bash
sqlite3 storage/submissions.sqlite \
    ".backup '/backup/formflow-submissions.sqlite'"
```

Example cron entry:

```cron
0 3 * * * sqlite3 /var/www/formflow/storage/submissions.sqlite ".backup '/backup/formflow-$(date +\%F).sqlite'"
```

Periodically delete old backup files.

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
