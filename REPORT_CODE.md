# FormFlow Code Report

Дата на преглед: 2026-08-01

## Обобщение

Кодът е ясен и прагматичен, с interface-и за repositories, mail, captcha, webhook transport и rate limiter. Тестовото покритие е добро като количество: 212 PHPUnit теста. Най-големите code quality проблеми са размерът на `AdminController`, mixed responsibilities в front controller-а, липса на static analysis/type tooling и няколко места, където runtime config arrays трябва да станат валидирани value objects.

Проверки:

- `composer validate --strict`: OK
- `composer audit --no-interaction`: OK
- `vendor/bin/phpunit`: FAIL, 2 errors от missing `chillerlan\QRCode\QROptions`

## Текущи Test Failures

### Missing installed dependency

Грешки:

- `formflow\Tests\Admin\AdminControllerTest::testSettingsTotpGenerationRendersQrCode`
- `formflow\Tests\TotpTest::testQrSvgRendersLocalQrCode`

Причина:

- `src/Totp.php:77` използва `chillerlan\QRCode\QROptions`.
- `composer.lock` съдържа `chillerlan/php-qrcode 6.0.1`.
- Локалният `vendor/` няма `vendor/chillerlan`, така че autoload не намира класа.

Действие:

- Пуснете `composer install`.
- След това пуснете `vendor/bin/phpunit` отново.
- Добавете CI, за да не се допуска lock/vendor drift локално или при release.

## Код За Подобряване

### 1. `AdminController` е прекалено голям

Файл:

- `src/Admin/AdminController.php:21`

Симптоми:

- Constructor с много зависимости.
- Един клас управлява routing, login, settings, forms, users, submissions, delivery, backup, config import/export и rendering.
- Private методите вече са групирани логически, което прави extraction сравнително безопасен.

Препоръка:

- Първо извадете `SettingsService` за `currentSettings()`, `settingsFromPost()`, `writeSettings()`, env/admin/security config writes.
- После извадете `FormConfigService` за create/edit/import validation.
- Накрая разделете admin route handlers по domains.

### 2. `public/index.php` е front controller плюс service container

Файлове:

- `public/index.php:28`
- `public/index.php:274`
- `public/index.php:360`

Симптоми:

- Глобални helper функции.
- Service creation директно в route handling.
- Public HTML, health response, installer и admin dispatch са в един файл.

Препоръка:

- Добавете `Bootstrap` или `AppFactory`, който връща typed dependencies.
- Извадете route dispatch в малък клас или function map.
- Оставете `index.php` да е thin entry point.

### 3. Config arrays имат нужда от canonical validators

Файлове:

- `src/Admin/AdminController.php:1466`
- `src/Admin/AdminController.php:1620`
- `src/FormHandler.php:45`

Симптоми:

- `FormHandler` приема arbitrary `$config` array и очаква конкретни keys.
- Admin create/edit валидира POST input, но config import записва arrays директно.
- Legacy `turnstile` и нов `captcha_provider` съжителстват.

Препоръка:

- Въведете `FormConfig` value object или поне `FormConfigValidator::normalize(array $config): array`.
- Използвайте го при static config load, DB forms load, create/edit и import.
- Runtime code да работи с normalized keys.

### 4. `CurlHttpClient` е прекалено общ без policy layer

Файл:

- `src/CurlHttpClient.php:14`

Симптоми:

- Един helper обслужва CAPTCHA и webhooks, но нуждите са различни.
- CAPTCHA URLs са fixed и trusted.
- Webhook URLs са user/admin-controlled и имат нужда от SSRF/redirect policy.

Препоръка:

- Оставете low-level helper, но добавете `WebhookHttpClient` с destination validation.
- Добавете explicit cURL options за redirects, protocols, TLS verification и user agent.
- Добавете unit tests с fake resolver/transport.

### 5. Upload representation смесва display и storage

Файл:

- `src/FormHandler.php:430`

Симптом:

- Payload стойността става string от типа `original.ext (/absolute/path/to/storage/uploads/...)`.

Препоръка:

- Записвайте structured upload metadata.
- Mail/admin display да форматират metadata отделно.
- Това ще помогне и при delete/retention cleanup.

## Какво Да Уеднаквим

- Naming: `captcha_provider` вместо legacy `turnstile`.
- Config validation: един validator за UI и JSON import.
- HTTP responses: typed response object вместо arrays с `status`, `body`, `redirect`, `headers`.
- Date handling: централен `Clock` или helper за `gmdate('c')`, особено за тестове.
- Error messages: public responses да са generic, admin responses да са detailed.
- File writes: atomic writes за `.env`, `config/admin.php`, `config/security.php`.

## Какво Да Тестваме

- Full clean install от празен clone: `composer install`, installer, login, create form, submit.
- TOTP QR rendering след clean `composer install`.
- Config import validation and rollback.
- Backup path restrictions.
- Upload metadata, MIME checks, dangerous extensions, retention delete.
- Reverse proxy IP resolution and HTTPS detection.
- Webhook SSRF protections and redirect handling.
- Queue workers idempotency and retry limits.

## Какво Да Махнем Или Отложим

- Legacy `turnstile` examples от docs/config, след migration към `captcha_provider`.
- Absolute upload paths от payload/email/export.
- Direct config writes от controller-а, след extraction на settings/config services.
- Manual route `if` chain в `AdminController`, след въвеждане на route map.
- Any stale generated/local state като `vendor/`, който не съответства на `composer.lock`.

## Suggested Implementation Order

1. Fix local dependency state with `composer install` and make PHPUnit green.
2. Add CI with install, validate, audit and PHPUnit.
3. Extract settings/config write logic from `AdminController`.
4. Add canonical `FormConfigValidator` and use it in config import.
5. Harden outbound webhook HTTP policy.
6. Change upload payload to structured metadata.
7. Thin down `public/index.php`.
