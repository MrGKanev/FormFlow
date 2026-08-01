<?php

declare(strict_types=1);

namespace formflow\Admin;

use formflow\FormConfigValidator;
use InvalidArgumentException;

final class AdminConfigTransferService
{
    public function __construct(private readonly AdminSettingsService $settingsService)
    {
    }

    /**
     * @param array<string, mixed> $settings
     * @param array<string, array<string, mixed>> $forms
     * @param array<string, mixed> $security
     * @return array{settings: array<string, mixed>, forms: array<string, array<string, mixed>>, security: array<string, mixed>}
     */
    public function exportData(array $settings, array $forms, array $security): array
    {
        return [
            'settings' => $settings,
            'forms' => $forms,
            'security' => $security,
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $currentSettings
     * @param array<string, mixed> $currentSecurity
     * @return array{settings: array<string, mixed>|null, security: array{blocked_ips: list<string>, trusted_proxies: list<string>, trusted_ip_headers: list<string>}|null, forms: array<string, array<string, mixed>>}
     */
    public function prepareImport(array $data, array $currentSettings, array $currentSecurity): array
    {
        $settingsToWrite = null;
        $securityToWrite = null;
        $formsToWrite = [];

        if (isset($data['settings']) && is_array($data['settings'])) {
            $settingsToWrite = $this->settingsService->settingsFromInput(array_merge($currentSettings, $data['settings']));
        }

        if (isset($data['security']) && is_array($data['security'])) {
            $securityToWrite = $this->settingsService->securityFromConfig(array_merge($currentSecurity, $data['security']));
        }

        if (isset($data['forms']) && is_array($data['forms'])) {
            foreach ($data['forms'] as $formId => $config) {
                if (is_string($formId) && is_array($config)) {
                    $formsToWrite[$formId] = FormConfigValidator::normalize($formId, $config);
                    continue;
                }

                throw new InvalidArgumentException('Imported forms must be keyed by valid form IDs.');
            }
        }

        return [
            'settings' => $settingsToWrite,
            'security' => $securityToWrite,
            'forms' => $formsToWrite,
        ];
    }
}
