# formflow v1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build formflow v1 — a minimal self-hosted PHP backend that accepts HTML form submissions, validates and protects them, stores them in SQLite, and emails them out — exactly as specified in `formflow.md` plus the "Допълнение към плана" addendum (storage-behind-interface, per-form API key, rate limiting, IP blocklist, spam filter).

**Architecture:** Plain PHP 8.2+ (no framework), one PSR-4 autoloaded `src/` with small single-responsibility classes behind interfaces (`SubmissionRepositoryInterface`, `MailSenderInterface`, `TurnstileVerifierInterface`, `RateLimiterInterface`), a single `FormHandler` orchestrator, and a single `public/index.php` front controller. SQLite is the only storage backend for v1, but `FormHandler` depends only on the interfaces.

**Tech Stack:** PHP 8.2+, Composer, `symfony/mailer` ^7.0, `symfony/dotenv` ^7.0, SQLite (`pdo_sqlite`), PHPUnit ^11 (dev only), Cloudflare Turnstile (HTTP API via cURL).

## Global Constraints

- PHP version floor: `^8.2` (every file starts with `declare(strict_types=1);`).
- No framework — only Composer libraries listed above. Do not add Laravel/Symfony HttpKernel/Slim/Flight.
- All classes are `final`. All source lives under namespace `formflow\` (PSR-4 → `src/`); tests live under `formflow\Tests\` (PSR-4 → `tests/`).
- SQLite is the only storage backend for v1. `FormHandler` must depend on interfaces (`SubmissionRepositoryInterface`, `MailSenderInterface`, `TurnstileVerifierInterface`, `RateLimiterInterface`), never on the concrete SQLite/Symfony/cURL classes directly.
- No CORS handling and no JS `fetch()` example — forms are submitted only via plain HTML `<form method="POST">`.
- No live network testing in this session — SMTP and Turnstile are exercised only through fakes in tests, never against real endpoints/credentials.
- IP addresses are never stored raw — always through the existing monthly-rotating `hash_hmac('sha256', $ip . '|' . date('Y-m'), $secret)` scheme.
- `config/forms.php` new keys (`api_key`, `rate_limit_per_ip`, `daily_limit`, `blocked_patterns`) are all optional; missing keys must fall back to sane defaults, never fatal.

---

### Task 1: Project scaffolding & dependencies

**Files:**
- Create: `composer.json`
- Create: `.env.example`
- Create: `.env`
- Create: `.gitignore`
- Create: `phpunit.xml`
- Create: `config/forms.php`
- Create: `config/security.php`
- Create: `storage/.gitkeep`

**Interfaces:**
- Consumes: nothing (first task).
- Produces: Composer autoloading (`formflow\` → `src/`, `formflow\Tests\` → `tests/`), the `forms.php` / `security.php` config arrays every later task reads.

- [ ] **Step 1: Create the directory layout**

```bash
mkdir -p public src config storage tests/Fakes
```

- [ ] **Step 2: Write `composer.json`**

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
  "require-dev": {
    "phpunit/phpunit": "^11.0"
  },
  "autoload": {
    "psr-4": {
      "formflow\\": "src/"
    }
  },
  "autoload-dev": {
    "psr-4": {
      "formflow\\Tests\\": "tests/"
    }
  }
}
```

- [ ] **Step 3: Install dependencies**

Run: `composer install`
Expected: Composer resolves and installs `symfony/mailer`, `symfony/dotenv`, `phpunit/phpunit` and their dependencies, creates `vendor/` and `composer.lock`, prints `Generating autoload files` with no errors.

- [ ] **Step 4: Write `.env.example`**

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

- [ ] **Step 5: Create local `.env` from the example**

```bash
cp .env.example .env
```

- [ ] **Step 6: Write `.gitignore`**

```gitignore
/vendor/
/.env
/storage/*.sqlite
/storage/*.sqlite-shm
/storage/*.sqlite-wal
```

- [ ] **Step 7: Write `phpunit.xml`**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
    bootstrap="vendor/autoload.php"
    colors="true"
>
    <testsuites>
        <testsuite name="formflow">
            <directory>tests</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

- [ ] **Step 8: Write `config/forms.php`**

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

        'api_key' => 'replace-with-a-long-random-value',

        'rate_limit_per_ip' => [
            'max' => 5,
            'window_minutes' => 10,
        ],

        'daily_limit' => 200,

        'blocked_patterns' => [
            'viagra',
            '<a href=',
        ],
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

Note: `support` intentionally omits `api_key`, `rate_limit_per_ip`, `daily_limit`, `blocked_patterns` to prove defaults apply when they're absent.

- [ ] **Step 9: Write `config/security.php`**

```php
<?php

declare(strict_types=1);

return [
    'blocked_ips' => [
        // '203.0.113.5',
        // '198.51.100.0/24',
    ],
];
```

- [ ] **Step 10: Create the storage placeholder**

```bash
touch storage/.gitkeep
```

- [ ] **Step 11: Verify config files are syntactically valid and PHPUnit is installed**

Run: `php -l config/forms.php && php -l config/security.php && vendor/bin/phpunit --version`
Expected: `No syntax errors detected` twice, followed by a PHPUnit version banner (e.g. `PHPUnit 11.x.y by Sebastian Bergmann and contributors.`).

- [ ] **Step 12: Commit**

```bash
git add composer.json composer.lock .env.example .gitignore phpunit.xml config/forms.php config/security.php storage/.gitkeep
git commit -m "chore: scaffold formflow project structure and dependencies"
```

---

### Task 2: Mail and Turnstile adapters

**Files:**
- Create: `src/MailSenderInterface.php`
- Create: `src/MailService.php`
- Create: `src/TurnstileVerifierInterface.php`
- Create: `src/Turnstile.php`

**Interfaces:**
- Consumes: Composer autoload from Task 1.
- Produces: `MailSenderInterface::send(string $recipient, string $subject, array $fields): void`, `TurnstileVerifierInterface::verify(string $token, ?string $remoteIp = null): bool`. `FormHandler` (Task 7) type-hints against these two interfaces.

- [ ] **Step 1: Write `src/MailSenderInterface.php`**

```php
<?php

declare(strict_types=1);

namespace formflow;

interface MailSenderInterface
{
    /** @param array<string, string> $fields */
    public function send(string $recipient, string $subject, array $fields): void;
}
```

- [ ] **Step 2: Write `src/MailService.php`**

```php
<?php

declare(strict_types=1);

namespace formflow;

use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

final class MailService implements MailSenderInterface
{
    private MailerInterface $mailer;

    public function __construct(
        string $mailerDsn,
        private readonly string $fromEmail,
        private readonly string $fromName
    ) {
        $this->mailer = new Mailer(Transport::fromDsn($mailerDsn));
    }

    public function send(string $recipient, string $subject, array $fields): void
    {
        $email = (new Email())
            ->from(new Address($this->fromEmail, $this->fromName))
            ->to($recipient)
            ->subject($subject)
            ->text($this->buildTextBody($fields))
            ->html($this->buildHtmlBody($fields));

        if (
            isset($fields['email'])
            && filter_var($fields['email'], FILTER_VALIDATE_EMAIL)
        ) {
            $replyName = isset($fields['name']) ? (string) $fields['name'] : '';

            $email->replyTo(new Address((string) $fields['email'], $replyName));
        }

        $this->mailer->send($email);
    }

    private function buildTextBody(array $fields): string
    {
        $lines = ['New formflow submission', ''];

        foreach ($fields as $name => $value) {
            $label = ucwords(str_replace('_', ' ', (string) $name));

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
                ucwords(str_replace('_', ' ', (string) $name)),
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8'
            );

            $safeValue = nl2br(
                htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            );

            $rows .= sprintf(
                '<tr>
                    <th style="text-align:left;vertical-align:top;padding:8px;border-bottom:1px solid #ddd;">%s</th>
                    <td style="padding:8px;border-bottom:1px solid #ddd;">%s</td>
                </tr>',
                $label,
                $safeValue
            );
        }

        return sprintf(
            '<h2>New formflow submission</h2><table style="border-collapse:collapse;width:100%%;">%s</table>',
            $rows
        );
    }
}
```

- [ ] **Step 3: Write `src/TurnstileVerifierInterface.php`**

```php
<?php

declare(strict_types=1);

namespace formflow;

interface TurnstileVerifierInterface
{
    public function verify(string $token, ?string $remoteIp = null): bool;
}
```

- [ ] **Step 4: Write `src/Turnstile.php`**

```php
<?php

declare(strict_types=1);

namespace formflow;

use RuntimeException;

final class Turnstile implements TurnstileVerifierInterface
{
    public function __construct(
        private readonly string $secret
    ) {
    }

    public function verify(string $token, ?string $remoteIp = null): bool
    {
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

        $handle = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');

        if ($handle === false) {
            throw new RuntimeException('Unable to initialize cURL.');
        }

        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        ]);

        $response = curl_exec($handle);

        if ($response === false) {
            $error = curl_error($handle);
            curl_close($handle);

            throw new RuntimeException('Turnstile request failed: ' . $error);
        }

        $statusCode = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        if ($statusCode < 200 || $statusCode >= 300) {
            return false;
        }

        $data = json_decode($response, true, 512, JSON_THROW_ON_ERROR);

        return ($data['success'] ?? false) === true;
    }
}
```

- [ ] **Step 5: Smoke-test both classes instantiate without fatal errors**

Run:
```bash
php -r "require 'vendor/autoload.php'; new formflow\MailService('smtp://user:pass@localhost:1025', 'from@example.com', 'Test'); echo 'mail ok' . PHP_EOL;"
php -r "require 'vendor/autoload.php'; new formflow\Turnstile('dummy-secret'); echo 'turnstile ok' . PHP_EOL;"
```
Expected: `mail ok` then `turnstile ok`, no exceptions or fatal errors. (These only build the objects — no network call happens until `send()`/`verify()` is invoked, so no live SMTP/Turnstile server is needed.)

- [ ] **Step 6: Commit**

```bash
git add src/MailSenderInterface.php src/MailService.php src/TurnstileVerifierInterface.php src/Turnstile.php
git commit -m "feat: add mail and Turnstile adapters behind interfaces"
```

---

### Task 3: Submission storage

**Files:**
- Create: `src/SubmissionRepositoryInterface.php`
- Create: `src/SqliteSubmissionRepository.php`
- Test: `tests/SqliteSubmissionRepositoryTest.php`

**Interfaces:**
- Consumes: Composer autoload from Task 1.
- Produces: `SubmissionRepositoryInterface::create(string $formId, array $payload, ?string $ipHash, string $status = 'received'): int`, `::markSent(int $submissionId): void`, `::markFailed(int $submissionId, string $errorMessage): void`, `::find(int $submissionId): ?array`. `FormHandler` (Task 7) and `public/index.php` (Task 8) depend on this.

- [ ] **Step 1: Write `src/SubmissionRepositoryInterface.php`**

```php
<?php

declare(strict_types=1);

namespace formflow;

interface SubmissionRepositoryInterface
{
    /** @param array<string, string> $payload */
    public function create(
        string $formId,
        array $payload,
        ?string $ipHash,
        string $status = 'received'
    ): int;

    public function markSent(int $submissionId): void;

    public function markFailed(int $submissionId, string $errorMessage): void;

    /** @return array<string, mixed>|null */
    public function find(int $submissionId): ?array;
}
```

- [ ] **Step 2: Write the failing test `tests/SqliteSubmissionRepositoryTest.php`**

```php
<?php

declare(strict_types=1);

namespace formflow\Tests;

use formflow\SqliteSubmissionRepository;
use PHPUnit\Framework\TestCase;

final class SqliteSubmissionRepositoryTest extends TestCase
{
    public function testCreateStoresSubmissionAndReturnsId(): void
    {
        $repository = new SqliteSubmissionRepository(':memory:');

        $id = $repository->create('contact', ['name' => 'Ada'], 'hash123');

        $row = $repository->find($id);

        $this->assertNotNull($row);
        $this->assertSame('contact', $row['form_id']);
        $this->assertSame('received', $row['status']);
        $this->assertSame(['name' => 'Ada'], json_decode($row['payload'], true));
    }

    public function testMarkSentUpdatesStatusAndSentAt(): void
    {
        $repository = new SqliteSubmissionRepository(':memory:');
        $id = $repository->create('contact', ['name' => 'Ada'], 'hash123');

        $repository->markSent($id);

        $row = $repository->find($id);

        $this->assertSame('sent', $row['status']);
        $this->assertNotNull($row['sent_at']);
    }

    public function testMarkFailedUpdatesStatusAndErrorMessage(): void
    {
        $repository = new SqliteSubmissionRepository(':memory:');
        $id = $repository->create('contact', ['name' => 'Ada'], 'hash123');

        $repository->markFailed($id, 'SMTP down');

        $row = $repository->find($id);

        $this->assertSame('failed', $row['status']);
        $this->assertSame('SMTP down', $row['error_message']);
    }

    public function testCreateAcceptsCustomStatus(): void
    {
        $repository = new SqliteSubmissionRepository(':memory:');

        $id = $repository->create('contact', [], 'hash123', 'blocked_spam');

        $row = $repository->find($id);

        $this->assertSame('blocked_spam', $row['status']);
    }

    public function testFindReturnsNullForMissingId(): void
    {
        $repository = new SqliteSubmissionRepository(':memory:');

        $this->assertNull($repository->find(999));
    }
}
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/SqliteSubmissionRepositoryTest.php`
Expected: FAIL — `Error: Class "formflow\SqliteSubmissionRepository" not found`.

- [ ] **Step 4: Write `src/SqliteSubmissionRepository.php`**

```php
<?php

declare(strict_types=1);

namespace formflow;

use PDO;
use RuntimeException;

final class SqliteSubmissionRepository implements SubmissionRepositoryInterface
{
    private PDO $pdo;

    public function __construct(string $databasePath)
    {
        if ($databasePath !== ':memory:') {
            $directory = dirname($databasePath);

            if (
                !is_dir($directory)
                && !mkdir($directory, 0775, true)
                && !is_dir($directory)
            ) {
                throw new RuntimeException('Unable to create the storage directory.');
            }
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
        ?string $ipHash,
        string $status = 'received'
    ): int {
        $statement = $this->pdo->prepare(
            'INSERT INTO submissions (form_id, payload, ip_hash, status, created_at)
             VALUES (:form_id, :payload, :ip_hash, :status, :created_at)'
        );

        $statement->execute([
            'form_id' => $formId,
            'payload' => json_encode(
                $payload,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ),
            'ip_hash' => $ipHash,
            'status' => $status,
            'created_at' => gmdate('c'),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function markSent(int $submissionId): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE submissions
             SET status = :status, sent_at = :sent_at, error_message = NULL
             WHERE id = :id'
        );

        $statement->execute([
            'status' => 'sent',
            'sent_at' => gmdate('c'),
            'id' => $submissionId,
        ]);
    }

    public function markFailed(int $submissionId, string $errorMessage): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE submissions
             SET status = :status, error_message = :error_message
             WHERE id = :id'
        );

        $statement->execute([
            'status' => 'failed',
            'error_message' => mb_substr($errorMessage, 0, 1000),
            'id' => $submissionId,
        ]);
    }

    public function find(int $submissionId): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM submissions WHERE id = :id');
        $statement->execute(['id' => $submissionId]);

        $row = $statement->fetch();

        return $row === false ? null : $row;
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

        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_submissions_form_id ON submissions(form_id)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_submissions_status ON submissions(status)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_submissions_created_at ON submissions(created_at)');
    }
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/SqliteSubmissionRepositoryTest.php`
Expected: `OK (5 tests, ...)`.

- [ ] **Step 6: Commit**

```bash
git add src/SubmissionRepositoryInterface.php src/SqliteSubmissionRepository.php tests/SqliteSubmissionRepositoryTest.php
git commit -m "feat: add SQLite submission repository behind an interface"
```

---

### Task 4: Rate limiter

**Files:**
- Create: `src/RateLimiterInterface.php`
- Create: `src/SqliteRateLimiter.php`
- Test: `tests/SqliteRateLimiterTest.php`

**Interfaces:**
- Consumes: Composer autoload from Task 1.
- Produces: `RateLimiterInterface::hit(string $formId, ?string $ipHash): void`, `::countRecentHitsByIp(string $formId, ?string $ipHash, int $windowMinutes): int`, `::countRecentHitsForForm(string $formId, int $windowMinutes): int`. `FormHandler` (Task 7) depends on this.

- [ ] **Step 1: Write `src/RateLimiterInterface.php`**

```php
<?php

declare(strict_types=1);

namespace formflow;

interface RateLimiterInterface
{
    public function hit(string $formId, ?string $ipHash): void;

    public function countRecentHitsByIp(string $formId, ?string $ipHash, int $windowMinutes): int;

    public function countRecentHitsForForm(string $formId, int $windowMinutes): int;
}
```

- [ ] **Step 2: Write the failing test `tests/SqliteRateLimiterTest.php`**

```php
<?php

declare(strict_types=1);

namespace formflow\Tests;

use formflow\SqliteRateLimiter;
use PHPUnit\Framework\TestCase;

final class SqliteRateLimiterTest extends TestCase
{
    public function testCountRecentHitsByIpCountsOnlyMatchingFormAndIp(): void
    {
        $limiter = new SqliteRateLimiter(':memory:');

        $limiter->hit('contact', 'ip-a');
        $limiter->hit('contact', 'ip-a');
        $limiter->hit('contact', 'ip-b');
        $limiter->hit('support', 'ip-a');

        $count = $limiter->countRecentHitsByIp('contact', 'ip-a', 10);

        $this->assertSame(2, $count);
    }

    public function testCountRecentHitsByIpTreatsNullIpHashSeparately(): void
    {
        $limiter = new SqliteRateLimiter(':memory:');

        $limiter->hit('contact', null);
        $limiter->hit('contact', 'ip-a');

        $count = $limiter->countRecentHitsByIp('contact', null, 10);

        $this->assertSame(1, $count);
    }

    public function testCountRecentHitsForFormCountsAcrossAllIps(): void
    {
        $limiter = new SqliteRateLimiter(':memory:');

        $limiter->hit('contact', 'ip-a');
        $limiter->hit('contact', 'ip-b');
        $limiter->hit('contact', 'ip-c');
        $limiter->hit('support', 'ip-a');

        $count = $limiter->countRecentHitsForForm('contact', 1440);

        $this->assertSame(3, $count);
    }

    public function testHitsForOneFormDoNotAffectAnotherForm(): void
    {
        $limiter = new SqliteRateLimiter(':memory:');

        $limiter->hit('contact', 'ip-a');

        $this->assertSame(0, $limiter->countRecentHitsByIp('support', 'ip-a', 10));
        $this->assertSame(0, $limiter->countRecentHitsForForm('support', 10));
    }
}
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/SqliteRateLimiterTest.php`
Expected: FAIL — `Error: Class "formflow\SqliteRateLimiter" not found`.

- [ ] **Step 4: Write `src/SqliteRateLimiter.php`**

```php
<?php

declare(strict_types=1);

namespace formflow;

use PDO;
use RuntimeException;

final class SqliteRateLimiter implements RateLimiterInterface
{
    private PDO $pdo;

    public function __construct(string $databasePath)
    {
        if ($databasePath !== ':memory:') {
            $directory = dirname($databasePath);

            if (
                !is_dir($directory)
                && !mkdir($directory, 0775, true)
                && !is_dir($directory)
            ) {
                throw new RuntimeException('Unable to create the storage directory.');
            }
        }

        $this->pdo = new PDO('sqlite:' . $databasePath, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $this->pdo->exec('PRAGMA journal_mode = WAL');
        $this->pdo->exec('PRAGMA busy_timeout = 5000');

        $this->createSchema();
    }

    public function hit(string $formId, ?string $ipHash): void
    {
        $this->pruneOldHits();

        $statement = $this->pdo->prepare(
            'INSERT INTO rate_limit_hits (form_id, ip_hash, created_at)
             VALUES (:form_id, :ip_hash, :created_at)'
        );

        $statement->execute([
            'form_id' => $formId,
            'ip_hash' => $ipHash,
            'created_at' => gmdate('c'),
        ]);
    }

    public function countRecentHitsByIp(string $formId, ?string $ipHash, int $windowMinutes): int
    {
        $since = gmdate('c', time() - ($windowMinutes * 60));

        if ($ipHash === null) {
            $statement = $this->pdo->prepare(
                'SELECT COUNT(*) AS hits FROM rate_limit_hits
                 WHERE form_id = :form_id AND ip_hash IS NULL AND created_at >= :since'
            );

            $statement->execute([
                'form_id' => $formId,
                'since' => $since,
            ]);
        } else {
            $statement = $this->pdo->prepare(
                'SELECT COUNT(*) AS hits FROM rate_limit_hits
                 WHERE form_id = :form_id AND ip_hash = :ip_hash AND created_at >= :since'
            );

            $statement->execute([
                'form_id' => $formId,
                'ip_hash' => $ipHash,
                'since' => $since,
            ]);
        }

        return (int) $statement->fetch()['hits'];
    }

    public function countRecentHitsForForm(string $formId, int $windowMinutes): int
    {
        $since = gmdate('c', time() - ($windowMinutes * 60));

        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) AS hits FROM rate_limit_hits
             WHERE form_id = :form_id AND created_at >= :since'
        );

        $statement->execute([
            'form_id' => $formId,
            'since' => $since,
        ]);

        return (int) $statement->fetch()['hits'];
    }

    private function pruneOldHits(): void
    {
        if (random_int(1, 100) > 2) {
            return;
        }

        $cutoff = gmdate('c', time() - 86400);

        $this->pdo
            ->prepare('DELETE FROM rate_limit_hits WHERE created_at < :cutoff')
            ->execute(['cutoff' => $cutoff]);
    }

    private function createSchema(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS rate_limit_hits (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                form_id TEXT NOT NULL,
                ip_hash TEXT,
                created_at TEXT NOT NULL
            )'
        );

        $this->pdo->exec(
            'CREATE INDEX IF NOT EXISTS idx_rate_limit_hits_form_ip
             ON rate_limit_hits(form_id, ip_hash, created_at)'
        );
    }
}
```

Note on `pruneOldHits`: called on every `hit()` with a 2% chance to delete rows older than 24h, so the table stays small without a cron job. The 24h cutoff is safe because the largest supported window (`daily_limit`, checked with `windowMinutes = 1440`) never needs to look further back than 24h.

- [ ] **Step 5: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/SqliteRateLimiterTest.php`
Expected: `OK (4 tests, ...)`.

- [ ] **Step 6: Commit**

```bash
git add src/RateLimiterInterface.php src/SqliteRateLimiter.php tests/SqliteRateLimiterTest.php
git commit -m "feat: add SQLite-backed rate limiter behind an interface"
```

---

### Task 5: IP blocklist

**Files:**
- Create: `src/IpBlocklist.php`
- Test: `tests/IpBlocklistTest.php`

**Interfaces:**
- Consumes: Composer autoload from Task 1.
- Produces: `IpBlocklist::__construct(array $blockedIps)`, `IpBlocklist::isBlocked(string $ip): bool`. `public/index.php` (Task 8) depends on this. IPv4 only (exact match or CIDR); not consumed by `FormHandler`.

- [ ] **Step 1: Write the failing test `tests/IpBlocklistTest.php`**

```php
<?php

declare(strict_types=1);

namespace formflow\Tests;

use formflow\IpBlocklist;
use PHPUnit\Framework\TestCase;

final class IpBlocklistTest extends TestCase
{
    public function testExactIpMatchIsBlocked(): void
    {
        $blocklist = new IpBlocklist(['203.0.113.5']);

        $this->assertTrue($blocklist->isBlocked('203.0.113.5'));
    }

    public function testUnlistedIpIsNotBlocked(): void
    {
        $blocklist = new IpBlocklist(['203.0.113.5']);

        $this->assertFalse($blocklist->isBlocked('198.51.100.9'));
    }

    public function testCidrRangeBlocksMatchingIp(): void
    {
        $blocklist = new IpBlocklist(['198.51.100.0/24']);

        $this->assertTrue($blocklist->isBlocked('198.51.100.42'));
    }

    public function testCidrRangeDoesNotBlockOutsideIp(): void
    {
        $blocklist = new IpBlocklist(['198.51.100.0/24']);

        $this->assertFalse($blocklist->isBlocked('198.51.101.42'));
    }

    public function testEmptyBlocklistNeverBlocks(): void
    {
        $blocklist = new IpBlocklist([]);

        $this->assertFalse($blocklist->isBlocked('203.0.113.5'));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/IpBlocklistTest.php`
Expected: FAIL — `Error: Class "formflow\IpBlocklist" not found`.

- [ ] **Step 3: Write `src/IpBlocklist.php`**

```php
<?php

declare(strict_types=1);

namespace formflow;

final class IpBlocklist
{
    /** @param list<string> $blockedIps */
    public function __construct(
        private readonly array $blockedIps
    ) {
    }

    public function isBlocked(string $ip): bool
    {
        foreach ($this->blockedIps as $entry) {
            if (str_contains($entry, '/')) {
                if ($this->ipInCidr($ip, $entry)) {
                    return true;
                }

                continue;
            }

            if ($entry === $ip) {
                return true;
            }
        }

        return false;
    }

    private function ipInCidr(string $ip, string $cidr): bool
    {
        [$subnet, $prefixLength] = explode('/', $cidr, 2);

        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);

        if ($ipLong === false || $subnetLong === false) {
            return false;
        }

        $prefixLength = (int) $prefixLength;

        if ($prefixLength < 0 || $prefixLength > 32) {
            return false;
        }

        if ($prefixLength === 0) {
            return true;
        }

        $mask = -1 << (32 - $prefixLength);

        return ($ipLong & $mask) === ($subnetLong & $mask);
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/IpBlocklistTest.php`
Expected: `OK (5 tests, ...)`.

- [ ] **Step 5: Commit**

```bash
git add src/IpBlocklist.php tests/IpBlocklistTest.php
git commit -m "feat: add IPv4 IP/CIDR blocklist"
```

---

### Task 6: Spam filter

**Files:**
- Create: `src/SpamFilter.php`
- Test: `tests/SpamFilterTest.php`

**Interfaces:**
- Consumes: Composer autoload from Task 1.
- Produces: `SpamFilter::__construct(array $blockedPatterns)`, `SpamFilter::isSpam(array $fields): bool`. `FormHandler` (Task 7) instantiates this per-request from `$config['blocked_patterns'] ?? []`.

- [ ] **Step 1: Write the failing test `tests/SpamFilterTest.php`**

```php
<?php

declare(strict_types=1);

namespace formflow\Tests;

use formflow\SpamFilter;
use PHPUnit\Framework\TestCase;

final class SpamFilterTest extends TestCase
{
    public function testMatchingPatternIsFlaggedAsSpam(): void
    {
        $filter = new SpamFilter(['viagra']);

        $this->assertTrue($filter->isSpam(['message' => 'Buy cheap Viagra online']));
    }

    public function testNoMatchIsNotSpam(): void
    {
        $filter = new SpamFilter(['viagra']);

        $this->assertFalse($filter->isSpam(['message' => 'Hello, I have a question.']));
    }

    public function testEmptyPatternListNeverFlagsSpam(): void
    {
        $filter = new SpamFilter([]);

        $this->assertFalse($filter->isSpam(['message' => 'Buy cheap viagra online']));
    }

    public function testMatchIsCaseInsensitive(): void
    {
        $filter = new SpamFilter(['VIAGRA']);

        $this->assertTrue($filter->isSpam(['message' => 'viagra deals']));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/SpamFilterTest.php`
Expected: FAIL — `Error: Class "formflow\SpamFilter" not found`.

- [ ] **Step 3: Write `src/SpamFilter.php`**

```php
<?php

declare(strict_types=1);

namespace formflow;

final class SpamFilter
{
    /** @param list<string> $blockedPatterns */
    public function __construct(
        private readonly array $blockedPatterns
    ) {
    }

    /** @param array<string, string> $fields */
    public function isSpam(array $fields): bool
    {
        if ($this->blockedPatterns === []) {
            return false;
        }

        $haystack = mb_strtolower(implode(' ', $fields));

        foreach ($this->blockedPatterns as $pattern) {
            if (str_contains($haystack, mb_strtolower($pattern))) {
                return true;
            }
        }

        return false;
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/SpamFilterTest.php`
Expected: `OK (4 tests, ...)`.

- [ ] **Step 5: Commit**

```bash
git add src/SpamFilter.php tests/SpamFilterTest.php
git commit -m "feat: add simple substring-based spam filter"
```

---

### Task 7: FormHandler orchestrator

**Files:**
- Create: `src/FormHandler.php`
- Create: `tests/Fakes/FakeMailSender.php`
- Create: `tests/Fakes/FakeTurnstileVerifier.php`
- Test: `tests/FormHandlerTest.php`

**Interfaces:**
- Consumes: `SubmissionRepositoryInterface` (Task 3), `MailSenderInterface` (Task 2), `TurnstileVerifierInterface` (Task 2), `RateLimiterInterface` (Task 4), `SpamFilter` (Task 6).
- Produces: `FormHandler::__construct(array $forms, MailSenderInterface $mailService, SubmissionRepositoryInterface $repository, TurnstileVerifierInterface $turnstile, RateLimiterInterface $rateLimiter, string $ipHashSecret)` and `FormHandler::handle(string $formId): array` returning `['status' => int, 'body' => array, 'redirect' => ?string]`. `public/index.php` (Task 8) depends on this exact constructor signature and return shape.

- [ ] **Step 1: Write the test fakes**

`tests/Fakes/FakeMailSender.php`:

```php
<?php

declare(strict_types=1);

namespace formflow\Tests\Fakes;

use formflow\MailSenderInterface;
use RuntimeException;

final class FakeMailSender implements MailSenderInterface
{
    /** @var list<array{recipient: string, subject: string, fields: array<string, string>}> */
    public array $sentMessages = [];

    public bool $shouldThrow = false;

    public function send(string $recipient, string $subject, array $fields): void
    {
        if ($this->shouldThrow) {
            throw new RuntimeException('Simulated SMTP failure.');
        }

        $this->sentMessages[] = [
            'recipient' => $recipient,
            'subject' => $subject,
            'fields' => $fields,
        ];
    }
}
```

`tests/Fakes/FakeTurnstileVerifier.php`:

```php
<?php

declare(strict_types=1);

namespace formflow\Tests\Fakes;

use formflow\TurnstileVerifierInterface;

final class FakeTurnstileVerifier implements TurnstileVerifierInterface
{
    public function __construct(
        private readonly bool $result = true
    ) {
    }

    public function verify(string $token, ?string $remoteIp = null): bool
    {
        return $this->result;
    }
}
```

- [ ] **Step 2: Write the failing test `tests/FormHandlerTest.php`**

```php
<?php

declare(strict_types=1);

namespace formflow\Tests;

use formflow\FormHandler;
use formflow\SqliteRateLimiter;
use formflow\SqliteSubmissionRepository;
use formflow\Tests\Fakes\FakeMailSender;
use formflow\Tests\Fakes\FakeTurnstileVerifier;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class FormHandlerTest extends TestCase
{
    protected function setUp(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['HTTP_ORIGIN'] = 'https://example.com';
        $_SERVER['REMOTE_ADDR'] = '198.51.100.10';
        unset($_SERVER['HTTP_REFERER']);
        $_POST = [];
    }

    protected function tearDown(): void
    {
        unset(
            $_SERVER['REQUEST_METHOD'],
            $_SERVER['HTTP_ORIGIN'],
            $_SERVER['HTTP_REFERER'],
            $_SERVER['REMOTE_ADDR']
        );
        $_POST = [];
    }

    /** @param array<string, mixed> $overrides */
    private function contactForm(array $overrides = []): array
    {
        return array_merge([
            'recipient' => 'hello@example.com',
            'allowed_origins' => ['https://example.com'],
            'allowed_fields' => ['name', 'email', 'message'],
            'required_fields' => ['name', 'email', 'message'],
            'subject' => 'New contact form submission',
            'success_redirect' => 'https://example.com/thank-you',
            'turnstile' => true,
        ], $overrides);
    }

    private function makeHandler(
        array $forms,
        ?FakeMailSender $mailSender = null,
        ?FakeTurnstileVerifier $turnstile = null,
        ?SqliteSubmissionRepository $repository = null,
        ?SqliteRateLimiter $rateLimiter = null
    ): FormHandler {
        return new FormHandler(
            $forms,
            $mailSender ?? new FakeMailSender(),
            $repository ?? new SqliteSubmissionRepository(':memory:'),
            $turnstile ?? new FakeTurnstileVerifier(true),
            $rateLimiter ?? new SqliteRateLimiter(':memory:'),
            'test-secret'
        );
    }

    public function testUnknownFormReturns404(): void
    {
        $handler = $this->makeHandler(['contact' => $this->contactForm()]);

        $result = $handler->handle('missing-form');

        $this->assertSame(404, $result['status']);
        $this->assertFalse($result['body']['success']);
    }

    public function testNonPostMethodReturns405(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $handler = $this->makeHandler(['contact' => $this->contactForm()]);

        $result = $handler->handle('contact');

        $this->assertSame(405, $result['status']);
    }

    public function testDisallowedOriginThrowsInvalidArgumentException(): void
    {
        $_SERVER['HTTP_ORIGIN'] = 'https://evil.example';

        $handler = $this->makeHandler(['contact' => $this->contactForm()]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Origin is not allowed.');

        $handler->handle('contact');
    }

    public function testHoneypotFieldReturnsFakeSuccessAndLogsBlockedHoneypot(): void
    {
        $_POST = [
            'name' => 'Bot',
            'email' => 'bot@example.com',
            'message' => 'spam',
            '_website' => 'http://spam.example',
        ];

        $mailSender = new FakeMailSender();
        $repository = new SqliteSubmissionRepository(':memory:');

        $handler = $this->makeHandler(
            ['contact' => $this->contactForm()],
            $mailSender,
            null,
            $repository
        );

        $result = $handler->handle('contact');

        $this->assertSame(200, $result['status']);
        $this->assertTrue($result['body']['success']);
        $this->assertSame([], $mailSender->sentMessages);

        $row = $repository->find(1);
        $this->assertSame('blocked_honeypot', $row['status']);
    }

    public function testMissingRequiredFieldThrowsInvalidArgumentException(): void
    {
        $_POST = ['name' => 'Ada', 'message' => 'Hello'];

        $handler = $this->makeHandler(['contact' => $this->contactForm()]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Field "email" is required.');

        $handler->handle('contact');
    }

    public function testInvalidEmailThrowsInvalidArgumentException(): void
    {
        $_POST = ['name' => 'Ada', 'email' => 'not-an-email', 'message' => 'Hello'];

        $handler = $this->makeHandler(['contact' => $this->contactForm()]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The email field is invalid.');

        $handler->handle('contact');
    }

    public function testFailedTurnstileReturns422(): void
    {
        $_POST = [
            'name' => 'Ada',
            'email' => 'ada@example.com',
            'message' => 'Hello',
            'cf-turnstile-response' => 'bad-token',
        ];

        $handler = $this->makeHandler(
            ['contact' => $this->contactForm()],
            null,
            new FakeTurnstileVerifier(false)
        );

        $result = $handler->handle('contact');

        $this->assertSame(422, $result['status']);
        $this->assertFalse($result['body']['success']);
    }

    public function testSuccessfulSubmissionSendsEmailAndStoresSent(): void
    {
        $_POST = [
            'name' => 'Ada',
            'email' => 'ada@example.com',
            'message' => 'Hello',
            'cf-turnstile-response' => 'good-token',
        ];

        $mailSender = new FakeMailSender();
        $repository = new SqliteSubmissionRepository(':memory:');

        $handler = $this->makeHandler(
            ['contact' => $this->contactForm()],
            $mailSender,
            new FakeTurnstileVerifier(true),
            $repository
        );

        $result = $handler->handle('contact');

        $this->assertSame(200, $result['status']);
        $this->assertTrue($result['body']['success']);
        $this->assertSame('https://example.com/thank-you', $result['redirect']);
        $this->assertCount(1, $mailSender->sentMessages);
        $this->assertSame('hello@example.com', $mailSender->sentMessages[0]['recipient']);

        $row = $repository->find(1);
        $this->assertSame('sent', $row['status']);
    }

    public function testMailFailureMarksSubmissionFailedAndThrows(): void
    {
        $_POST = ['name' => 'Ada', 'email' => 'ada@example.com', 'message' => 'Hello'];

        $mailSender = new FakeMailSender();
        $mailSender->shouldThrow = true;

        $repository = new SqliteSubmissionRepository(':memory:');

        $handler = $this->makeHandler(
            ['contact' => $this->contactForm()],
            $mailSender,
            new FakeTurnstileVerifier(true),
            $repository
        );

        $this->expectException(RuntimeException::class);

        try {
            $handler->handle('contact');
        } finally {
            $row = $repository->find(1);
            $this->assertSame('failed', $row['status']);
            $this->assertSame('Simulated SMTP failure.', $row['error_message']);
        }
    }

    public function testApiKeyMismatchThrowsInvalidArgumentException(): void
    {
        $_POST = [
            'name' => 'Ada',
            'email' => 'ada@example.com',
            'message' => 'Hello',
            '_key' => 'wrong-key',
        ];

        $handler = $this->makeHandler([
            'contact' => $this->contactForm(['api_key' => 'correct-key']),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid API key.');

        $handler->handle('contact');
    }

    public function testApiKeyMatchAllowsSubmission(): void
    {
        $_POST = [
            'name' => 'Ada',
            'email' => 'ada@example.com',
            'message' => 'Hello',
            '_key' => 'correct-key',
            'cf-turnstile-response' => 'good-token',
        ];

        $handler = $this->makeHandler([
            'contact' => $this->contactForm(['api_key' => 'correct-key']),
        ]);

        $result = $handler->handle('contact');

        $this->assertSame(200, $result['status']);
    }

    public function testSpamFilterMatchReturnsFakeSuccessAndLogsBlockedSpam(): void
    {
        $_POST = [
            'name' => 'Ada',
            'email' => 'ada@example.com',
            'message' => 'Buy cheap viagra now',
        ];

        $mailSender = new FakeMailSender();
        $repository = new SqliteSubmissionRepository(':memory:');

        $handler = $this->makeHandler(
            ['contact' => $this->contactForm(['blocked_patterns' => ['viagra']])],
            $mailSender,
            null,
            $repository
        );

        $result = $handler->handle('contact');

        $this->assertSame(200, $result['status']);
        $this->assertSame([], $mailSender->sentMessages);

        $row = $repository->find(1);
        $this->assertSame('blocked_spam', $row['status']);
    }

    public function testRateLimitPerIpExceededReturns429(): void
    {
        $_POST = [
            'name' => 'Ada',
            'email' => 'ada@example.com',
            'message' => 'Hello',
            'cf-turnstile-response' => 'good-token',
        ];

        $rateLimiter = new SqliteRateLimiter(':memory:');
        $form = $this->contactForm([
            'rate_limit_per_ip' => ['max' => 2, 'window_minutes' => 10],
        ]);

        $handler = $this->makeHandler(
            ['contact' => $form],
            null,
            new FakeTurnstileVerifier(true),
            new SqliteSubmissionRepository(':memory:'),
            $rateLimiter
        );

        $handler->handle('contact');
        $handler->handle('contact');
        $result = $handler->handle('contact');

        $this->assertSame(429, $result['status']);
    }

    public function testDailyLimitExceededReturns429(): void
    {
        $_POST = [
            'name' => 'Ada',
            'email' => 'ada@example.com',
            'message' => 'Hello',
            'cf-turnstile-response' => 'good-token',
        ];

        $rateLimiter = new SqliteRateLimiter(':memory:');
        $form = $this->contactForm([
            'rate_limit_per_ip' => ['max' => 1000, 'window_minutes' => 10],
            'daily_limit' => 1,
        ]);

        $handler = $this->makeHandler(
            ['contact' => $form],
            null,
            new FakeTurnstileVerifier(true),
            new SqliteSubmissionRepository(':memory:'),
            $rateLimiter
        );

        $handler->handle('contact');

        $_SERVER['REMOTE_ADDR'] = '198.51.100.99';
        $result = $handler->handle('contact');

        $this->assertSame(429, $result['status']);
    }
}
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/FormHandlerTest.php`
Expected: FAIL — `Error: Class "formflow\FormHandler" not found`.

- [ ] **Step 4: Write `src/FormHandler.php`**

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
        private readonly MailSenderInterface $mailService,
        private readonly SubmissionRepositoryInterface $repository,
        private readonly TurnstileVerifierInterface $turnstile,
        private readonly RateLimiterInterface $rateLimiter,
        private readonly string $ipHashSecret
    ) {
    }

    public function handle(string $formId): array
    {
        if (!isset($this->forms[$formId])) {
            return [
                'status' => 404,
                'body' => ['success' => false, 'message' => 'Form not found.'],
            ];
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return [
                'status' => 405,
                'body' => ['success' => false, 'message' => 'Method not allowed.'],
            ];
        }

        $config = $this->forms[$formId];

        $this->assertAllowedOrigin($config);

        $ipHash = $this->ipHash($this->clientIp());

        $this->rateLimiter->hit($formId, $ipHash);

        $perIpLimit = array_merge(
            ['max' => 5, 'window_minutes' => 10],
            $config['rate_limit_per_ip'] ?? []
        );

        $recentIpHits = $this->rateLimiter->countRecentHitsByIp(
            $formId,
            $ipHash,
            (int) $perIpLimit['window_minutes']
        );

        if ($recentIpHits > (int) $perIpLimit['max']) {
            return [
                'status' => 429,
                'body' => ['success' => false, 'message' => 'Too many submissions. Please try again later.'],
            ];
        }

        $dailyLimit = (int) ($config['daily_limit'] ?? 200);
        $todayHits = $this->rateLimiter->countRecentHitsForForm($formId, 1440);

        if ($todayHits > $dailyLimit) {
            return [
                'status' => 429,
                'body' => ['success' => false, 'message' => 'Daily submission limit reached for this form.'],
            ];
        }

        $this->assertApiKey($config);

        if (!empty($_POST['_website'])) {
            try {
                $honeypotFields = $this->extractAllowedFields($_POST, $config['allowed_fields'] ?? []);
            } catch (InvalidArgumentException) {
                $honeypotFields = [];
            }

            $this->repository->create($formId, $honeypotFields, $ipHash, 'blocked_honeypot');

            return [
                'status' => 200,
                'body' => ['success' => true, 'message' => 'Submission accepted.'],
                'redirect' => $config['success_redirect'] ?? null,
            ];
        }

        $fields = $this->extractAllowedFields($_POST, $config['allowed_fields'] ?? []);

        $this->validateRequiredFields($fields, $config['required_fields'] ?? []);
        $this->validateEmailField($fields);

        $spamFilter = new SpamFilter($config['blocked_patterns'] ?? []);

        if ($spamFilter->isSpam($fields)) {
            $this->repository->create($formId, $fields, $ipHash, 'blocked_spam');

            return [
                'status' => 200,
                'body' => ['success' => true, 'message' => 'Submission accepted.'],
                'redirect' => $config['success_redirect'] ?? null,
            ];
        }

        if (($config['turnstile'] ?? false) === true) {
            $token = (string) ($_POST['cf-turnstile-response'] ?? '');

            if (!$this->turnstile->verify($token, $this->clientIp())) {
                return [
                    'status' => 422,
                    'body' => ['success' => false, 'message' => 'Turnstile validation failed.'],
                ];
            }
        }

        $submissionId = $this->repository->create($formId, $fields, $ipHash);

        try {
            $this->mailService->send(
                (string) $config['recipient'],
                (string) ($config['subject'] ?? 'New form submission'),
                $fields
            );

            $this->repository->markSent($submissionId);
        } catch (Throwable $exception) {
            $this->repository->markFailed($submissionId, $exception->getMessage());

            throw new RuntimeException('Unable to send the submission email.', 0, $exception);
        }

        return [
            'status' => 200,
            'body' => ['success' => true, 'message' => 'Submission sent successfully.'],
            'redirect' => $config['success_redirect'] ?? null,
        ];
    }

    private function assertAllowedOrigin(array $config): void
    {
        $allowedOrigins = $config['allowed_origins'] ?? [];

        if ($allowedOrigins === []) {
            return;
        }

        $origin = $_SERVER['HTTP_ORIGIN'] ?? null;

        if (is_string($origin) && in_array($origin, $allowedOrigins, true)) {
            return;
        }

        $referer = $_SERVER['HTTP_REFERER'] ?? null;

        if (is_string($referer)) {
            foreach ($allowedOrigins as $allowedOrigin) {
                if ($referer === $allowedOrigin) {
                    return;
                }

                if (str_starts_with($referer, rtrim($allowedOrigin, '/') . '/')) {
                    return;
                }
            }
        }

        throw new InvalidArgumentException('Origin is not allowed.');
    }

    private function assertApiKey(array $config): void
    {
        $expectedKey = $config['api_key'] ?? null;

        if ($expectedKey === null || $expectedKey === '') {
            return;
        }

        $providedKey = (string) ($_POST['_key'] ?? '');

        if (!hash_equals((string) $expectedKey, $providedKey)) {
            throw new InvalidArgumentException('Invalid API key.');
        }
    }

    private function extractAllowedFields(array $input, array $allowedFields): array
    {
        $result = [];

        foreach ($allowedFields as $field) {
            if (!array_key_exists($field, $input)) {
                continue;
            }

            $value = $input[$field];

            if (is_array($value)) {
                $value = implode(', ', array_map('strval', $value));
            }

            $value = trim((string) $value);

            if (mb_strlen($value) > 10000) {
                throw new InvalidArgumentException(sprintf('Field "%s" is too long.', $field));
            }

            $result[$field] = $value;
        }

        return $result;
    }

    private function validateRequiredFields(array $fields, array $requiredFields): void
    {
        foreach ($requiredFields as $field) {
            if (!isset($fields[$field]) || $fields[$field] === '') {
                throw new InvalidArgumentException(sprintf('Field "%s" is required.', $field));
            }
        }
    }

    private function validateEmailField(array $fields): void
    {
        if (!isset($fields['email']) || $fields['email'] === '') {
            return;
        }

        if (!filter_var($fields['email'], FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('The email field is invalid.');
        }
    }

    private function clientIp(): ?string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;

        return is_string($ip) && $ip !== '' ? $ip : null;
    }

    private function ipHash(?string $ip): ?string
    {
        if ($ip === null) {
            return null;
        }

        return hash_hmac('sha256', $ip . '|' . date('Y-m'), $this->ipHashSecret);
    }
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/FormHandlerTest.php`
Expected: `OK (14 tests, ...)`.

- [ ] **Step 6: Run the full test suite so far**

Run: `vendor/bin/phpunit`
Expected: All tests across all test files pass, e.g. `OK (28 tests, ...)` with zero failures/errors.

- [ ] **Step 7: Commit**

```bash
git add src/FormHandler.php tests/Fakes/FakeMailSender.php tests/Fakes/FakeTurnstileVerifier.php tests/FormHandlerTest.php
git commit -m "feat: add FormHandler orchestrating validation, rate limiting, spam filter, mail and storage"
```

---

### Task 8: Front controller wiring

**Files:**
- Create: `public/index.php`

**Interfaces:**
- Consumes: `FormHandler` (Task 7), `SqliteSubmissionRepository` (Task 3), `SqliteRateLimiter` (Task 4), `MailService`/`Turnstile` (Task 2), `IpBlocklist` (Task 5), `config/forms.php` / `config/security.php` (Task 1).
- Produces: the public HTTP endpoint — `GET /health`, `POST /{formId}`. No later task depends on this file.

- [ ] **Step 1: Write `public/index.php`**

```php
<?php

declare(strict_types=1);

use formflow\FormHandler;
use formflow\IpBlocklist;
use formflow\MailService;
use formflow\SqliteRateLimiter;
use formflow\SqliteSubmissionRepository;
use formflow\Turnstile;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);

if (is_file($root . '/.env')) {
    (new Dotenv())->usePutenv()->load($root . '/.env');
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$formId = trim((string) $path, '/');

if ($formId === 'health') {
    header('Content-Type: application/json; charset=utf-8');

    echo json_encode([
        'status' => 'ok',
        'service' => 'formflow',
        'time' => gmdate('c'),
    ], JSON_UNESCAPED_SLASHES);

    exit;
}

$security = require $root . '/config/security.php';
$blocklist = new IpBlocklist($security['blocked_ips'] ?? []);

$clientIp = $_SERVER['REMOTE_ADDR'] ?? null;

if (is_string($clientIp) && $blocklist->isBlocked($clientIp)) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');

    echo json_encode(['success' => false, 'message' => 'Forbidden.'], JSON_UNESCAPED_SLASHES);

    exit;
}

$forms = require $root . '/config/forms.php';

$databasePath = getenv('DATABASE_PATH') ?: 'storage/submissions.sqlite';

if (!str_starts_with($databasePath, '/')) {
    $databasePath = $root . '/' . ltrim($databasePath, '/');
}

$handler = new FormHandler(
    $forms,
    new MailService(
        getenv('MAILER_DSN') ?: '',
        getenv('MAIL_FROM') ?: '',
        getenv('MAIL_FROM_NAME') ?: 'formflow'
    ),
    new SqliteSubmissionRepository($databasePath),
    new Turnstile(getenv('TURNSTILE_SECRET') ?: ''),
    new SqliteRateLimiter($databasePath),
    getenv('IP_HASH_SECRET') ?: 'change-me'
);

try {
    $result = $handler->handle($formId);

    http_response_code($result['status']);

    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    $expectsJson = str_contains($accept, 'application/json');

    if (
        !$expectsJson
        && !empty($result['redirect'])
        && ($result['body']['success'] ?? false) === true
    ) {
        header('Location: ' . $result['redirect'], true, 303);
        exit;
    }

    header('Content-Type: application/json; charset=utf-8');

    echo json_encode(
        $result['body'],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    );
} catch (InvalidArgumentException $exception) {
    http_response_code(422);
    header('Content-Type: application/json; charset=utf-8');

    echo json_encode([
        'success' => false,
        'message' => $exception->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    error_log($exception->__toString());

    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');

    echo json_encode([
        'success' => false,
        'message' => 'Internal server error.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
```

- [ ] **Step 2: Start the local PHP server**

Run: `php -S localhost:8080 -t public` (run in background — e.g. append `&` or use a separate terminal/background job — since it's a long-running server process)
Expected: `[Sun Jul 23 ... ] PHP 8.5.x Development Server (http://localhost:8080) started`.

- [ ] **Step 3: Verify the health endpoint**

Run: `curl -s http://localhost:8080/health`
Expected: `{"status":"ok","service":"formflow","time":"..."}`.

- [ ] **Step 4: Verify unknown form returns 404**

Run: `curl -s -o /dev/null -w "%{http_code}\n" http://localhost:8080/does-not-exist -X POST`
Expected: `404`.

- [ ] **Step 5: Verify disallowed origin returns 422**

Run:
```bash
curl -s -X POST http://localhost:8080/contact \
    -H "Origin: https://evil.example" \
    -H "Accept: application/json" \
    -F "name=Test" -F "email=test@example.com" -F "message=hi"
```
Expected: HTTP 422 body `{"success":false,"message":"Origin is not allowed."}`.

- [ ] **Step 6: Verify honeypot returns fake success without sending mail**

Note: the default `config/forms.php` `contact` form has `api_key` set, so a real end-to-end submit needs `_key`. This step targets the honeypot path, which is checked before field validation but after the API key check — include the correct `_key`.

Run:
```bash
curl -s -X POST http://localhost:8080/contact \
    -H "Origin: https://example.com" \
    -H "Accept: application/json" \
    -F "_key=replace-with-a-long-random-value" \
    -F "_website=http://spam.example" \
    -F "name=Bot" -F "email=bot@example.com" -F "message=spam"
```
Expected: HTTP 200 body `{"success":true,"message":"Submission accepted."}`. Inspect `storage/submissions.sqlite` to confirm no email was attempted (no live SMTP is configured, so a real send would instead surface as a 500 — a 200 here confirms the honeypot short-circuit fired before mail was attempted):

```bash
sqlite3 storage/submissions.sqlite "SELECT form_id, status FROM submissions ORDER BY id DESC LIMIT 1;"
```
Expected: `contact|blocked_honeypot`.

- [ ] **Step 7: Verify blocked IP returns 403**

Edit `config/security.php` temporarily to add your own test IP (e.g. `127.0.0.1` if testing via `localhost`, but note `curl` to `localhost` typically connects as `127.0.0.1`):

```php
'blocked_ips' => ['127.0.0.1'],
```

Run: `curl -s -o /dev/null -w "%{http_code}\n" http://localhost:8080/health` — expected `200` (health check bypasses the blocklist, matches Task 8 Step 1 ordering).

Run: `curl -s -o /dev/null -w "%{http_code}\n" http://localhost:8080/contact -X POST`
Expected: `403`.

Revert `config/security.php` back to the empty example afterward so the blocklist doesn't stay active:

```php
'blocked_ips' => [
    // '203.0.113.5',
    // '198.51.100.0/24',
],
```

- [ ] **Step 8: Stop the local PHP server**

Stop the background `php -S` process (e.g. `kill %1` or Ctrl-C in its terminal).

- [ ] **Step 9: Commit**

```bash
git add public/index.php
git commit -m "feat: wire FormHandler, IP blocklist and config into the public front controller"
```

---

### Task 9: README and final verification

**Files:**
- Modify: `README.md`

**Interfaces:**
- Consumes: everything from Tasks 1-8.
- Produces: nothing further consumes this (final task).

- [ ] **Step 1: Rewrite `README.md`**

```markdown
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

## Production deployment

Nginx/Apache конфигурация, права за `storage/`, backup, GDPR препоръки — виж [`formflow.md`](formflow.md).
```

- [ ] **Step 2: Run the full automated test suite**

Run: `vendor/bin/phpunit`
Expected: All tests pass, zero failures/errors (same count as Task 7 Step 6).

- [ ] **Step 3: Lint every PHP file**

Run: `find src public config tests -name "*.php" -exec php -l {} \;`
Expected: `No syntax errors detected in ...` for every file, no failures.

- [ ] **Step 4: Commit**

```bash
git add README.md
git commit -m "docs: rewrite README with install, run and test instructions"
```

---

## Self-Review

**Spec coverage:** multiple forms/recipients/subjects (Task 1 config), allowed origins/fields/required fields/email validation (Task 7), Turnstile (Task 2 + 7), honeypot (Task 7), SQLite archive (Task 3), SMTP + Reply-To (Task 2), JSON response + success redirect (Task 8), health endpoint (Task 8), failed-submission logging (Task 3 `markFailed` + Task 7), storage behind an interface (Tasks 3/7), per-form `api_key` (Task 7), rate limiting by IP+form and daily cap (Tasks 4/7), IP blocklist (Tasks 5/8), spam filter (Tasks 6/7), PHPUnit coverage (Tasks 3-7) — all covered. CORS/JS-fetch and MySQL/Postgres implementations are explicitly out of scope per the addendum and are not tasked.

**Placeholder scan:** no TBD/TODO; every step has runnable commands or complete code.

**Type consistency:** `FormHandler` constructor order (`forms, mailService, repository, turnstile, rateLimiter, ipHashSecret`) matches Task 7's `makeHandler` helper and Task 8's `index.php` instantiation. `SubmissionRepositoryInterface::create()`'s `$status` default and `find()` method are used identically across Tasks 3 and 7. `RateLimiterInterface` method names (`hit`, `countRecentHitsByIp`, `countRecentHitsForForm`) match between Task 4's interface/implementation and Task 7's `FormHandler` usage.

---

Plan complete and saved to `docs/superpowers/plans/2026-07-23-formflow-v1-implementation.md`. Two execution options:

**1. Subagent-Driven (recommended)** — I dispatch a fresh subagent per task, review between tasks, fast iteration.

**2. Inline Execution** — Execute tasks in this session using executing-plans, batch execution with checkpoints.

Which approach?
