<?php

declare(strict_types=1);

namespace formflow\Tests;

use formflow\Clock;
use formflow\SqliteSubmissionRepository;
use PDO;
use PHPUnit\Framework\TestCase;

final class SqliteMailRetryRepositoryTest extends TestCase
{
    public function testDueBoundaryAndClaimRecheckScheduleAndReturnFreshAttempts(): void
    {
        $repository = new SqliteSubmissionRepository(':memory:');
        $id = $repository->create('contact', [], null, 'pending_mail');
        $this->assertSame(1, $repository->claimMail($id, 'pending_mail')['mail_attempts']);
        $repository->markMailFailed($id, 'SMTP down', 3600);
        $this->assertSame([], $repository->findDueMail());
        $this->assertNull($repository->claimMail($id, 'failed'));
        $this->pdo($repository)->prepare('UPDATE submissions SET mail_next_attempt_at = ? WHERE id = ?')
            ->execute([Clock::nowIso(), $id]);
        $staleCandidate = $repository->findDueMail()[0];
        $this->assertSame(1, $staleCandidate['mail_attempts']);
        $freshClaim = $repository->claimMail($id, 'failed');
        $this->assertSame(2, $freshClaim['mail_attempts']);
        $this->assertNull($freshClaim['mail_next_attempt_at']);
        $this->assertNull($repository->claimMail($id, 'failed'));
        $repository->markSent($id);
        $this->assertSame(0, $repository->find($id)['mail_retryable']);
        $this->assertSame([], $repository->findDueMail());
    }

    public function testAttemptCapAndForcedRetryRespectLiveLockAndSentStatus(): void
    {
        $repository = new SqliteSubmissionRepository(':memory:');
        $id = $repository->create('contact', [], null, 'pending_mail');
        $repository->claimMail($id, 'pending_mail', 1);
        $this->assertNull($repository->claimMail($id, 'pending_mail', 1, true));
        $repository->markMailFailed($id, 'SMTP down', 0);
        $this->assertNull($repository->claimMail($id, 'failed', 1));
        $this->assertSame([], $repository->findDueMail(100, true, 1));
        $this->assertSame(0, $repository->find($id)['mail_retryable']);
        $this->assertSame(1, $repository->claimMail($id, 'failed', 1, true)['mail_attempts']);
        $repository->markSent($id);
        $this->assertNull($repository->claimMail($id, 'sent', 5, true));
        $this->assertNull($repository->claimMail($id, 'failed', 5, true));
    }

    public function testTerminalFailureRequiresExplicitRetryEvenBelowAttemptCap(): void
    {
        $repository = new SqliteSubmissionRepository(':memory:');
        $id = $repository->create('contact', [], null, 'pending_mail');
        $repository->claimMail($id, 'pending_mail');
        $repository->markMailFailed($id, 'Invalid payload', null);
        $this->assertSame([], $repository->findDueMail());
        $this->assertNull($repository->claimMail($id, 'failed'));
        $this->assertSame(1, $repository->claimMail($id, 'failed', 5, true)['mail_attempts']);
    }

    public function testCrashedFinalAttemptBecomesTerminalAfterLeaseExpires(): void
    {
        $repository = new SqliteSubmissionRepository(':memory:');
        $id = $repository->create('contact', [], null, 'pending_mail');
        $repository->claimMail($id, 'pending_mail', 1);
        $repository->findDueMail(100, true, 1);
        $this->assertSame('pending_mail', $repository->find($id)['status']);
        $this->pdo($repository)->prepare('UPDATE submissions SET locked_at = ? WHERE id = ?')
            ->execute([Clock::relativeIso(-901), $id]);
        $this->assertSame([], $repository->findDueMail(100, true, 1));
        $row = $repository->find($id);
        $this->assertSame('failed', $row['status']);
        $this->assertSame(0, $row['mail_retryable']);
        $this->assertNull($row['locked_at']);
        $this->assertStringContainsString('retry limit', $row['error_message']);
        $this->assertNull($repository->claimMail($id, 'failed', 1));
    }

    public function testDeliberateResendOfSentSubmissionStartsNewRetryBudget(): void
    {
        $repository = new SqliteSubmissionRepository(':memory:');
        $id = $repository->create('contact', [], null, 'sent');
        $this->pdo($repository)->prepare('UPDATE submissions SET mail_attempts = 5 WHERE id = ?')->execute([$id]);

        $this->assertTrue($repository->claim($id, 'sent'));
        $row = $repository->find($id);
        $this->assertSame(1, $row['mail_attempts']);
        $this->assertSame(1, $row['mail_retryable']);
        $repository->markMailFailed($id, 'SMTP unavailable', 0);
        $this->assertCount(1, $repository->findDueMail());
        $this->assertSame(2, $repository->claimMail($id, 'failed')['mail_attempts']);
    }

    public function testPersistedAttemptsAreVisibleAcrossConnectionsAndRecheckedOnClaim(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'formflow-retry-');
        self::assertNotFalse($path);

        try {
            $first = new SqliteSubmissionRepository($path);
            $second = new SqliteSubmissionRepository($path);
            $id = $first->create('contact', [], null, 'pending_mail');
            $this->assertCount(1, $second->findDueMail());
            $this->assertSame(1, $first->claimMail($id, 'pending_mail', 1)['mail_attempts']);
            $this->assertNull($second->claimMail($id, 'pending_mail', 1));
            $first->markMailFailed($id, 'Unavailable', 0);
            $this->assertSame([], $second->findDueMail(100, true, 1));
            $this->assertSame(1, $second->find($id)['mail_attempts']);
            $reopened = new SqliteSubmissionRepository($path);
            $this->assertSame(1, $reopened->find($id)['mail_attempts']);
            $this->assertSame(0, $reopened->find($id)['mail_retryable']);
        } finally {
            unset($first, $second, $reopened);
            foreach ([$path, $path . '-wal', $path . '-shm'] as $file) {
                if (is_file($file)) { unlink($file); }
            }
        }
    }

    public function testForcedRetryIgnoresFutureScheduleAndResetsPersistedBudget(): void
    {
        $repository = new SqliteSubmissionRepository(':memory:');
        $id = $repository->create('contact', [], null, 'failed');
        $repository->markMailFailed($id, 'SMTP down', 3600);
        $this->pdo($repository)->prepare('UPDATE submissions SET mail_attempts = 4 WHERE id = ?')->execute([$id]);

        $this->assertNull($repository->claimMail($id, 'failed'));
        $row = $repository->claimMail($id, 'failed', 5, true);
        $this->assertSame(1, $row['mail_attempts']);
        $this->assertNull($row['mail_next_attempt_at']);
        $this->assertSame(1, $row['mail_retryable']);
    }

    public function testGenericFailureHonorsConfiguredAttemptCapAndCurrentBackoff(): void
    {
        $settings = ['MAIL_MAX_ATTEMPTS', 'MAIL_RETRY_BASE_DELAY_SECONDS', 'MAIL_RETRY_MAX_DELAY_SECONDS'];
        $original = [];
        foreach ($settings as $name) { $original[$name] = getenv($name); }

        try {
            putenv('MAIL_MAX_ATTEMPTS=1');
            putenv('MAIL_RETRY_BASE_DELAY_SECONDS=60');
            putenv('MAIL_RETRY_MAX_DELAY_SECONDS=3600');
            $repository = new SqliteSubmissionRepository(':memory:');
            $id = $repository->create('contact', [], null);
            $repository->markFailed($id, 'SMTP unavailable');
            $row = $repository->find($id);
            $this->assertSame(1, $row['mail_attempts']);
            $this->assertSame(0, $row['mail_retryable']);
            $this->assertNull($row['mail_next_attempt_at']);

            putenv('MAIL_MAX_ATTEMPTS=5');
            $this->pdo($repository)->prepare('UPDATE submissions SET mail_attempts = 3 WHERE id = ?')->execute([$id]);
            $before = time();
            $repository->markFailed($id, 'SMTP still unavailable');
            $row = $repository->find($id);
            $next = strtotime($row['mail_next_attempt_at']);
            $this->assertSame(3, $row['mail_attempts']);
            $this->assertSame(1, $row['mail_retryable']);
            $this->assertGreaterThanOrEqual($before + 120, $next);
            $this->assertLessThanOrEqual(time() + 240, $next);
        } finally {
            foreach ($original as $name => $value) {
                putenv($value === false ? $name : $name . '=' . $value);
            }
        }
    }

    private function pdo(SqliteSubmissionRepository $repository): PDO
    {
        return (new \ReflectionProperty($repository, 'pdo'))->getValue($repository);
    }
}
