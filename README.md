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

If no `.env` file exists yet, every request redirects to `/install` - a
one-time setup wizard. Only the admin username and password (asked first)
are required — SMTP details, App URL, and the Cloudflare Turnstile secret
are all optional and can be left blank, filled in later by editing `.env`
directly. Email sending won't work until `MAILER_DSN`/`MAIL_FROM` are set,
but you can finish installing and log into the admin panel without them.
The wizard writes `.env` and adds your IP to `config/admin.php`'s
whitelist. `/install` refuses to run again once `.env` exists (`403`), and
there's nothing to delete afterward - it's a route, not a file. If PHP
can't write where it needs to, `/install` reports exactly which path(s)
to fix instead of failing silently.

Alternatively, skip the wizard and configure by hand:

```bash
cp .env.example .env
```

Edit `.env` with real SMTP/Turnstile data, and `config/forms.php` with your forms.

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
- `/admin/login` - login form.
- `/admin/logout` - logout, redirect to `/admin/login`.
- `/admin/submissions/{id}` - details for a specific submission.
- `/admin/whitelist` - management of the IP whitelist for the admin panel (adding/removing IP/CIDR entries).
- `/admin/api-keys` - generate/regenerate an API key per form (replaces the static `api_key` field in `config/forms.php`).

Requires:

- `ADMIN_USERNAME` and `ADMIN_PASSWORD_HASH` in `.env` (the hash is generated with `php -r "echo password_hash('...', PASSWORD_DEFAULT), PHP_EOL;"`).
- At least one IP (or CIDR) in `config/admin.php` → `allowed_ips`, or an entry in the dynamic whitelist (the `admin_ip_whitelist` table in SQLite, managed via `/admin/whitelist`).

## Tests

```bash
composer install
vendor/bin/phpunit
```

## Configuration

- `config/forms.php` - per form: recipient, `allowed_origins`, `allowed_fields`, `required_fields`, `subject`, `success_redirect`, `turnstile`, `rate_limit_per_ip` (`max`, `window_minutes`), `daily_limit`, `blocked_patterns`. The API key is no longer here - it is generated from `/admin/api-keys`.
- `config/security.php` - global IP blocklist (exact IPv4 addresses or CIDR ranges).

## Protections

Allowed origins/referer, honeypot field (`_website`), Cloudflare Turnstile, per-form API key (generated from `/admin/api-keys`, sent as a hidden field `_key`; until one is generated for a given form, it accepts submissions without a key), rate limiting by IP+form with a configurable window, daily cap on submissions per form, global IP blocklist, simple case-insensitive spam filter by keywords/phrases.

**Reverse proxy:** The IP-based protections (rate limiting, IP blocklist, IP hash) read `REMOTE_ADDR` directly. Behind Cloudflare/nginx this is the proxy's IP, not the real client - set up `real_ip`/`CF-Connecting-IP` at the Nginx level, otherwise these protections do not work correctly.

### storage/ permissions and PHP extensions

```bash
mkdir -p storage
touch storage/submissions.sqlite

chown -R www-data:www-data storage
chmod -R 775 storage
```

Replace `www-data` if PHP-FPM runs as a different user.

Check that the required extensions are enabled:

```bash
php -m | grep -E "curl|mbstring|pdo|sqlite"
```

At minimum, these must be present:

```text
curl
mbstring
PDO
pdo_sqlite
sqlite3
```

Debian/Ubuntu example:

```bash
sudo apt install \
    php-curl \
    php-mbstring \
    php-sqlite3
```

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

### Deployment checklist

- [ ] PHP 8.2+ is installed.
- [ ] `curl`, `mbstring`, `pdo_sqlite`, and `sqlite3` are enabled.
- [ ] Composer dependencies are installed.
- [ ] `.env` has real SMTP credentials.
- [ ] `TURNSTILE_SECRET` is set.
- [ ] `IP_HASH_SECRET` has been changed from its default.
- [ ] `forms.php` has the correct domains and recipients.
- [ ] Document root points to `public/`.
- [ ] `storage/` is writable by PHP-FPM.
- [ ] HTTPS is enabled.
- [ ] Request body size is limited.
- [ ] Rate limiting is enabled.
- [ ] `/health` works.
- [ ] A test email was received.
- [ ] A failed submission stays recorded in SQLite.
- [ ] SQLite backup is configured.
- [ ] Privacy policy is up to date.
- [ ] If deployed behind Cloudflare/nginx, `real_ip`/`CF-Connecting-IP` is also configured for the admin IP whitelist (same requirement as for the IP blocklist above).
- [ ] Generate an API key per form from `/admin/api-keys` for any form that should require one.

## Alternatives

If you want a more feature-rich solution, consider:

### Self-hosted

- [FormLander](https://github.com/karloscodes/formlander) - Go backend, more features, but not PHP friendly and needs Docker for easier deployment.

### Hosted

- [Web3Forms](https://web3forms.com/) - hosted, free tier, more features.
- [Formspree](https://formspree.io/) - hosted, free tier, more features.
