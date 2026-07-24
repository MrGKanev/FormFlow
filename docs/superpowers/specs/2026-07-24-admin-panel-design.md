# Admin panel with IP whitelisting — design

Date: 2026-07-24
Status: approved (pending final spec review)

## Purpose

formflow v1 (`formflow.md`) explicitly excludes an admin panel. This spec adds
one, scoped to what was agreed during brainstorming:

- View submissions (list + detail), not edit/delete/retry them.
- Access to the panel is gated by an IP whitelist *and* a username/password
  login — two independent layers.
- The IP whitelist has a static, panel-immutable baseline (config file) plus
  a dynamic list manageable from inside the panel (SQLite), so an admin can
  never lock themselves out by mis-editing the dynamic list.

Out of scope for this spec: managing `config/security.php` (form-facing IP
blocklist), editing form config, retrying/deleting submissions, CSV export,
multi-admin accounts. These remain candidates for a future iteration.

## Routing

No new dependency, no new front controller. `public/index.php` already does
manual path-based routing (it special-cases `health`); this adds the same
kind of special-case for `admin` and `admin/*`, delegated to a new
`AdminController`:

```
GET/POST /admin/login              login form / credential check
GET      /admin/logout             destroy session
GET      /admin                    dashboard: submissions list
GET      /admin/submissions/{id}   submission detail (full payload)
GET/POST /admin/whitelist          list + add/remove dynamic whitelist IPs
```

`AdminController::handle(string $path): array` mirrors `FormHandler::handle()`'s
shape: returns `['status' => int, 'body' => string|array, 'redirect' => ?string]`.
For HTML routes `body` is a rendered template string; `index.php` `echo`s it
with `Content-Type: text/html` instead of JSON when the route is under `admin`.

## Request flow

```
Request to /admin*
   ↓
Client IP allowed? (config/admin.php['allowed_ips'] ∪ SQLite admin_ip_whitelist)
   no  → 403 Forbidden (login form itself is not rendered)
   yes ↓
Valid session already?
   no  → render login form
   yes ↓
POST /admin/login:
   wrong credentials → 401 + error message
   rate limit hit (see below) → 429
   correct            → session established, redirect to /admin
   ↓
GET  /admin                    → paginated submissions list, filters: form_id, status
GET  /admin/submissions/{id}   → full decoded payload + status/error/timestamps
GET/POST /admin/whitelist      → list (config entries read-only, SQLite entries removable)
                                  + form to add a new SQLite entry
GET  /admin/logout             → destroy session, redirect to /admin/login
```

Login brute-force protection reuses the existing `RateLimiterInterface` /
`SqliteRateLimiter` (already used for form submissions), keyed by a fixed
pseudo-form-id (`admin_login`) and the requester's IP hash — no new rate
limiting mechanism. Default: 5 attempts / 15 minutes, configurable in
`config/admin.php`.

## New classes

| Class | Responsibility |
|---|---|
| `IpMatcher` | Extracted from `IpBlocklist`'s existing exact-IP/CIDR matching logic (including the hardened malformed-CIDR rejection from commit `aaa621d`). No behavior change to `IpBlocklist`, just a shared dependency so the whitelist doesn't reimplement CIDR parsing. |
| `IpBlocklist` | Refactored to use `IpMatcher` internally. Public API unchanged; existing `IpBlocklistTest` must keep passing unmodified. |
| `AdminIpWhitelistInterface` / `AdminIpWhitelist` | `isAllowed(string $ip): bool` — true if `$ip` matches an entry in `config/admin.php['allowed_ips']` OR the SQLite `admin_ip_whitelist` table. Uses `IpMatcher`. |
| `AdminWhitelistRepositoryInterface` / `SqliteAdminWhitelistRepository` | CRUD on `admin_ip_whitelist` (`add(string $ipOrCidr, ?string $note)`, `remove(int $id)`, `list(): array`). Same SQLite file as submissions/rate-limit tables. |
| `AdminAuth` | `attemptLogin(string $username, string $password): bool` — `hash_equals`-safe username compare + `password_verify()` against `ADMIN_USERNAME`/`ADMIN_PASSWORD_HASH` (env). Wraps the rate limiter check. `isLoggedIn()`/`login()`/`logout()` manage `$_SESSION`. |
| `AdminController` | Dispatches the routes above; depends only on the interfaces (`AdminAuth`, `AdminIpWhitelistInterface`, `SubmissionRepositoryInterface`, `AdminWhitelistRepositoryInterface`) — testable with fakes, same pattern as `FormHandler`. |

## Data model changes

New table (created in the same SQLite database as `submissions`, via the
existing `createSchema()`-style pattern):

```sql
CREATE TABLE IF NOT EXISTS admin_ip_whitelist (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    ip_or_cidr TEXT NOT NULL UNIQUE,
    note TEXT,
    created_at TEXT NOT NULL
);
```

`SubmissionRepositoryInterface` gains three read methods (additive, no
existing signatures change):

```php
findPaginated(?string $formId, ?string $status, int $page, int $perPage): array;
count(?string $formId, ?string $status): int;
find(int $id): ?array;
```

`SqliteSubmissionRepository` implements them with parameterized queries
(`form_id`/`status` optional filters, `ORDER BY created_at DESC`, `LIMIT`/`OFFSET`
for pagination). `find()` decodes the stored JSON payload for display.

## Configuration

New `config/admin.php` (mirrors the shape of `config/security.php`):

```php
<?php

declare(strict_types=1);

return [
    'allowed_ips' => [
        // '203.0.113.10',
    ],

    'login_rate_limit' => [
        'max' => 5,
        'window_minutes' => 15,
    ],
];
```

New `.env` keys (documented in `.env.example`):

```dotenv
ADMIN_USERNAME=admin
ADMIN_PASSWORD_HASH=
```

`ADMIN_PASSWORD_HASH` is a `password_hash()` output (bcrypt/argon2), generated
once via `php -r "echo password_hash('...', PASSWORD_DEFAULT), PHP_EOL;"` and
pasted into `.env` — no in-panel password change flow (single-admin, matches
`.env`-based secret management already used for `TURNSTILE_SECRET` etc.).
If `ADMIN_PASSWORD_HASH` is empty, `AdminAuth` always rejects login (fails
closed, not open).

## Views

Plain PHP templates under `src/Admin/views/` (`login.php`, `dashboard.php`,
`submission.php`, `whitelist.php`), no template engine — consistent with the
project's "no framework" stance. All dynamic output passed through
`htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`.

## CSRF

A random token is stored in `$_SESSION['csrf_token']` on session start and
rendered as a hidden field in every POST form (login, whitelist add/remove).
`AdminController` verifies it with `hash_equals()` before processing any POST
and returns HTTP 419 with a rendered HTML error page (same plain-text-message
convention as the JSON error bodies elsewhere in the app) on mismatch.

## Security notes carried over from the existing IP-based defenses

Same caveat as documented for `IpBlocklist`/rate limiting in `formflow.md`:
if formflow sits behind Cloudflare/nginx, `REMOTE_ADDR` reflects the proxy,
not the real client, unless `real_ip`/`CF-Connecting-IP` is configured at
the web server. This applies to the admin IP whitelist exactly as it does to
the existing blocklist — will be called out in the README/formflow.md
alongside the existing note rather than duplicated as new prose.

## Testing plan (PHPUnit, existing fakes/`:memory:` SQLite pattern)

- `IpMatcherTest` — exact IP, CIDR match, malformed CIDR rejected (moved/adapted
  from the relevant cases already in `IpBlocklistTest`).
- `IpBlocklistTest` — unchanged, must still pass (regression check on the refactor).
- `AdminIpWhitelistTest` — config-only match, SQLite-only match, union of both,
  no match.
- `SqliteAdminWhitelistRepositoryTest` — add/remove/list, duplicate IP rejected.
- `AdminAuthTest` — correct/incorrect credentials, empty password hash always
  rejects, lockout after N attempts via a fake rate limiter.
- `SqliteSubmissionRepositoryTest` — extended with `findPaginated`/`count`/`find`
  cases (filter by form_id, by status, pagination boundaries).
- `AdminControllerTest` — 403 for non-whitelisted IP, login success/failure/
  lockout, dashboard listing with filters, submission detail 404 for unknown
  id, whitelist add/remove, CSRF rejection on POST without/with wrong token.

## Deployment checklist additions

- [ ] `ADMIN_USERNAME` and `ADMIN_PASSWORD_HASH` set in `.env`.
- [ ] `config/admin.php` `allowed_ips` includes the operator's real IP(s).
- [ ] If behind Cloudflare/nginx, `real_ip`/`CF-Connecting-IP` configured (same
      requirement as the existing IP blocklist).
