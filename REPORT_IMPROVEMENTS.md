# FormFlow Improvements Report

Дата на преглед: 2026-08-01

## Обобщение

FormFlow вече има солидна основа: admin панел, dynamic forms, API keys, CAPTCHA providers, rate limits, audit log, delivery logs, backup/export/import и добър PHPUnit пакет. Най-големите възможности за подобрение не са нови фийчъри, а уеднаквяване на operational поведението, по-ясни граници между модулите и по-сигурни defaults при deployment.

Проверки:

- `composer validate --strict`: OK
- `composer audit --no-interaction`: OK, няма намерени advisories
- `vendor/bin/phpunit`: FAIL, 212 tests, 508 assertions, 2 errors заради липсващ installed пакет `chillerlan/php-qrcode` във `vendor/`, въпреки че е в `composer.lock`

## Приоритет 1

### 1. Уеднаквяване на runtime environment

Проблем: `composer.lock` съдържа `chillerlan/php-qrcode`, но текущият `vendor/` няма `vendor/chillerlan`. Това чупи TOTP QR функционалността и два теста:

- `src/Totp.php:77`
- `tests/Admin/AdminControllerTest.php::testSettingsTotpGenerationRendersQrCode`
- `tests/TotpTest.php::testQrSvgRendersLocalQrCode`

Препоръка:

- Да се пуска `composer install` след dependency промени и преди тестове.
- Да се добави CI стъпка: `composer install --no-interaction --prefer-dist`, `composer validate --strict`, `composer audit`, `vendor/bin/phpunit`.
- Да се документира, че `vendor/` не е source of truth, а `composer.lock` е.

### 2. Разделяне на `AdminController`

`src/Admin/AdminController.php` е 1857 реда и държи routing, auth flow, dashboard, settings, config import/export, users, backup, forms и rendering orchestration. Това вдига риска при всяка промяна.

Препоръка:

- Извадете отделни services/controllers за:
  - `SettingsController` или `SettingsService`
  - `FormAdminController`
  - `SubmissionAdminController`
  - `ConfigImportExportService`
  - `BackupService`
- Оставете `AdminController` като router/facade, докато routes не се отделят.
- Първата безопасна стъпка е extraction на чисти private методи около settings/env/config writes, защото те вече имат ясна граница около `writeSettings()`.

### 3. Разделяне на bootstrap/routing от `public/index.php`

`public/index.php` създава dependencies, чете env/config, render-ва homepage/health, обработва installer, admin routes и public form submission routes.

Препоръка:

- Извадете `AppFactory` или `ContainerFactory` за repositories/services.
- Извадете `Router` или поне route dispatcher за `install`, `admin`, `health`, public forms.
- Оставете `public/index.php` да прави само bootstrap, security headers и dispatch.

## Приоритет 2

### 4. Ясна политика за uploads

Uploads се пазят в `storage/uploads`, а payload записва оригиналното име плюс локалния filesystem път. Има проверки за размер, брой, dangerous extensions и MIME за популярни типове.

Препоръка:

- Да се добави отделен metadata формат за upload fields: original filename, stored basename, size, mime, not full absolute path.
- Да се показва download action в admin през контролирана route, вместо raw filesystem path в submission payload.
- Да се добави config за retention/delete на uploaded files заедно със submissions.

### 5. По-ясни defaults за form security

Новите database-backed форми получават API key, но `require_api_key` зависи от admin form checkbox. Starter config използва legacy `turnstile`.

Препоръка:

- За нови форми `require_api_key` да е включено по подразбиране.
- `config/forms.php` да мине към `captcha_provider => 'turnstile'` вместо legacy `turnstile => true`.
- В UI да има ясно състояние дали формата е protected by API key, CAPTCHA, allowed origins и rate limit.

### 6. Operational статусът да стане action-oriented

`/admin/system` показва полезни стойности: database, storage, queues, trusted proxies. Може да стане по-полезно за production.

Препоръка:

- Добавете checks за missing vendor packages спрямо `composer.lock`.
- Добавете warning, когато `APP_ENV !== production`.
- Добавете warning, когато upload директорията е writable, но няма deny rule или е под public root.
- Добавете queue lag и last worker run timestamp.

## Приоритет 3

### 7. Уеднаквяване на terminology

В проекта има смесица от:

- `turnstile` legacy flag и `captcha_provider`
- `webhooks:retry`, `mail:work`, `mail:retry-failed`
- `integrations`, `notification_channels`, `webhook deliveries`
- `settings`, `system`, `maintenance`

Препоръка:

- Изберете един речник: `captcha_provider`, `delivery channels`, `delivery attempts`, `maintenance`.
- В README, UI labels и config keys да се използват същите термини.
- Legacy support да остане в runtime, но docs и generated configs да ползват новия формат.

### 8. Намаляване на config duplication

Настройките са разпръснати между `.env`, `config/admin.php`, `config/security.php`, `config/forms.php` и SQLite. Това е нормално за self-hosted app, но кодът трябва да го прави видимо.

Препоръка:

- Добавете `SettingsRepository` или `SettingsSnapshot`, който връща typed/current config.
- Използвайте един parser/writer layer за `.env`.
- Добавете `ConfigValidator`, който се ползва и при form create/edit, и при config import.

## Какво да се тества допълнително

- Production smoke test: fresh `composer install`, empty `.env`, installer, login, form create, submit, export, backup.
- Upload lifecycle: upload accepted file, admin view, backup/export, retention cleanup, delete submission.
- Config import: invalid forms, invalid security entries, partial imports, rollback behavior при грешка.
- Reverse proxy setup: trusted proxy allowlist, spoofed `X-Forwarded-For`, Cloudflare header.
- Queue mode: mail/webhook pending, retry, failed, delivery log consistency.

## Какво може да се махне или архивира

- Legacy docs/examples с `turnstile => true`, след като всички generated configs минат към `captcha_provider`.
- Stale local `vendor/` state, ако не съответства на `composer.lock`.
- Manual QR rendering fallback, ако dependency install стане задължителна CI проверка.
- Дублирани config transformation функции след въвеждане на shared config validator.
