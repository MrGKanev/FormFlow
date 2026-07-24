# formflow

Минимално self-hosted PHP решение за приемане на HTML форми, подобно на Web3Forms и Formspree.

formflow може да се инсталира на стандартен PHP сървър без Docker, PostgreSQL или тежък framework.

## Какво прави

Форма от произволен сайт изпраща данните към централен endpoint:

```html
<form method="POST" action="https://forms.example.com/contact">
```

formflow:

- приема submission-а;
- проверява дали формата съществува;
- проверява разрешения origin;
- допуска само предварително зададени полета;
- валидира задължителните полета;
- проверява honeypot поле;
- валидира Cloudflare Turnstile;
- записва submission-а в SQLite;
- изпраща email през SMTP;
- връща JSON или пренасочва към thank-you страница.

---

## Основна идея

За първата версия не е необходим framework.

Използвани компоненти:

- PHP 8.2 или по-нова версия;
- Composer;
- Symfony Mailer;
- Symfony Dotenv;
- SQLite;
- Cloudflare Turnstile;
- Nginx или Apache.

Препоръчителна подредба според сложността:

```text
Чист PHP > Flight PHP > Slim > Laravel
```

Laravel би имал смисъл по-късно, ако formflow получи:

- потребители;
- dashboard;
- form builder;
- teams;
- analytics;
- integrations;
- API keys;
- multi-tenancy.

---

## Функционалности на версия 1

- множество форми;
- различен получател за всяка форма;
- различен subject за всяка форма;
- allowed origins;
- allowed fields;
- required fields;
- email validation;
- Cloudflare Turnstile;
- honeypot защита;
- Nginx rate limiting;
- SQLite архив;
- SMTP изпращане;
- Reply-To към подателя;
- JSON response;
- success redirect;
- health endpoint;
- ограничение на размера на заявките;
- запис на failed submissions.

---

## Структура на проекта

```text
formflow/
├── public/
│   └── index.php
├── src/
│   ├── FormHandler.php
│   ├── MailService.php
│   ├── SubmissionRepository.php
│   └── Turnstile.php
├── config/
│   └── forms.php
├── storage/
│   └── submissions.sqlite
├── .env
├── .env.example
├── .gitignore
├── composer.json
└── README.md
```

---

## Инсталация

```bash
mkdir formflow
cd formflow

composer require symfony/mailer symfony/dotenv
```

Създай директориите:

```bash
mkdir -p public src config storage
touch storage/submissions.sqlite
```

---

## Composer конфигурация

Файл: `composer.json`

```json
{
  "name": "formflow/formflow",
  "description": "Minimal self-hosted PHP form backend",
  "type": "project",
  "license": "MIT",
  "require": {
    "php": "^8.2",
    "ext-curl": "*",
    "ext-json": "*",
    "ext-mbstring": "*",
    "ext-pdo": "*",
    "ext-pdo_sqlite": "*",
    "symfony/dotenv": "^7.0",
    "symfony/mailer": "^7.0"
  },
  "autoload": {
    "psr-4": {
      "formflow\\": "src/"
    }
  }
}
```

След промяна:

```bash
composer install
composer dump-autoload
```

---

## Environment конфигурация

Файл: `.env.example`

```dotenv
APP_ENV=production
APP_URL=https://forms.example.com

MAILER_DSN=smtp://username:password@smtp.example.com:587
MAIL_FROM=forms@example.com
MAIL_FROM_NAME=formflow

TURNSTILE_SECRET=your-turnstile-secret

DATABASE_PATH=storage/submissions.sqlite
IP_HASH_SECRET=replace-with-a-long-random-value
```

Копирай файла:

```bash
cp .env.example .env
```

Не добавяй `.env` в Git.

Примерен `.gitignore`:

```gitignore
/vendor/
/.env
/storage/*.sqlite
/storage/*.sqlite-shm
/storage/*.sqlite-wal
```

---

## SMTP DSN примери

Обикновен SMTP:

```dotenv
MAILER_DSN=smtp://username:password@smtp.example.com:587
```

SMTP с implicit TLS:

```dotenv
MAILER_DSN=smtps://username:password@smtp.example.com:465
```

Ако username или password съдържат специални символи, те трябва да бъдат URL encoded.

---

## Конфигурация на формите

Файл: `config/forms.php`

```php
<?php

declare(strict_types=1);

return [
    'contact' => [
        'recipient' => 'hello@example.com',

        'allowed_origins' => [
            'https://example.com',
            'https://www.example.com',
        ],

        'allowed_fields' => [
            'name',
            'email',
            'phone',
            'message',
        ],

        'required_fields' => [
            'name',
            'email',
            'message',
        ],

        'subject' => 'New contact form submission',

        'success_redirect' => 'https://example.com/thank-you',

        'turnstile' => true,
    ],

    'support' => [
        'recipient' => 'support@example.com',

        'allowed_origins' => [
            'https://support.example.com',
        ],

        'allowed_fields' => [
            'email',
            'order_number',
            'message',
        ],

        'required_fields' => [
            'email',
            'message',
        ],

        'subject' => 'New support request',

        'success_redirect' => 'https://support.example.com/thank-you',

        'turnstile' => true,
    ],
];
```

Endpoint-ите ще бъдат:

```text
POST https://forms.example.com/contact
POST https://forms.example.com/support
```

---

## Примерна HTML форма

```html
<form method="POST" action="https://forms.example.com/contact">
    <label>
        Име
        <input type="text" name="name" required>
    </label>

    <label>
        Email
        <input type="email" name="email" required>
    </label>

    <label>
        Телефон
        <input type="text" name="phone">
    </label>

    <label>
        Съобщение
        <textarea name="message" required></textarea>
    </label>

    <input
        type="text"
        name="_website"
        autocomplete="off"
        tabindex="-1"
        aria-hidden="true"
        style="position:absolute;left:-9999px"
    >

    <div
        class="cf-turnstile"
        data-sitekey="YOUR_TURNSTILE_SITE_KEY"
    ></div>

    <button type="submit">Изпрати</button>
</form>

<script
    src="https://challenges.cloudflare.com/turnstile/v0/api.js"
    async
    defer
></script>
```

---

## SQLite структура

```sql
CREATE TABLE IF NOT EXISTS submissions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    form_id TEXT NOT NULL,
    payload TEXT NOT NULL,
    ip_hash TEXT,
    status TEXT NOT NULL DEFAULT 'received',
    error_message TEXT,
    created_at TEXT NOT NULL,
    sent_at TEXT
);

CREATE INDEX IF NOT EXISTS idx_submissions_form_id
    ON submissions(form_id);

CREATE INDEX IF NOT EXISTS idx_submissions_status
    ON submissions(status);

CREATE INDEX IF NOT EXISTS idx_submissions_created_at
    ON submissions(created_at);
```

---

## SubmissionRepository

Файл: `src/SubmissionRepository.php`

```php
<?php

declare(strict_types=1);

namespace formflow;

use PDO;
use RuntimeException;

final class SubmissionRepository
{
    private PDO $pdo;

    public function __construct(string $databasePath)
    {
        $directory = dirname($databasePath);

        if (
            !is_dir($directory)
            && !mkdir($directory, 0775, true)
            && !is_dir($directory)
        ) {
            throw new RuntimeException(
                'Unable to create the storage directory.'
            );
        }

        $this->pdo = new PDO('sqlite:' . $databasePath, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $this->pdo->exec('PRAGMA journal_mode = WAL');
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->pdo->exec('PRAGMA busy_timeout = 5000');

        $this->createSchema();
    }

    public function create(
        string $formId,
        array $payload,
        ?string $ipHash
    ): int {
        $statement = $this->pdo->prepare(
            'INSERT INTO submissions (
                form_id,
                payload,
                ip_hash,
                status,
                created_at
            ) VALUES (
                :form_id,
                :payload,
                :ip_hash,
                :status,
                :created_at
            )'
        );

        $statement->execute([
            'form_id' => $formId,
            'payload' => json_encode(
                $payload,
                JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_THROW_ON_ERROR
            ),
            'ip_hash' => $ipHash,
            'status' => 'received',
            'created_at' => gmdate('c'),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function markSent(int $submissionId): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE submissions
             SET status = :status,
                 sent_at = :sent_at,
                 error_message = NULL
             WHERE id = :id'
        );

        $statement->execute([
            'status' => 'sent',
            'sent_at' => gmdate('c'),
            'id' => $submissionId,
        ]);
    }

    public function markFailed(
        int $submissionId,
        string $errorMessage
    ): void {
        $statement = $this->pdo->prepare(
            'UPDATE submissions
             SET status = :status,
                 error_message = :error_message
             WHERE id = :id'
        );

        $statement->execute([
            'status' => 'failed',
            'error_message' => mb_substr($errorMessage, 0, 1000),
            'id' => $submissionId,
        ]);
    }

    private function createSchema(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS submissions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                form_id TEXT NOT NULL,
                payload TEXT NOT NULL,
                ip_hash TEXT,
                status TEXT NOT NULL DEFAULT "received",
                error_message TEXT,
                created_at TEXT NOT NULL,
                sent_at TEXT
            )'
        );

        $this->pdo->exec(
            'CREATE INDEX IF NOT EXISTS idx_submissions_form_id
             ON submissions(form_id)'
        );

        $this->pdo->exec(
            'CREATE INDEX IF NOT EXISTS idx_submissions_status
             ON submissions(status)'
        );

        $this->pdo->exec(
            'CREATE INDEX IF NOT EXISTS idx_submissions_created_at
             ON submissions(created_at)'
        );
    }
}
```

---

## Turnstile service

Файл: `src/Turnstile.php`

```php
<?php

declare(strict_types=1);

namespace formflow;

use RuntimeException;

final class Turnstile
{
    public function __construct(
        private readonly string $secret
    ) {
    }

    public function verify(
        string $token,
        ?string $remoteIp = null
    ): bool {
        if ($token === '' || $this->secret === '') {
            return false;
        }

        $payload = [
            'secret' => $this->secret,
            'response' => $token,
        ];

        if ($remoteIp !== null && $remoteIp !== '') {
            $payload['remoteip'] = $remoteIp;
        }

        $handle = curl_init(
            'https://challenges.cloudflare.com/turnstile/v0/siteverify'
        );

        if ($handle === false) {
            throw new RuntimeException(
                'Unable to initialize cURL.'
            );
        }

        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
            ],
        ]);

        $response = curl_exec($handle);

        if ($response === false) {
            $error = curl_error($handle);
            curl_close($handle);

            throw new RuntimeException(
                'Turnstile request failed: ' . $error
            );
        }

        $statusCode = curl_getinfo(
            $handle,
            CURLINFO_RESPONSE_CODE
        );

        curl_close($handle);

        if ($statusCode < 200 || $statusCode >= 300) {
            return false;
        }

        $data = json_decode(
            $response,
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        return ($data['success'] ?? false) === true;
    }
}
```

---

## MailService

Файл: `src/MailService.php`

```php
<?php

declare(strict_types=1);

namespace formflow;

use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

final class MailService
{
    private MailerInterface $mailer;

    public function __construct(
        string $mailerDsn,
        private readonly string $fromEmail,
        private readonly string $fromName
    ) {
        $this->mailer = new Mailer(
            Transport::fromDsn($mailerDsn)
        );
    }

    public function send(
        string $recipient,
        string $subject,
        array $fields
    ): void {
        $email = (new Email())
            ->from(
                new Address(
                    $this->fromEmail,
                    $this->fromName
                )
            )
            ->to($recipient)
            ->subject($subject)
            ->text($this->buildTextBody($fields))
            ->html($this->buildHtmlBody($fields));

        if (
            isset($fields['email'])
            && filter_var(
                $fields['email'],
                FILTER_VALIDATE_EMAIL
            )
        ) {
            $replyName = isset($fields['name'])
                ? (string) $fields['name']
                : '';

            $email->replyTo(
                new Address(
                    (string) $fields['email'],
                    $replyName
                )
            );
        }

        $this->mailer->send($email);
    }

    private function buildTextBody(array $fields): string
    {
        $lines = [
            'New formflow submission',
            '',
        ];

        foreach ($fields as $name => $value) {
            $label = ucwords(
                str_replace('_', ' ', (string) $name)
            );

            $lines[] = $label . ':';
            $lines[] = (string) $value;
            $lines[] = '';
        }

        return implode(PHP_EOL, $lines);
    }

    private function buildHtmlBody(array $fields): string
    {
        $rows = '';

        foreach ($fields as $name => $value) {
            $label = htmlspecialchars(
                ucwords(
                    str_replace('_', ' ', (string) $name)
                ),
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8'
            );

            $safeValue = nl2br(
                htmlspecialchars(
                    (string) $value,
                    ENT_QUOTES | ENT_SUBSTITUTE,
                    'UTF-8'
                )
            );

            $rows .= sprintf(
                '<tr>
                    <th style="
                        text-align:left;
                        vertical-align:top;
                        padding:8px;
                        border-bottom:1px solid #ddd;
                    ">%s</th>
                    <td style="
                        padding:8px;
                        border-bottom:1px solid #ddd;
                    ">%s</td>
                </tr>',
                $label,
                $safeValue
            );
        }

        return sprintf(
            '<h2>New formflow submission</h2>
             <table style="
                border-collapse:collapse;
                width:100%%;
             ">%s</table>',
            $rows
        );
    }
}
```

---

## FormHandler

Файл: `src/FormHandler.php`

```php
<?php

declare(strict_types=1);

namespace formflow;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class FormHandler
{
    public function __construct(
        private readonly array $forms,
        private readonly MailService $mailService,
        private readonly SubmissionRepository $repository,
        private readonly Turnstile $turnstile,
        private readonly string $ipHashSecret
    ) {
    }

    public function handle(string $formId): array
    {
        if (!isset($this->forms[$formId])) {
            return [
                'status' => 404,
                'body' => [
                    'success' => false,
                    'message' => 'Form not found.',
                ],
            ];
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return [
                'status' => 405,
                'body' => [
                    'success' => false,
                    'message' => 'Method not allowed.',
                ],
            ];
        }

        $config = $this->forms[$formId];

        $this->assertAllowedOrigin($config);

        if (!empty($_POST['_website'])) {
            return [
                'status' => 200,
                'body' => [
                    'success' => true,
                    'message' => 'Submission accepted.',
                ],
                'redirect' => $config['success_redirect'] ?? null,
            ];
        }

        $fields = $this->extractAllowedFields(
            $_POST,
            $config['allowed_fields'] ?? []
        );

        $this->validateRequiredFields(
            $fields,
            $config['required_fields'] ?? []
        );

        $this->validateEmailField($fields);

        if (($config['turnstile'] ?? false) === true) {
            $token = (string) (
                $_POST['cf-turnstile-response'] ?? ''
            );

            if (
                !$this->turnstile->verify(
                    $token,
                    $this->clientIp()
                )
            ) {
                return [
                    'status' => 422,
                    'body' => [
                        'success' => false,
                        'message' =>
                            'Turnstile validation failed.',
                    ],
                ];
            }
        }

        $submissionId = $this->repository->create(
            $formId,
            $fields,
            $this->ipHash($this->clientIp())
        );

        try {
            $this->mailService->send(
                (string) $config['recipient'],
                (string) (
                    $config['subject']
                    ?? 'New form submission'
                ),
                $fields
            );

            $this->repository->markSent($submissionId);
        } catch (Throwable $exception) {
            $this->repository->markFailed(
                $submissionId,
                $exception->getMessage()
            );

            throw new RuntimeException(
                'Unable to send the submission email.',
                0,
                $exception
            );
        }

        return [
            'status' => 200,
            'body' => [
                'success' => true,
                'message' => 'Submission sent successfully.',
            ],
            'redirect' => $config['success_redirect'] ?? null,
        ];
    }

    private function assertAllowedOrigin(
        array $config
    ): void {
        $allowedOrigins = $config['allowed_origins'] ?? [];

        if ($allowedOrigins === []) {
            return;
        }

        $origin = $_SERVER['HTTP_ORIGIN'] ?? null;

        if (
            is_string($origin)
            && in_array($origin, $allowedOrigins, true)
        ) {
            return;
        }

        $referer = $_SERVER['HTTP_REFERER'] ?? null;

        if (is_string($referer)) {
            foreach ($allowedOrigins as $allowedOrigin) {
                if ($referer === $allowedOrigin) {
                    return;
                }

                if (
                    str_starts_with(
                        $referer,
                        rtrim($allowedOrigin, '/') . '/'
                    )
                ) {
                    return;
                }
            }
        }

        throw new InvalidArgumentException(
            'Origin is not allowed.'
        );
    }

    private function extractAllowedFields(
        array $input,
        array $allowedFields
    ): array {
        $result = [];

        foreach ($allowedFields as $field) {
            if (!array_key_exists($field, $input)) {
                continue;
            }

            $value = $input[$field];

            if (is_array($value)) {
                $value = implode(
                    ', ',
                    array_map('strval', $value)
                );
            }

            $value = trim((string) $value);

            if (mb_strlen($value) > 10000) {
                throw new InvalidArgumentException(
                    sprintf(
                        'Field "%s" is too long.',
                        $field
                    )
                );
            }

            $result[$field] = $value;
        }

        return $result;
    }

    private function validateRequiredFields(
        array $fields,
        array $requiredFields
    ): void {
        foreach ($requiredFields as $field) {
            if (
                !isset($fields[$field])
                || $fields[$field] === ''
            ) {
                throw new InvalidArgumentException(
                    sprintf(
                        'Field "%s" is required.',
                        $field
                    )
                );
            }
        }
    }

    private function validateEmailField(
        array $fields
    ): void {
        if (
            !isset($fields['email'])
            || $fields['email'] === ''
        ) {
            return;
        }

        if (
            !filter_var(
                $fields['email'],
                FILTER_VALIDATE_EMAIL
            )
        ) {
            throw new InvalidArgumentException(
                'The email field is invalid.'
            );
        }
    }

    private function clientIp(): ?string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;

        return is_string($ip) && $ip !== ''
            ? $ip
            : null;
    }

    private function ipHash(
        ?string $ip
    ): ?string {
        if ($ip === null) {
            return null;
        }

        return hash_hmac(
            'sha256',
            $ip . '|' . date('Y-m'),
            $this->ipHashSecret
        );
    }
}
```

---

## Public endpoint

Файл: `public/index.php`

```php
<?php

declare(strict_types=1);

use formflow\FormHandler;
use formflow\MailService;
use formflow\SubmissionRepository;
use formflow\Turnstile;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);

if (is_file($root . '/.env')) {
    (new Dotenv())
        ->usePutenv()
        ->load($root . '/.env');
}

$forms = require $root . '/config/forms.php';

$databasePath = getenv('DATABASE_PATH')
    ?: 'storage/submissions.sqlite';

if (!str_starts_with($databasePath, '/')) {
    $databasePath = $root . '/'
        . ltrim($databasePath, '/');
}

$path = parse_url(
    $_SERVER['REQUEST_URI'] ?? '/',
    PHP_URL_PATH
);

$formId = trim((string) $path, '/');

if ($formId === 'health') {
    header(
        'Content-Type: application/json; charset=utf-8'
    );

    echo json_encode([
        'status' => 'ok',
        'service' => 'formflow',
        'time' => gmdate('c'),
    ], JSON_UNESCAPED_SLASHES);

    exit;
}

$handler = new FormHandler(
    $forms,
    new MailService(
        getenv('MAILER_DSN') ?: '',
        getenv('MAIL_FROM') ?: '',
        getenv('MAIL_FROM_NAME') ?: 'formflow'
    ),
    new SubmissionRepository($databasePath),
    new Turnstile(
        getenv('TURNSTILE_SECRET') ?: ''
    ),
    getenv('IP_HASH_SECRET') ?: 'change-me'
);

try {
    $result = $handler->handle($formId);

    http_response_code($result['status']);

    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    $expectsJson = str_contains(
        $accept,
        'application/json'
    );

    if (
        !$expectsJson
        && !empty($result['redirect'])
        && ($result['body']['success'] ?? false) === true
    ) {
        header(
            'Location: ' . $result['redirect'],
            true,
            303
        );

        exit;
    }

    header(
        'Content-Type: application/json; charset=utf-8'
    );

    echo json_encode(
        $result['body'],
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_THROW_ON_ERROR
    );
} catch (InvalidArgumentException $exception) {
    http_response_code(422);

    header(
        'Content-Type: application/json; charset=utf-8'
    );

    echo json_encode([
        'success' => false,
        'message' => $exception->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    error_log($exception->__toString());

    http_response_code(500);

    header(
        'Content-Type: application/json; charset=utf-8'
    );

    echo json_encode([
        'success' => false,
        'message' => 'Internal server error.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
```

---

## Nginx конфигурация

```nginx
limit_req_zone $binary_remote_addr
    zone=formflow_limit:10m
    rate=5r/m;

server {
    listen 80;
    server_name forms.example.com;

    root /var/www/formflow/public;
    index index.php;

    client_max_body_size 64k;

    location / {
        limit_req
            zone=formflow_limit
            burst=3
            nodelay;

        try_files $uri /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;

        fastcgi_param
            SCRIPT_FILENAME
            $document_root$fastcgi_script_name;

        fastcgi_param HTTP_PROXY "";

        fastcgi_pass
            unix:/run/php/php8.3-fpm.sock;
    }

    location ~ /\. {
        deny all;
    }
}
```

Добави HTTPS чрез Certbot, Cloudflare или текущия reverse proxy.

---

## Apache конфигурация

Document root трябва да сочи към:

```text
/var/www/formflow/public
```

Файл: `public/.htaccess`

```apache
RewriteEngine On

RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^ index.php [QSA,L]
```

Root директорията на проекта не трябва да бъде публично достъпна.

---

## Права за storage директорията

```bash
mkdir -p storage
touch storage/submissions.sqlite

chown -R www-data:www-data storage
chmod -R 775 storage
```

При различен PHP-FPM потребител замени `www-data`.

---

## Проверка на PHP extensions

```bash
php -m | grep -E "curl|mbstring|pdo|sqlite"
```

Трябва да присъстват поне:

```text
curl
mbstring
PDO
pdo_sqlite
sqlite3
```

Пример за Debian или Ubuntu:

```bash
sudo apt install \
    php-curl \
    php-mbstring \
    php-sqlite3
```

---

## Проверка на health endpoint

```bash
curl https://forms.example.com/health
```

Очакван отговор:

```json
{
  "status": "ok",
  "service": "formflow",
  "time": "2026-07-23T17:00:00+00:00"
}
```

---

## Тестово изпращане

```bash
curl -X POST \
    https://forms.example.com/contact \
    -H "Origin: https://example.com" \
    -H "Accept: application/json" \
    -F "name=Gabriel" \
    -F "email=gabriel@example.com" \
    -F "message=Test submission" \
    -F "cf-turnstile-response=TURNSTILE_TOKEN"
```

---

## JSON изпращане

Първата версия използва стандартен form POST.

Поддържани content types:

```text
application/x-www-form-urlencoded
multipart/form-data
```

За да остане кодът малък, JSON request body не е задължителен за версия 1.

При нужда може да се добави по-късно.

---

## CORS

При обикновено HTML form submit не е необходим CORS response.

CORS е необходим, ако формата се изпраща чрез JavaScript `fetch()` и frontend кодът трябва да прочете response-а.

Минимална CORS логика може да се добави само за разрешените origins.

Не използвай:

```http
Access-Control-Allow-Origin: *
```

ако endpoint-ът връща чувствителна информация или приема authenticated заявки.

---

## JavaScript fetch пример

```html
<form id="contact-form">
    <input type="text" name="name" required>
    <input type="email" name="email" required>
    <textarea name="message" required></textarea>

    <input
        type="text"
        name="_website"
        tabindex="-1"
        autocomplete="off"
        style="position:absolute;left:-9999px"
    >

    <div
        class="cf-turnstile"
        data-sitekey="YOUR_TURNSTILE_SITE_KEY"
    ></div>

    <button type="submit">Изпрати</button>
</form>

<script>
const form = document.querySelector('#contact-form');

form.addEventListener('submit', async (event) => {
    event.preventDefault();

    const response = await fetch(
        'https://forms.example.com/contact',
        {
            method: 'POST',
            body: new FormData(form),
            headers: {
                Accept: 'application/json'
            }
        }
    );

    const result = await response.json();

    if (!response.ok) {
        alert(result.message || 'Възникна грешка.');
        return;
    }

    form.reset();
    alert('Съобщението е изпратено.');
});
</script>
```

---

## Основни защити

### Allowed origins

Всяка форма има собствен списък с позволени сайтове.

Това спира обикновеното използване на endpoint-а от чужди сайтове.

`Origin` и `Referer` не са абсолютна защита, защото могат да бъдат подменени от custom HTTP клиент. Затова трябва да се комбинират с Turnstile и rate limiting.

### Honeypot

Полето `_website` трябва да остане празно.

При попълнено honeypot поле formflow връща фалшив успешен отговор, но не записва и не изпраща submission-а.

### Cloudflare Turnstile

Turnstile token-ът винаги се валидира server-side.

Не е достатъчно само да присъства Turnstile widget във frontend-а.

### Rate limiting

Rate limiting е по-добре да бъде на Nginx или Cloudflare ниво, вместо да се пише допълнителна PHP логика.

**Важно за reverse proxy:** IP-базираните защити на formflow (rate limiting по IP, IP blocklist, месечният IP hash) четат директно `$_SERVER['REMOTE_ADDR']`. Ако сайтът е зад Cloudflare или друг reverse proxy, това поле показва IP-то на proxy-то, не на реалния клиент — всички заявки изглеждат като идващи от един и същ адрес, което прави IP-базираните защити неефективни (един общ rate limit "bucket" за всички посетители, блокирането на конкретен нападател през IP blocklist вече не работи). За версия 1 това не се коригира в кода — при deploy зад Cloudflare/nginx трябва да се настрои `real_ip`/`CF-Connecting-IP` на ниво Nginx, за да получава PHP реалния клиентски IP в `REMOTE_ADDR`.

### Allowed fields

formflow не изпраща директно цялото `$_POST`.

Приемат се само полетата, описани в `allowed_fields`.

### Request size

Nginx ограничава body размера:

```nginx
client_max_body_size 64k;
```

Това е достатъчно за нормална contact форма без файлове.

### File uploads

Файловите uploads не са част от версия 1.

Те изискват:

- MIME validation;
- ограничение на размерите;
- malware scanning;
- защитено storage пространство;
- signed download URLs;
- retention policy.

---

## Работа при SMTP проблем

Последователността е:

```text
Приемане
   ↓
Валидация
   ↓
Запис в SQLite
   ↓
SMTP изпращане
   ↓
status = sent или failed
```

Ако SMTP не работи, submission-ът остава в SQLite със статус:

```text
failed
```

Така данните не се губят.

В бъдеще може да се добави cron command, който изпраща отново failed submissions.

---

## Backup

Минимален backup:

```bash
sqlite3 storage/submissions.sqlite \
    ".backup '/backup/formflow-submissions.sqlite'"
```

Примерен cron:

```cron
0 3 * * * sqlite3 /var/www/formflow/storage/submissions.sqlite ".backup '/backup/formflow-$(date +\%F).sqlite'"
```

Периодично изтривай стари backup файлове.

---

## Данни и GDPR

Формите могат да съдържат лични данни.

Препоръчително е:

- да не пазиш IP адреса в чист вид;
- да използваш месечно променящ се hash;
- да дефинираш retention период;
- да изтриваш стари submissions;
- да не събираш ненужни полета;
- да ограничиш достъпа до SQLite файла;
- да използваш HTTPS;
- да опишеш обработката в privacy policy.

Примерно изтриване на записи над 180 дни:

```bash
sqlite3 storage/submissions.sqlite \
    "DELETE FROM submissions
     WHERE created_at < datetime('now', '-180 days');"
```

---

## Какво не влиза във версия 1

За да остане проектът малък, първата версия не включва:

- admin panel;
- login;
- user registration;
- teams;
- form builder;
- file uploads;
- queues;
- Redis;
- webhooks;
- Slack;
- Discord;
- Telegram;
- Google Sheets;
- email template editor;
- analytics dashboard;
- API keys;
- public hosted forms;
- multi-tenancy;
- automatic retries;
- password reset;
- billing.

---

## Възможна версия 2

След като основният endpoint е стабилен, могат да се добавят:

- CLI команда за failed submissions;
- cron retry;
- webhook destinations;
- auto-reply към подателя;
- conditional recipients;
- email templates;
- spam scoring;
- submission export;
- simple password-protected dashboard;
- retention cleanup command;
- per-form rate limits;
- signed API tokens;
- attachments;
- n8n integration.

---

## Минимална бъдеща CLI команда

Примерна идея:

```bash
php bin/formflow retry
php bin/formflow cleanup
php bin/formflow stats
```

Това може да се направи без framework.

---

## Deployment checklist

- [ ] PHP 8.2+ е инсталиран.
- [ ] `curl`, `mbstring`, `pdo_sqlite` и `sqlite3` са активни.
- [ ] Composer dependencies са инсталирани.
- [ ] `.env` съдържа реалните SMTP данни.
- [ ] `TURNSTILE_SECRET` е зададен.
- [ ] `IP_HASH_SECRET` е сменен.
- [ ] `forms.php` съдържа правилните domains и recipients.
- [ ] Document root сочи към `public/`.
- [ ] `storage/` е writable от PHP-FPM.
- [ ] HTTPS е активиран.
- [ ] Request body размерът е ограничен.
- [ ] Rate limiting е включен.
- [ ] `/health` работи.
- [ ] Тестов email е получен.
- [ ] Failed submission остава в SQLite.
- [ ] Backup на SQLite е настроен.
- [ ] Privacy policy е актуализирана.
- [ ] Ако сайтът е зад Cloudflare/nginx reverse proxy, `REMOTE_ADDR` показва proxy IP-то, не реалния клиентски IP — настроен е `real_ip`/`CF-Connecting-IP` на ниво Nginx, за да работят коректно IP blocklist-ът, rate limiting-ът и IP hash-a (виж бележката в "Основни защити" по-долу).
- [ ] `ADMIN_USERNAME` и `ADMIN_PASSWORD_HASH` са зададени в `.env` (генерирани с `php -r "echo password_hash('...', PASSWORD_DEFAULT), PHP_EOL;"`).
- [ ] `config/admin.php` → `allowed_ips` съдържа реалния IP на оператора.
- [ ] Ако инсталацията е зад Cloudflare/nginx, `real_ip`/`CF-Connecting-IP` е настроен и за admin IP whitelist-а (същото изискване като за IP blocklist-а по-горе).

---

## Име на проекта

Избрано име:

# formflow

Името описва основната функция:

```text
HTML form
   ↓
formflow
   ↓
Email / storage / бъдещи integrations
```

Подходящи варианти за repository:

```text
formflow
php-formflow
formflow-server
self-hosted-formflow
```

Подходящи поддомейни:

```text
forms.example.com
relay.example.com
submit.example.com
```

Преди публично пускане като продукт е разумно да се направи отделна проверка за свободен домейн и съществуващи търговски марки.

---

## Лиценз

За личен или open-source проект може да се използва MIT License.

---

## Обобщение

formflow е малък централен backend за HTML форми.

Той не се опитва да бъде пълна SaaS платформа. Основната му задача е надеждно да:

1. приеме submission;
2. валидира данните;
3. блокира масовия spam;
4. запази submission-а;
5. изпрати email;
6. върне ясен response.

Това е достатъчно за contact, support, feedback и quote форми в множество сайтове, без отделен backend за всеки от тях.

---

## Допълнение към плана — решения за v1 (storage + защити)

Тази секция допълва оригиналния план след обсъждане. Тя е меродавна там, където противоречи на по-горния текст.

### Storage зад интерфейс

`SubmissionRepository` се разделя на `SubmissionRepositoryInterface` + `SqliteSubmissionRepository`. За v1 съществува само SQLite имплементация, но `FormHandler` зависи от интерфейса — смяна на backend (MySQL/Postgres) по-късно става с нов клас, без промяна във `FormHandler`.

По същата причина се въвеждат:

- `MailSenderInterface` (имплементиран от `MailService`);
- `TurnstileVerifierInterface` (имплементиран от `Turnstile`);
- `RateLimiterInterface` (имплементиран от `SqliteRateLimiter`).

Целта е `FormHandler` да зависи само от интерфейси, за да е тестваем с fake-ове, без реални мрежови повиквания или файлове.

### Разширена конфигурация на формите

Файл: `config/forms.php` — нови, **незадължителни** ключове (ако липсват, важат default стойности):

```php
'contact' => [
    // ... съществуващите ключове ...

    // Споделен таен низ, изпращан като скрито поле от формата.
    // Обикновено един ключ на домейн/сайт, не по страница.
    'api_key' => 'replace-with-a-long-random-value',

    // Лимит по IP в рамките на прозорец — брои се всеки опит
    // (успешен и неуспешен), не само приетите submissions.
    'rate_limit_per_ip' => [
        'max' => 5,
        'window_minutes' => 10,
    ],

    // Общ таван на приетите submissions за формата за последните 24ч,
    // независимо от IP — защита при масирана атака от много адреси.
    'daily_limit' => 200,

    // Прости case-insensitive substrings (не regex), проверявани
    // във всички текстови полета на submission-а.
    'blocked_patterns' => [
        'viagra',
        '<a href=',
    ],
],
```

Ако `api_key` е зададен, HTML формата трябва да съдържа:

```html
<input type="hidden" name="_key" value="replace-with-a-long-random-value">
```

Нов глобален файл: `config/security.php`

```php
<?php

declare(strict_types=1);

return [
    'blocked_ips' => [
        '203.0.113.5',
        '198.51.100.0/24',
    ],
];
```

### Нови класове

| Клас | Отговорност |
|---|---|
| `SubmissionRepositoryInterface` / `SqliteSubmissionRepository` | CRUD върху `submissions` таблицата (както досега) |
| `RateLimiterInterface` / `SqliteRateLimiter` | Пише/брои опити в нова таблица `rate_limit_hits (form_id, ip_hash, created_at)`; периодично трие стари редове |
| `IpBlocklist` | Чете `config/security.php`, проверява точен IP или CIDR match |
| `SpamFilter` | Чете `blocked_patterns` от конфигурацията на формата, проверява извлечените полета |

`ip_hash()` логиката (месечно ротиращ HMAC) се преизползва и за `rate_limit_hits`, вместо да се въвежда втора схема за хеширане.

### Ред на проверките в заявката

```text
Blocked IP (index.php, преди config/forms.php)
   ↓
Формата съществува ли
   ↓
HTTP метод POST
   ↓
Allowed origin/referer
   ↓
Rate limit: IP + форма (прозорец от X мин)
   ↓
Rate limit: дневен таван на формата
   ↓
API key (ако е конфигуриран)
   ↓
Honeypot (_website)
   ↓
Allowed/required fields + email format
   ↓
Spam filter (blocked_patterns)
   ↓
Cloudflare Turnstile
   ↓
Запис в SQLite + изпращане на имейл
```

### HTTP статус кодове

| Случай | Код | Записва ли се в SQLite | Изпраща ли се имейл |
|---|---|---|---|
| Формата не съществува | 404 | не | не |
| Грешен HTTP метод | 405 | не | не |
| Blocked IP | 403 | не | не |
| Забранен origin | 422 | не | не |
| Rate limit / дневен таван превишен | 429 | не | не |
| Грешен/липсващ `api_key` | 422 | не | не |
| Honeypot попълнен | 200 (fake success) | да, `status = blocked_honeypot` | не |
| Spam filter match | 200 (fake success) | да, `status = blocked_spam` | не |
| Липсващо required поле / невалиден email | 422 | не | не |
| Неуспешен Turnstile | 422 | не | не |
| Успешен submit | 200 + redirect | да, `status = sent` или `failed` | да |

Honeypot и spam filter вече оставят следа в SQLite (нов статус), за да може операторът да вижда какво се блокира и да настройва `blocked_patterns` — но отговорът към подателя остава фалшив success, за да не се издава защитата.

### Тестове (PHPUnit)

Добавят се тестове и за новите компоненти, по същия модел с fake-ове/`:memory:` SQLite, без реални мрежови повиквания:

- `RateLimiterTest` — превишаване на IP+форма прозореца, превишаване на дневния таван, изчистване на стари редове;
- `IpBlocklistTest` — точен IP match, CIDR match, непозволен IP преминава;
- `SpamFilterTest` — match/no-match, case-insensitivity;
- `FormHandlerTest` се разширява с case-ове за 403 (blocked IP — тества се на ниво `index.php`/интеграционно, извън unit теста на `FormHandler`), 429 (rate limit, дневен таван), 422 (грешен `api_key`), 200 fake-success + `blocked_spam`/`blocked_honeypot` записи.

### Извън обхвата на текущата сесия

- CORS / JS `fetch()` cross-origin подаване — формите се подават само с обикновен `<form method="POST">`, без JS. CORS логика не се добавя.
- Реално изпращане на имейл или реална Turnstile верификация — работим само локално с код, без live credentials.
- MySQL/Postgres имплементация на `SubmissionRepositoryInterface` — само интерфейсът се подготвя, конкретна втора имплементация не се пише в тази сесия.
