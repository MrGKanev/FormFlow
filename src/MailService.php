<?php

declare(strict_types=1);

namespace formflow;

use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

final class MailService implements MailSenderInterface
{
    private MailerInterface $mailer;

    public function __construct(
        string $mailerDsn,
        private readonly string $fromEmail,
        private readonly string $fromName
    ) {
        $this->mailer = new Mailer(Transport::fromDsn($mailerDsn));
    }

    public function send(string $recipient, string $subject, array $fields): void
    {
        $email = (new Email())
            ->from(new Address($this->fromEmail, $this->fromName))
            ->to($recipient)
            ->subject($subject)
            ->text($this->buildTextBody($fields))
            ->html($this->buildHtmlBody($fields));

        if (
            isset($fields['email'])
            && filter_var($fields['email'], FILTER_VALIDATE_EMAIL)
        ) {
            $replyName = isset($fields['name']) ? (string) $fields['name'] : '';

            $email->replyTo(new Address((string) $fields['email'], $replyName));
        }

        $this->mailer->send($email);
    }

    private function buildTextBody(array $fields): string
    {
        $lines = ['New formflow submission', ''];

        foreach ($fields as $name => $value) {
            $label = ucwords(str_replace('_', ' ', (string) $name));

            $lines[] = $label . ':';
            $lines[] = (string) $value;
            $lines[] = '';
        }

        return implode(PHP_EOL, $lines);
    }

    private function buildHtmlBody(array $fields): string
    {
        $rows = '';

        foreach ($fields as $name => $value) {
            $label = htmlspecialchars(
                ucwords(str_replace('_', ' ', (string) $name)),
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8'
            );

            $safeValue = nl2br(
                htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            );

            $rows .= sprintf(
                '<tr>
                    <th style="text-align:left;vertical-align:top;padding:8px;border-bottom:1px solid #ddd;">%s</th>
                    <td style="padding:8px;border-bottom:1px solid #ddd;">%s</td>
                </tr>',
                $label,
                $safeValue
            );
        }

        return sprintf(
            '<h2>New formflow submission</h2><table style="border-collapse:collapse;width:100%%;">%s</table>',
            $rows
        );
    }
}
