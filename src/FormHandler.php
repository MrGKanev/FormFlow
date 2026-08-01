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
        private readonly string $ipHashSecret,
        private readonly FormApiKeyRepositoryInterface $apiKeys,
        private readonly ?WebhookNotifierInterface $webhookNotifier = null,
        private readonly string $uploadDirectory = '',
        private readonly ?CaptchaVerifierInterface $captchaVerifier = null,
        private readonly ?string $clientIp = null,
        private readonly bool $deferMail = false
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

        $rawConfig = $this->forms[$formId];
        $config = FormConfigValidator::normalize($formId, $rawConfig);

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

        $this->assertApiKey($formId, !empty($config['require_api_key']));

        if (!empty($_POST['_website'])) {
            try {
                $honeypotFields = $this->extractFields($_POST);
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

        $fields = array_merge(
            $this->extractFields($_POST),
            $this->extractUploadedFiles($_FILES ?? [], $config['uploads'] ?? [])
        );

        $this->validateEmailField($fields);

        $spamFilter = new SpamFilter($config['blocked_patterns'] ?? []);

        if ($spamFilter->isSpam(SubmissionPayloadFormatter::displayFields($fields))) {
            $this->repository->create($formId, $fields, $ipHash, 'blocked_spam');

            return [
                'status' => 200,
                'body' => ['success' => true, 'message' => 'Submission accepted.'],
                'redirect' => $config['success_redirect'] ?? null,
            ];
        }

        $captchaProvider = $this->captchaProvider($config);

        if ($captchaProvider !== 'none') {
            $token = (string) ($_POST[$this->captchaResponseField($captchaProvider)] ?? '');
            $verified = $captchaProvider === 'turnstile'
                ? $this->turnstile->verify($token, $this->clientIp())
                : ($this->captchaVerifier?->verify($captchaProvider, $token, $this->clientIp()) ?? false);

            if (!$verified) {
                return [
                    'status' => 422,
                    'body' => ['success' => false, 'message' => 'CAPTCHA validation failed.'],
                ];
            }
        }

        $submissionId = $this->repository->create(
            $formId,
            $fields,
            $ipHash,
            $this->deferMail ? 'pending_mail' : 'received'
        );

        if (!$this->deferMail) {
            try {
                $this->mailService->send(
                    (string) $config['recipient'],
                    (string) ($config['subject'] ?? 'New form submission'),
                    SubmissionPayloadFormatter::displayFields($fields)
                );

                $this->repository->markSent($submissionId);
            } catch (Throwable $exception) {
                $this->repository->markFailed($submissionId, $exception->getMessage());

                throw new RuntimeException('Unable to send the submission email.', 0, $exception);
            }
        }

        try {
            $overrides = is_array($config['notification_overrides'] ?? null)
                ? $config['notification_overrides']
                : [];
            $this->webhookNotifier?->notify($formId, $fields, (array) $config['delivery_channels'], $overrides);
        } catch (Throwable) {
        }

        return [
            'status' => 200,
            'body' => [
                'success' => true,
                'message' => $this->deferMail ? 'Submission accepted.' : 'Submission sent successfully.',
            ],
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

    private function assertApiKey(string $formId, bool $required): void
    {
        $expectedKey = $this->apiKeys->get($formId);

        if ($expectedKey === null) {
            if ($required) {
                throw new InvalidArgumentException('API key is required.');
            }

            return;
        }

        $providedKey = (string) ($_POST['_key'] ?? '');

        if (!hash_equals($expectedKey, $providedKey)) {
            throw new InvalidArgumentException('Invalid API key.');
        }
    }

    private function captchaProvider(array $config): string
    {
        $provider = (string) ($config['captcha_provider'] ?? '');

        return in_array($provider, ['turnstile', 'hcaptcha', 'recaptcha', 'friendlycaptcha'], true)
            ? $provider
            : 'none';
    }

    private function captchaResponseField(string $provider): string
    {
        return match ($provider) {
            'turnstile' => 'cf-turnstile-response',
            'hcaptcha' => 'h-captcha-response',
            'recaptcha' => 'g-recaptcha-response',
            'friendlycaptcha' => 'frc-captcha-response',
            default => '',
        };
    }

    private function extractFields(array $input): array
    {
        $result = [];

        foreach ($input as $field => $value) {
            $field = (string) $field;

            if ($this->isSystemField($field)) {
                continue;
            }

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

    /** @return array<string, mixed> */
    private function extractUploadedFiles(array $files, array $policy): array
    {
        if ($files === [] || $this->uploadDirectory === '') {
            return [];
        }

        if (!is_dir($this->uploadDirectory)) {
            mkdir($this->uploadDirectory, 0775, true);
        }

        $stored = [];
        $policy = array_merge([
            'max_file_size_mb' => 10,
            'max_files' => 3,
            'allowed_extensions' => [],
        ], $policy);

        $entries = $this->uploadedFileEntries($files);
        $acceptedEntries = [];

        foreach ($entries as $field => $file) {
            if ($file['error'] === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            $acceptedEntries[$field] = $file;
        }

        if (count($acceptedEntries) > (int) $policy['max_files']) {
            throw new InvalidArgumentException('Too many files were uploaded.');
        }

        foreach ($acceptedEntries as $field => $file) {
            $this->validateUploadedFile($field, $file, $policy);
            $this->storeUploadedFile($field, $file, $stored);
        }

        return $stored;
    }

    /** @return array<string, array{name: string, tmp_name: string, error: int, size: int}> */
    private function uploadedFileEntries(array $files): array
    {
        $entries = [];

        foreach ($files as $field => $file) {
            if (!is_array($file) || !isset($file['error'])) {
                continue;
            }

            if (is_array($file['error'])) {
                foreach ($file['error'] as $index => $error) {
                    $entries[(string) $field . '_' . (int) $index] = [
                        'name' => (string) ($file['name'][$index] ?? ''),
                        'tmp_name' => (string) ($file['tmp_name'][$index] ?? ''),
                        'error' => (int) $error,
                        'size' => (int) ($file['size'][$index] ?? 0),
                    ];
                }

                continue;
            }

            $entries[(string) $field] = [
                'name' => (string) ($file['name'] ?? ''),
                'tmp_name' => (string) ($file['tmp_name'] ?? ''),
                'error' => (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE),
                'size' => (int) ($file['size'] ?? 0),
            ];
        }

        return $entries;
    }

    /** @param array{name: string, tmp_name: string, error: int, size: int} $file @param array<string, mixed> $policy */
    private function validateUploadedFile(string $field, array $file, array $policy): void
    {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException(sprintf('Upload "%s" failed.', $field));
        }

        if ($file['size'] > (int) $policy['max_file_size_mb'] * 1024 * 1024) {
            throw new InvalidArgumentException(sprintf('Upload "%s" is too large.', $field));
        }

        $extension = strtolower((string) pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowedExtensions = $policy['allowed_extensions'];
        $dangerousExtensions = [
            'bat',
            'cmd',
            'com',
            'exe',
            'js',
            'msi',
            'phtml',
            'php',
            'phar',
            'ps1',
            'sh',
            'vbs',
        ];

        if (in_array($extension, $dangerousExtensions, true)) {
            throw new InvalidArgumentException(sprintf('Upload "%s" has an unsupported file type.', $field));
        }

        if (is_array($allowedExtensions) && $allowedExtensions !== [] && !in_array($extension, $allowedExtensions, true)) {
            throw new InvalidArgumentException(sprintf('Upload "%s" has an unsupported file type.', $field));
        }

        $this->validateUploadedMimeType($field, $file, $extension);
    }

    /** @param array{name: string, tmp_name: string, error: int, size: int} $file */
    private function validateUploadedMimeType(string $field, array $file, string $extension): void
    {
        if (!is_file($file['tmp_name']) || !function_exists('finfo_open')) {
            return;
        }

        $expected = [
            'csv' => ['text/csv', 'text/plain'],
            'gif' => ['image/gif'],
            'jpg' => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'json' => ['application/json', 'text/plain'],
            'pdf' => ['application/pdf'],
            'png' => ['image/png'],
            'txt' => ['text/plain'],
            'webp' => ['image/webp'],
        ][$extension] ?? null;

        if ($expected === null) {
            return;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);

        if ($finfo === false) {
            return;
        }

        $mimeType = finfo_file($finfo, $file['tmp_name']);

        if (!is_string($mimeType) || !in_array($mimeType, $expected, true)) {
            throw new InvalidArgumentException(sprintf('Upload "%s" has an unsupported MIME type.', $field));
        }
    }

    /** @param array{name: string, tmp_name: string, error: int, size: int} $file @param array<string, mixed> $stored */
    private function storeUploadedFile(string $field, array $file, array &$stored): void
    {
        $safeName = preg_replace('/[^A-Za-z0-9._-]+/', '-', basename($file['name'])) ?: 'upload.bin';
        $storedName = gmdate('YmdHis') . '-' . bin2hex(random_bytes(6)) . '-' . $safeName;
        $target = rtrim($this->uploadDirectory, '/') . '/' . $storedName;
        $moved = is_uploaded_file($file['tmp_name'])
            ? move_uploaded_file($file['tmp_name'], $target)
            : rename($file['tmp_name'], $target);

        if (!$moved) {
            throw new InvalidArgumentException(sprintf('Upload "%s" could not be stored.', $field));
        }

        $stored[$field] = [
            'type' => 'upload',
            'original_name' => $file['name'],
            'stored_name' => $storedName,
            'size_bytes' => $file['size'],
            'mime_type' => $this->detectMimeType($target),
        ];
    }

    private function detectMimeType(string $path): string
    {
        if (!is_file($path) || !function_exists('finfo_open')) {
            return 'application/octet-stream';
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);

        if ($finfo === false) {
            return 'application/octet-stream';
        }

        $mimeType = finfo_file($finfo, $path);

        return is_string($mimeType) && $mimeType !== '' ? $mimeType : 'application/octet-stream';
    }

    private function isSystemField(string $field): bool
    {
        return in_array($field, [
            '_key',
            '_website',
            'cf-turnstile-response',
            'h-captcha-response',
            'g-recaptcha-response',
            'frc-captcha-response',
            'csrf_token',
        ], true);
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
        if ($this->clientIp !== null && $this->clientIp !== '') {
            return $this->clientIp;
        }

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
