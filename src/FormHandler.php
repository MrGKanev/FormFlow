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
        private readonly string $uploadDirectory = ''
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

        try {
            $channels = array_key_exists('notification_channels', $config)
                ? (array) $config['notification_channels']
                : null;
            $this->webhookNotifier?->notify($formId, $fields, $channels);
        } catch (Throwable) {
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

    /** @return array<string, string> */
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

        foreach ($files as $field => $file) {
            if (!is_array($file) || !isset($file['error'])) {
                continue;
            }

            if (is_array($file['error'])) {
                foreach ($file['error'] as $index => $error) {
                    $entry = [
                        'name' => (string) ($file['name'][$index] ?? ''),
                        'tmp_name' => (string) ($file['tmp_name'][$index] ?? ''),
                        'error' => (int) $error,
                        'size' => (int) ($file['size'][$index] ?? 0),
                    ];
                    $this->storeUploadedFile((string) $field . '_' . (int) $index, $entry, $stored, $policy);
                }

                continue;
            }

            $this->storeUploadedFile((string) $field, [
                'name' => (string) ($file['name'] ?? ''),
                'tmp_name' => (string) ($file['tmp_name'] ?? ''),
                'error' => (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE),
                'size' => (int) ($file['size'] ?? 0),
            ], $stored, $policy);
        }

        return $stored;
    }

    /** @param array{name: string, tmp_name: string, error: int, size: int} $file @param array<string, string> $stored @param array<string, mixed> $policy */
    private function storeUploadedFile(string $field, array $file, array &$stored, array $policy): void
    {
        if ($file['error'] === UPLOAD_ERR_NO_FILE) {
            return;
        }

        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException(sprintf('Upload "%s" failed.', $field));
        }

        if (count($stored) >= (int) $policy['max_files']) {
            throw new InvalidArgumentException('Too many files were uploaded.');
        }

        if ($file['size'] > (int) $policy['max_file_size_mb'] * 1024 * 1024) {
            throw new InvalidArgumentException(sprintf('Upload "%s" is too large.', $field));
        }

        $extension = strtolower((string) pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowedExtensions = $policy['allowed_extensions'];

        if (is_array($allowedExtensions) && $allowedExtensions !== [] && !in_array($extension, $allowedExtensions, true)) {
            throw new InvalidArgumentException(sprintf('Upload "%s" has an unsupported file type.', $field));
        }

        $safeName = preg_replace('/[^A-Za-z0-9._-]+/', '-', basename($file['name'])) ?: 'upload.bin';
        $target = rtrim($this->uploadDirectory, '/') . '/' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(6)) . '-' . $safeName;
        $moved = is_uploaded_file($file['tmp_name'])
            ? move_uploaded_file($file['tmp_name'], $target)
            : rename($file['tmp_name'], $target);

        if (!$moved) {
            throw new InvalidArgumentException(sprintf('Upload "%s" could not be stored.', $field));
        }

        $stored[$field] = $file['name'] . ' (' . $target . ')';
    }

    private function isSystemField(string $field): bool
    {
        return in_array($field, ['_key', '_website', 'cf-turnstile-response', 'csrf_token'], true);
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
