<?php

declare(strict_types=1);

namespace formflow\Tests\Admin;

use PHPUnit\Framework\TestCase;

final class MailRetryViewsTest extends TestCase
{
    private function render(string $view, array $data): string
    {
        extract($data, EXTR_SKIP);
        ob_start();
        try {
            require dirname(__DIR__, 2) . '/src/Admin/views/' . $view . '.php';
            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }

    private function submission(): array
    {
        return ['id' => 1, 'form_id' => 'contact', 'status' => 'failed', 'payload' => '{}',
            'created_at' => '2026-10-13', 'sent_at' => null, 'reviewed_at' => null,
            'error_message' => null, 'mail_attempts' => 5, 'mail_retryable' => 0,
            'mail_next_attempt_at' => null];
    }

    public function testSubmissionDisplaysExhaustedRetryBudgetAndEscapesNextRetry(): void
    {
        $row = $this->submission();
        $html = $this->render('submission', ['submission' => $row]);
        $this->assertStringContainsString('Email attempts</th><td>5', $html);
        $this->assertStringContainsString('Stopped — manual resend available', $html);
        $row['mail_retryable'] = 1;
        $row['mail_next_attempt_at'] = '<script>alert(1)</script>';
        $html = $this->render('submission', ['submission' => $row]);
        $this->assertStringContainsString('Next retry: &lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
    }

    public function testDeliveryDisplaysExhaustedBudgetAndEscapesRetryTimestamp(): void
    {
        $rows = [$this->submission(), array_replace($this->submission(), [
            'id' => 2, 'mail_retryable' => 1, 'mail_next_attempt_at' => '<script>alert(1)</script>',
        ])];
        $html = $this->render('delivery', ['entries' => $rows, 'webhookEntries' => [],
            'total' => 2, 'perPage' => 50, 'page' => 1]);
        $this->assertStringContainsString('Stopped — manual resend available', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertLessThan(strpos($html, 'Email deliveries'), strpos($html, 'No integration deliveries yet.'));
    }
}
