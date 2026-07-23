# formflow

Минимален self-hosted PHP backend за приемане на HTML форми (като Web3Forms/Formspree), без тежък framework.

Пълната спецификация и обосновката на решенията са в [`formflow.md`](formflow.md).

## Изисквания

- PHP 8.2+
- Composer
- PHP extensions: `curl`, `mbstring`, `pdo_sqlite`

## Инсталация

```bash
composer install
cp .env.example .env
```

Редактирай `.env` с реални SMTP/Turnstile данни, и `config/forms.php` с твоите форми.

## Локално стартиране

```bash
php -S localhost:8080 -t public
```

Health check:

```bash
curl http://localhost:8080/health
```

Тестово подаване (формата `contact` от `config/forms.php`):

```bash
curl -X POST http://localhost:8080/contact \
    -H "Origin: https://example.com" \
    -H "Accept: application/json" \
    -F "_key=replace-with-a-long-random-value" \
    -F "name=Gabriel" \
    -F "email=gabriel@example.com" \
    -F "message=Test submission"
```

По подразбиране `contact` има `'turnstile' => true`, а горната команда не подава `cf-turnstile-response` — очаквай `422 Turnstile validation failed`. За локален smoke test без реален Cloudflare token временно смени на `'turnstile' => false` в `config/forms.php`, или подай валиден token, получен от истински widget.

## Тестове

```bash
composer install
vendor/bin/phpunit
```

## Конфигурация

- `config/forms.php` — по форма: получател, `allowed_origins`, `allowed_fields`, `required_fields`, `subject`, `success_redirect`, `turnstile`, `api_key`, `rate_limit_per_ip` (`max`, `window_minutes`), `daily_limit`, `blocked_patterns`.
- `config/security.php` — глобален IP blocklist (точни IPv4 адреси или CIDR диапазони).

## Защити

Allowed origins/referer, honeypot поле (`_website`), Cloudflare Turnstile, per-form `api_key` (изпраща се като скрито поле `_key`, обикновено един ключ на домейн), rate limiting по IP+форма с конфигурируем прозорец, дневен таван на submissions по форма, глобален IP blocklist, прост case-insensitive spam филтър по ключови думи/фрази.

Пълните детайли, редът на проверките и HTTP кодовете за всеки случай са в [`formflow.md`](formflow.md#допълнение-към-плана--решения-за-v1-storage--защити).

**Reverse proxy:** IP-базираните защити (rate limiting, IP blocklist, IP hash) четат `REMOTE_ADDR` директно. Зад Cloudflare/nginx това е proxy IP-то, не реалният клиент — настрой `real_ip`/`CF-Connecting-IP` на ниво Nginx, иначе тези защити не работят коректно.

## Production deployment

Nginx/Apache конфигурация, права за `storage/`, backup, GDPR препоръки — виж [`formflow.md`](formflow.md).
