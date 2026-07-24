<?php

declare(strict_types=1);

namespace formflow\Tests;

use formflow\SqliteFormApiKeyRepository;
use PHPUnit\Framework\TestCase;

final class SqliteFormApiKeyRepositoryTest extends TestCase
{
    public function testGetReturnsNullBeforeGeneration(): void
    {
        $repository = new SqliteFormApiKeyRepository(':memory:');

        $this->assertNull($repository->get('contact'));
    }

    public function testRegenerateReturnsAndPersistsKey(): void
    {
        $repository = new SqliteFormApiKeyRepository(':memory:');

        $key = $repository->regenerate('contact');

        $this->assertNotSame('', $key);
        $this->assertSame($key, $repository->get('contact'));
    }

    public function testRegenerateAgainReplacesTheKey(): void
    {
        $repository = new SqliteFormApiKeyRepository(':memory:');

        $firstKey = $repository->regenerate('contact');
        $secondKey = $repository->regenerate('contact');

        $this->assertNotSame($firstKey, $secondKey);
        $this->assertSame($secondKey, $repository->get('contact'));
    }

    public function testRegenerateDoesNotAffectOtherForms(): void
    {
        $repository = new SqliteFormApiKeyRepository(':memory:');

        $repository->regenerate('contact');

        $this->assertNull($repository->get('support'));
    }

    public function testAllReturnsGeneratedKeysKeyedByFormId(): void
    {
        $repository = new SqliteFormApiKeyRepository(':memory:');

        $repository->regenerate('contact');
        $repository->regenerate('support');

        $all = $repository->all();

        $this->assertCount(2, $all);
        $this->assertArrayHasKey('contact', $all);
        $this->assertArrayHasKey('support', $all);
        $this->assertSame($repository->get('contact'), $all['contact']['api_key']);
    }
}
