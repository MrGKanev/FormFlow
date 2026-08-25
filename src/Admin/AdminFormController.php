<?php

declare(strict_types=1);

namespace formflow\Admin;

use formflow\AdminAuth;
use formflow\AuditLogRepositoryInterface;
use formflow\FormApiKeyRepositoryInterface;
use formflow\FormConfigRepositoryInterface;
use InvalidArgumentException;

final class AdminFormController
{
    private readonly AdminFormService $service;

    /** @param array<string, array<string, mixed>> $forms */
    public function __construct(
        private readonly array $forms,
        private readonly FormApiKeyRepositoryInterface $apiKeys,
        FormConfigRepositoryInterface $formRepository,
        private readonly AdminSettingsService $settingsService,
        private readonly ?AuditLogRepositoryInterface $auditLog,
        private readonly AdminAuth $auth,
        private readonly AdminViewRenderer $renderer
    ) {
        $this->service = new AdminFormService($forms, $formRepository, $apiKeys);
    }

    /** @return array<string, mixed> */
    public function index(): array
    {
        return $this->htmlResponse(200, $this->renderForms(null, []));
    }

    /** @return array<string, mixed> */
    public function create(): array
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!$this->verifyCsrfToken()) {
                return $this->htmlResponse(419, '<h1>Invalid CSRF token.</h1>');
            }

            try {
                $formId = $this->service->create($_POST);
                $this->recordAudit('form.create', 'Created form "' . $formId . '" with an API key.');
            } catch (InvalidArgumentException $exception) {
                return $this->htmlResponse(422, $this->renderCreator($exception->getMessage(), $_POST));
            }

            return ['status' => 302, 'body' => '', 'redirect' => '/admin/forms'];
        }

        return $this->htmlResponse(200, $this->renderCreator(null, []));
    }

    /** @return array<string, mixed> */
    public function edit(string $formId): array
    {
        $formId = rawurldecode($formId);

        if (!isset($this->forms[$formId])) {
            return $this->htmlResponse(404, '<h1>Form not found</h1>');
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!$this->verifyCsrfToken()) {
                return $this->htmlResponse(419, '<h1>Invalid CSRF token.</h1>');
            }

            try {
                $this->service->update($formId, $_POST);
                $this->recordAudit('form.update', 'Updated form "' . $formId . '".');
            } catch (InvalidArgumentException $exception) {
                return $this->htmlResponse(422, $this->renderEditor($formId, $exception->getMessage(), $_POST));
            }

            return ['status' => 302, 'body' => '', 'redirect' => '/admin/forms'];
        }

        return $this->htmlResponse(200, $this->renderEditor(
            $formId,
            null,
            $this->service->valuesFromConfig($formId, $this->forms[$formId])
        ));
    }

    /** @return array<string, mixed> */
    public function delete(string $formId): array
    {
        $formId = rawurldecode($formId);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return $this->htmlResponse(405, '<h1>Method not allowed</h1>');
        }

        if (!$this->verifyCsrfToken()) {
            return $this->htmlResponse(419, '<h1>Invalid CSRF token.</h1>');
        }

        $this->service->delete($formId);
        $this->recordAudit('form.delete', 'Deleted dynamic form "' . $formId . '".');

        return ['status' => 302, 'body' => '', 'redirect' => '/admin/forms'];
    }

    /** @param array<string, mixed> $values */
    private function renderForms(?string $error, array $values): string
    {
        $settings = $this->settingsService->currentSettings();

        return $this->renderer->render('forms', [
            'error' => $error,
            'forms' => $this->forms,
            'dynamicFormIds' => $this->service->dynamicFormIds(),
            'apiKeys' => $this->apiKeys->all(),
            'appUrl' => trim((string) ($settings['app_url'] ?? '')),
            'captchaSiteKeys' => [
                'turnstile' => (string) ($settings['turnstile_site_key'] ?? ''),
                'hcaptcha' => (string) ($settings['hcaptcha_site_key'] ?? ''),
                'recaptcha' => (string) ($settings['recaptcha_site_key'] ?? ''),
                'friendlycaptcha' => (string) ($settings['friendly_captcha_site_key'] ?? ''),
            ],
            'csrfToken' => $_SESSION['csrf_token'],
            'values' => $values,
        ], 'Forms');
    }

    /** @param array<string, mixed> $values */
    private function renderEditor(string $formId, ?string $error, array $values): string
    {
        return $this->renderer->render('form-edit', [
            'error' => $error,
            'formId' => $formId,
            'csrfToken' => $_SESSION['csrf_token'],
            'values' => $values,
            'integrationSettings' => $this->settingsService->currentSettings(),
        ], 'Edit form');
    }

    /** @param array<string, mixed> $values */
    private function renderCreator(?string $error, array $values): string
    {
        return $this->renderer->render('form-new', [
            'error' => $error,
            'csrfToken' => $_SESSION['csrf_token'],
            'values' => $values,
            'integrationSettings' => $this->settingsService->currentSettings(),
        ], 'New form');
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
