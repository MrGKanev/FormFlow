<?php

declare(strict_types=1);

namespace formflow;

final class AnalyticsApiController
{
    public function __construct(
        private readonly SubmissionRepositoryInterface $submissions,
        private readonly string $token
    ) {
    }

    /** @param array<string, mixed> $query */
    public function handle(string $method, string $authorization, array $query): HttpResponse
    {
        $headers = ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store'];
        if ($this->token === '' || !hash_equals('Bearer ' . $this->token, $authorization)) {
            return new HttpResponse(401, ['error' => 'Unauthorized'], headers: $headers);
        }
        if ($method !== 'GET') {
            return new HttpResponse(405, ['error' => 'Method not allowed'], headers: $headers + ['Allow' => 'GET']);
        }
        $days = $query['days'] ?? '30';
        $formId = $query['form_id'] ?? '';
        if (!is_scalar($days) || !in_array((string) $days, ['7', '30', '90'], true) || !is_string($formId)) {
            return new HttpResponse(422, ['error' => 'Use days=7, 30 or 90 and a string form_id.'], headers: $headers);
        }
        $formId = trim($formId);
        return new HttpResponse(200, [
            'days' => (int) $days,
            'form_id' => $formId !== '' ? $formId : null,
            'analytics' => $this->submissions->analyticsOverview((int) $days, $formId !== '' ? $formId : null),
        ], headers: $headers);
    }
}
