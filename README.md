# formflow

Minimal self-hosted PHP backend for accepting HTML forms (like Web3Forms/Formspree), without a heavy framework.

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

Edit `.env` with real SMTP/Turnstile data. You can keep starter forms in `config/forms.php` or create new forms from `/admin/forms`.

## Running locally

```bash
php -S localhost:8080 -t public
```

`GET /` returns a short static home page with a description of the project and links to `/admin` and `/health`.

Health check:

```bash
curl http://localhost:8080/health
```

Test submission (the `contact` form from `config/forms.php`):

```bash
curl -X POST http://localhost:8080/contact \
    -H "Origin: https://example.com" \
    -H "Accept: application/json" \
    -F "name=Gabriel" \
    -F "email=gabriel@example.com" \
    -F "message=Test submission"
```

If you have already generated an API key for `contact` from `/admin/api-keys`, also add `-F "_key=<the generated key>"` - otherwise submissions go through without it.

By default `contact` has `'turnstile' => true`, and the command above does not send `cf-turnstile-response` - expect `422 Turnstile validation failed`. For a local smoke test without a real Cloudflare token, temporarily change it to `'turnstile' => false` in `config/forms.php`, or send a valid token obtained from a real widget.

## Admin panel

Minimal admin panel for reviewing submissions, protected with login + IP whitelist.

Routes:

- `/admin` - dashboard with paginated submissions (filters by form and status).
- `/admin/forms` - create new form endpoints and review configured forms.
- `/admin/login` - login form.
- `/admin/logout` - logout, redirect to `/admin/login`.
- `/admin/submissions/{id}` - details for a specific submission.
- `/admin/whitelist` - management of the IP whitelist for the admin panel (adding/removing IP/CIDR entries).
- `/admin/api-keys` - generate/regenerate an API key per form.

Requires:

- `ADMIN_USERNAME` and `ADMIN_PASSWORD_HASH` in `.env` (the hash is generated with `php -r "echo password_hash('...', PASSWORD_DEFAULT), PHP_EOL;"`).
- At least one IP (or CIDR) in `config/admin.php` → `allowed_ips`, or an entry in the dynamic whitelist (the `admin_ip_whitelist` table in SQLite, managed via `/admin/whitelist`).

## Tests

```bash
composer install
vendor/bin/phpunit
```

## Configuration

- Forms can be created from `/admin/forms`. Starter/static forms can also live in `config/forms.php`.
- Per form: recipient, `allowed_origins`, `subject`, `success_redirect`, `turnstile`, `rate_limit_per_ip` (`max`, `window_minutes`), `daily_limit`, `blocked_patterns`. Form endpoints accept all submitted user fields; system fields such as `_key`, `_website`, `cf-turnstile-response`, and `csrf_token` are not stored or emailed. API keys are generated from `/admin/api-keys`.
- `config/security.php` - global IP blocklist (exact IPv4 addresses or CIDR ranges).

## Protections

Allowed origins/referer, honeypot field (`_website`), Cloudflare Turnstile, per-form API key (generated from `/admin/api-keys`, sent as a hidden field `_key`; until one is generated for a given form, it accepts submissions without a key), rate limiting by IP+form with a configurable window, daily cap on submissions per form, global IP blocklist, simple case-insensitive spam filter by keywords/phrases.

**Reverse proxy:** The IP-based protections (rate limiting, IP blocklist, IP hash) read `REMOTE_ADDR` directly. Behind Cloudflare/nginx this is the proxy's IP, not the real client - set up `real_ip`/`CF-Connecting-IP` at the Nginx level, otherwise these protections do not work correctly.


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
sqlite3 storage/submissions.sqlite \
    "DELETE FROM submissions
     WHERE created_at < datetime('now', '-180 days');"
```

## Alternatives

If you want a more feature-rich solution, consider:

### Self-hosted

- [FormLander](https://github.com/karloscodes/formlander) - Go backend, more features, but not PHP friendly and needs Docker for easier deployment.

### Hosted

- [Web3Forms](https://web3forms.com/) - hosted, free tier, more features.
- [Formspree](https://formspree.io/) - hosted, free tier, more features.
