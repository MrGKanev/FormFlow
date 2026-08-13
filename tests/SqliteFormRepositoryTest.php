<?php

declare(strict_types=1);

namespace formflow\Tests;

use formflow\SqliteFormRepository;
use PHPUnit\Framework\TestCase;

final class SqliteFormRepositoryTest extends TestCase
{
    public function testAllReturnsEmptyArrayBeforeCreate(): void
    {
        $repository = new SqliteFormRepository(':memory:');

        $this->assertSame([], $repository->all());
    }

    public function testCreatePersistsFormConfig(): void
    {
        $repository = new SqliteFormRepository(':memory:');

        $repository->create('newsletter', [
            'recipient' => 'news@example.com',
            'allowed_origins' => ['https://example.com'],
            'subject' => 'New signup',
            'turnstile' => true,
        ]);

        $forms = $repository->all();

        $this->assertArrayHasKey('newsletter', $forms);
        $this->assertSame('news@example.com', $forms['newsletter']['recipient']);
        $this->assertTrue($repository->exists('newsletter'));
        $this->assertFalse($repository->exists('contact'));
    }

    public function testUpdateChangesExistingFormAndUpsertsMissingForm(): void
    {
        $repository = new SqliteFormRepository(':memory:');
        $repository->create('contact', ['recipient' => 'old@example.com']);

        $repository->update('contact', ['recipient' => 'new@example.com', 'turnstile' => false]);
        $repository->update('support', ['recipient' => 'support@example.com']);

        $forms = $repository->all();
        $this->assertSame('new@example.com', $forms['contact']['recipient']);
        $this->assertFalse($forms['contact']['turnstile']);
        $this->assertSame('support@example.com', $forms['support']['recipient']);
    }

    public function testDeleteRemovesOnlyRequestedForm(): void
    {
        $repository = new SqliteFormRepository(':memory:');
        $repository->create('contact', ['recipient' => 'contact@example.com']);
        $repository->create('support', ['recipient' => 'support@example.com']);

        $repository->delete('contact');

        $this->assertFalse($repository->exists('contact'));
        $this->assertTrue($repository->exists('support'));
        $this->assertSame(['support' => ['recipient' => 'support@example.com']], $repository->all());
    }
}
