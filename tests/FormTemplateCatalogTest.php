<?php

declare(strict_types=1);

namespace formflow\Tests;

use formflow\FormTemplateCatalog;
use PHPUnit\Framework\TestCase;

final class FormTemplateCatalogTest extends TestCase
{
    public function testTemplateFillsMissingDefaultsWithoutOverwritingUserValues(): void
    {
        $result = FormTemplateCatalog::apply([
            'template' => 'support',
            'subject' => 'My support subject',
            'daily_limit' => '',
        ]);

        $this->assertSame('My support subject', $result['subject']);
        $this->assertSame('500', $result['daily_limit']);
        $this->assertSame('pdf' . PHP_EOL . 'jpg' . PHP_EOL . 'jpeg' . PHP_EOL . 'png' . PHP_EOL . 'txt', $result['upload_allowed_extensions']);
        $this->assertSame('1', $result['auto_reply_enabled']);
    }

    public function testUnknownTemplateLeavesInputUnchanged(): void
    {
        $input = ['template' => 'unknown', 'subject' => 'Custom'];

        $this->assertSame($input, FormTemplateCatalog::apply($input));
    }
}
