<?php

declare(strict_types=1);

namespace formflow\Admin;

use formflow\AdminAuth;
use formflow\AuditLogRepositoryInterface;
use formflow\Clock;
use formflow\MailSenderInterface;
use formflow\SubmissionRepositoryInterface;
use formflow\Totp;
use InvalidArgumentException;
use Throwable;

final class AdminSettingsController
{
    public function __construct(
        private readonly AdminSettingsService $settingsService,
        private readonly SubmissionRepositoryInterface $submissions,
        private readonly ?MailSenderInterface $mailSender,
        private readonly int $formCount,
        private readonly string $root,
        private readonly ?AuditLogRepositoryInterface $auditLog,
        private readonly AdminAuth $auth,
        private readonly AdminViewRenderer $renderer
    ) {
    }

    /** @return array<string, mixed> */
    public function settings(): array
    {
        $tab = $this->settingsTab((string) ($_POST['tab'] ?? $_GET['tab'] ?? 'general'));

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!$this->verifyCsrfToken()) {
                return $this->htmlResponse(419, '<h1>Invalid CSRF token.</h1>');
            }

            $action = (string) ($_POST['action'] ?? 'save');

            if ($action === 'cleanup') {
                $days = max(1, (int) ($_POST['retention_days'] ?? $this->currentSettings()['retention_days'] ?? 180));
                $deleted = $this->submissions->deleteOlderThan($days);
                $this->recordAudit('retention.cleanup', 'Deleted ' . $deleted . ' submissions older than ' . $days . ' days.');

                return $this->htmlResponse(200, $this->renderSettings(null, array_merge($_POST, ['tab' => $tab === 'general' ? 'maintenance' : $tab]), false, 'Deleted ' . $deleted . ' old submissions.'));
            }

            if ($action === 'generate_recovery') {
                $token = bin2hex(random_bytes(24));
                $expiresAt = Clock::relativeIso(3600);
                $this->settingsService->writeEnvFile([
                    'RECOVERY_TOKEN_HASH' => password_hash($token, PASSWORD_DEFAULT),
                    'RECOVERY_TOKEN_EXPIRES_AT' => $expiresAt,
                ]);
                $this->recordAudit('settings.recovery_token', 'Generated a recovery token.');

                return $this->htmlResponse(200, $this->renderSettings(
                    null,
                    array_merge($_POST, ['tab' => $tab === 'general' ? 'admin' : $tab]),
                    false,
                    'Recovery token generated. It expires at ' . $expiresAt . '.',
                    $token,
                    $expiresAt
                ));
            }

            if ($action === 'generate_totp') {
                $secret = Totp::generateSecret();
                $this->settingsService->writeEnvFile(['ADMIN_TOTP_SECRET' => $secret]);
                $this->recordAudit('settings.totp', 'Generated bootstrap TOTP secret.');

                return $this->htmlResponse(200, $this->renderSettings(null, array_merge($_POST, ['admin_totp_secret' => $secret, 'tab' => $tab === 'general' ? 'admin' : $tab]), false, 'TOTP secret generated.'));
            }

            if ($action === 'test_email') {
                $message = $this->sendTestEmail((string) ($_POST['test_email_to'] ?? ''));

                if (str_starts_with($message, 'Unable')) {
                    return $this->htmlResponse(422, $this->renderSettings($message, array_merge($_POST, ['tab' => $tab === 'general' ? 'delivery' : $tab]), false));
                }

                return $this->htmlResponse(200, $this->renderSettings(null, array_merge($_POST, ['tab' => $tab === 'general' ? 'delivery' : $tab]), false, $message));
            }

            try {
                $settings = $this->settingsService->settingsFromInput(array_merge($this->currentSettings(), $_POST));
                $this->settingsService->writeSettings($settings);
                $this->recordAudit('settings.update', 'Updated global settings.');
            } catch (InvalidArgumentException $exception) {
                return $this->htmlResponse(422, $this->renderSettings($exception->getMessage(), $_POST, false));
            }

            return ['status' => 302, 'body' => '', 'redirect' => '/admin/settings?tab=' . rawurlencode($tab) . '&saved=1'];
        }

        return $this->htmlResponse(200, $this->renderSettings(null, [], ($_GET['saved'] ?? null) === '1'));
    }

    /** @return array<string, mixed> */
    public function integrations(): array
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!$this->verifyCsrfToken()) {
                return $this->htmlResponse(419, '<h1>Invalid CSRF token.</h1>');
            }

            try {
                $this->settingsService->writeEnvFile($this->settingsService->integrationSettingsFromInput($_POST));
                $this->recordAudit('integrations.update', 'Updated notification integrations.');
            } catch (InvalidArgumentException $exception) {
                return $this->htmlResponse(422, $this->renderIntegrations($exception->getMessage(), $_POST, false));
            }

            return ['status' => 302, 'body' => '', 'redirect' => '/admin/integrations?saved=1'];
        }

        return $this->htmlResponse(200, $this->renderIntegrations(null, [], ($_GET['saved'] ?? null) === '1'));
    }

    /** @param array<string, mixed> $values */
    private function renderSettings(
        ?string $error,
        array $values,
        bool $saved,
        ?string $notice = null,
        ?string $recoveryToken = null,
        ?string $recoveryTokenExpiresAt = null
    ): string {
        $settings = $values !== [] ? array_merge($this->currentSettings(), $values) : $this->currentSettings();
        $activeTab = $this->settingsTab((string) ($values['tab'] ?? $_GET['tab'] ?? 'general'));
        $totpSecret = trim((string) ($settings['admin_totp_secret'] ?? ''));
        $totpUri = $totpSecret !== ''
            ? Totp::provisioningUri($totpSecret, (string) ($settings['admin_username'] ?? 'admin'))
            : '';

        return $this->renderer->render('settings', [
            'error' => $error,
            'saved' => $saved,
            'notice' => $notice,
            'settings' => $settings,
            'activeTab' => $activeTab,
            'totpQrSvg' => $totpUri !== '' ? Totp::qrSvg($totpUri) : null,
            'totpProvisioningUri' => $totpUri,
            'recoveryToken' => $recoveryToken,
            'recoveryTokenExpiresAt' => $recoveryTokenExpiresAt,
            'setupStatus' => $this->setupStatus(),
            'csrfToken' => $_SESSION['csrf_token'],
        ], 'Settings');
    }

    /** @param array<string, mixed> $values */
    private function renderIntegrations(?string $error, array $values, bool $saved): string
    {
        return $this->renderer->render('integrations', [
            'error' => $error,
            'saved' => $saved,
            'settings' => $values !== [] ? array_merge($this->currentSettings(), $values) : $this->currentSettings(),
            'csrfToken' => $_SESSION['csrf_token'],
        ], 'Integrations');
    }

    /** @return 'general'|'delivery'|'protection'|'admin'|'maintenance' */
    private function settingsTab(string $tab): string
    {
        return in_array($tab, ['general', 'delivery', 'protection', 'admin', 'maintenance'], true)
            ? $tab
            : 'general';
    }

    /** @return array<string, mixed> */
    private function currentSettings(): array
    {
        return $this->settingsService->currentSettings();
    }

    private function sendTestEmail(string $recipient): string
    {
        if ($this->mailSender === null) {
            return 'Unable to send test email: mail service is not available.';
        }

        $recipient = trim($recipient);

        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            return 'Unable to send test email: enter a valid recipient.';
        }

        try {
            $this->mailSender->send($recipient, 'formflow test email', [
                'message' => 'SMTP settings are working.',
                'sent_at' => Clock::nowIso(),
            ]);
            $this->recordAudit('settings.test_email', 'Sent test email to ' . $recipient . '.');

            return 'Test email sent to ' . $recipient . '.';
        } catch (Throwable $exception) {
            return 'Unable to send test email: ' . $exception->getMessage();
        }
    }

    /** @return array{mail: string, captcha: string, storage: string, forms: string} */
    private function setupStatus(): array
    {
        $settings = $this->settingsService->snapshot();
        $databasePath = $settings->databasePath();
        $databaseDirectory = dirname(str_starts_with($databasePath, '/') ? $databasePath : $this->root . '/' . $databasePath);
        $mailReady = $settings->string('mail_from') !== ''
            && ($settings->string('mailer_dsn') !== '' || $settings->string('smtp_host') !== '');
        $captchaReady = (
            $settings->string('turnstile_secret') !== '' && $settings->string('turnstile_site_key') !== ''
        ) || (
            $settings->string('hcaptcha_secret') !== '' && $settings->string('hcaptcha_site_key') !== ''
        ) || (
            $settings->string('recaptcha_secret') !== '' && $settings->string('recaptcha_site_key') !== ''
        ) || (
            $settings->string('friendly_captcha_api_key') !== '' && $settings->string('friendly_captcha_site_key') !== ''
        );

        return [
            'mail' => $mailReady ? 'Configured' : 'Needs SMTP',
            'captcha' => $captchaReady ? 'Configured' : 'Optional',
            'storage' => is_dir($databaseDirectory) && is_writable($databaseDirectory) ? 'Writable' : 'Check storage',
            'forms' => (string) $this->formCount,
        ];
    }

    private function verifyCsrfToken(): bool
    {
        return hash_equals(
            (string) ($_SESSION['csrf_token'] ?? ''),
            (string) ($_POST['csrf_token'] ?? '')
        );
    }

    private function recordAudit(string $action, string $detail): void
    {
        $this->auditLog?->record($this->auth->username(), $action, $detail);
    }

    /** @return array{status: int, body: string, redirect: null} */
    private function htmlResponse(int $status, string $body): array
    {
        return ['status' => $status, 'body' => $body, 'redirect' => null];
    }
}
