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
}
