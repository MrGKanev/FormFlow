<?php

declare(strict_types=1);

namespace formflow\Tests;

use formflow\SqliteAdminUserRepository;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SqliteAdminUserRepositoryTest extends TestCase
{
    public function testCreateAndFindByUsername(): void
    {
        $repository = new SqliteAdminUserRepository(':memory:');

        $repository->create('ada', 'hashed-password', 'JBSWY3DPEHPK3PXP');

        $user = $repository->findByUsername('ada');

        $this->assertNotNull($user);
        $this->assertSame('ada', $user['username']);
        $this->assertSame('hashed-password', $user['password_hash']);
        $this->assertSame('JBSWY3DPEHPK3PXP', $user['totp_secret']);
    }

    public function testCreateWithoutTotpSecretStoresNull(): void
    {
        $repository = new SqliteAdminUserRepository(':memory:');

        $repository->create('ada', 'hashed-password');

        $this->assertNull($repository->findByUsername('ada')['totp_secret']);
    }

    public function testFindByUsernameReturnsNullWhenMissing(): void
    {
        $repository = new SqliteAdminUserRepository(':memory:');

        $this->assertNull($repository->findByUsername('missing'));
    }

    public function testDuplicateUsernameIsRejected(): void
    {
        $repository = new SqliteAdminUserRepository(':memory:');
        $repository->create('ada', 'hashed-password');

        $this->expectException(InvalidArgumentException::class);

        $repository->create('ada', 'another-hash');
    }

    public function testListReturnsUsersOrderedByUsername(): void
    {
        $repository = new SqliteAdminUserRepository(':memory:');
        $repository->create('carol', 'hash');
        $repository->create('ada', 'hash');
        $repository->create('bob', 'hash');

        $usernames = array_column($repository->list(), 'username');

        $this->assertSame(['ada', 'bob', 'carol'], $usernames);
    }

    public function testListDoesNotExposePasswordHashOrTotpSecret(): void
    {
        $repository = new SqliteAdminUserRepository(':memory:');
        $repository->create('ada', 'hashed-password', 'JBSWY3DPEHPK3PXP');

        $entry = $repository->list()[0];

        $this->assertArrayNotHasKey('password_hash', $entry);
        $this->assertArrayNotHasKey('totp_secret', $entry);
    }

    public function testDeleteRemovesUser(): void
    {
        $repository = new SqliteAdminUserRepository(':memory:');
        $repository->create('ada', 'hashed-password');
        $id = (int) $repository->findByUsername('ada')['id'];

        $repository->delete($id);

        $this->assertNull($repository->findByUsername('ada'));
    }
}
