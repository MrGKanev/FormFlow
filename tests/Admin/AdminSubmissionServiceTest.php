<?php

declare(strict_types=1);

namespace formflow\Tests\Admin;

use formflow\Admin\AdminSubmissionService;
use formflow\SqliteSubmissionRepository;
use PHPUnit\Framework\TestCase;

final class AdminSubmissionServiceTest extends TestCase
{
    public function testSelectedIdsRejectsValuesThatCouldTargetUnselectedSubmissions(): void
    {
        $service = new AdminSubmissionService(new SqliteSubmissionRepository(':memory:'), null, []);

        $this->assertSame([1, 2], $service->selectedIds([
            '1', 2, '2', '3junk', [4], true, 5.5, '1e2', 0, -1,
            '999999999999999999999999999999999999',
        ]));
    }
}
