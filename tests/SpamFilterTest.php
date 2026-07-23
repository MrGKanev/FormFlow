<?php

declare(strict_types=1);

namespace formflow\Tests;

use formflow\SpamFilter;
use PHPUnit\Framework\TestCase;

final class SpamFilterTest extends TestCase
{
    public function testMatchingPatternIsFlaggedAsSpam(): void
    {
        $filter = new SpamFilter(['viagra']);

        $this->assertTrue($filter->isSpam(['message' => 'Buy cheap Viagra online']));
    }

    public function testNoMatchIsNotSpam(): void
    {
        $filter = new SpamFilter(['viagra']);

        $this->assertFalse($filter->isSpam(['message' => 'Hello, I have a question.']));
    }

    public function testEmptyPatternListNeverFlagsSpam(): void
    {
        $filter = new SpamFilter([]);

        $this->assertFalse($filter->isSpam(['message' => 'Buy cheap viagra online']));
    }

    public function testMatchIsCaseInsensitive(): void
    {
        $filter = new SpamFilter(['VIAGRA']);

        $this->assertTrue($filter->isSpam(['message' => 'viagra deals']));
    }
}
