<?php

declare(strict_types=1);

namespace formflow\Tests;

use formflow\MailService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

final class MailServiceTest extends TestCase
{
    public function testSendBuildsEscapedEmailAndSetsValidReplyTo(): void
    {
        $service = new MailService('null://null', 'forms@example.com', 'FormFlow');
        $mailer = $this->createMock(MailerInterface::class);
        $this->replaceMailer($service, $mailer);

        $mailer->expects($this->once())
            ->method('send')
            ->with($this->callback(function (Email $email): bool {
                $this->assertSame('forms@example.com', $email->getFrom()[0]->getAddress());
                $this->assertSame('FormFlow', $email->getFrom()[0]->getName());
                $this->assertSame('owner@example.com', $email->getTo()[0]->getAddress());
                $this->assertSame('New enquiry', $email->getSubject());
                $this->assertSame('ada@example.com', $email->getReplyTo()[0]->getAddress());
                $this->assertSame('Ada <Admin>', $email->getReplyTo()[0]->getName());
                $this->assertStringContainsString("Name:\nAda <Admin>", $email->getTextBody());
                $this->assertStringContainsString('Message:', $email->getTextBody());
                $this->assertStringContainsString('<th', $email->getHtmlBody());
                $this->assertStringContainsString('Ada &lt;Admin&gt;', $email->getHtmlBody());
                $this->assertStringContainsString('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;<br', $email->getHtmlBody());

                return true;
            }));

        $service->send('owner@example.com', 'New enquiry', [
            'name' => 'Ada <Admin>',
            'email' => 'ada@example.com',
            'message' => '<script>alert("x")</script>' . PHP_EOL . 'Second line',
        ]);
    }

    public function testSendDoesNotSetReplyToForInvalidEmailField(): void
    {
        $service = new MailService('null://null', 'forms@example.com', 'FormFlow');
        $mailer = $this->createMock(MailerInterface::class);
        $this->replaceMailer($service, $mailer);

        $mailer->expects($this->once())
            ->method('send')
            ->with($this->callback(function (Email $email): bool {
                $this->assertSame([], $email->getReplyTo());

                return true;
            }));

        $service->send('owner@example.com', 'New enquiry', ['email' => 'not-an-email']);
    }

    private function replaceMailer(MailService $service, MailerInterface $mailer): void
    {
        $property = new \ReflectionProperty($service, 'mailer');
        $property->setValue($service, $mailer);
    }
}
