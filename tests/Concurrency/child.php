<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use formflow\MailDeliveryWorker;
use formflow\MailSenderInterface;
use formflow\SqliteRateLimiter;
use formflow\SqliteSubmissionRepository;
use formflow\SqliteWebhookDeliveryRepository;
use formflow\WebhookDeliveryWorker;
use formflow\WebhookTransportInterface;

[$script, $mode, $database, $output] = $argv;
$repository = str_starts_with($mode, 'webhook') ? new SqliteWebhookDeliveryRepository($database) : (in_array($mode, ['rate', 'locked-rate'], true) ? new SqliteRateLimiter($database) : new SqliteSubmissionRepository($database));
echo "ready\n";
fflush(STDOUT);
if (trim((string) fgets(STDIN)) !== 'go') {
    exit(2);
}
if ($mode === 'webhook-crash') {
    echo json_encode($repository->claim((int) $output)) . "\n";
    exit(0);
}
if ($mode === 'webhook') {
    $transport = new class($output) implements WebhookTransportInterface {
        public function __construct(private string $path) {}
        public function postJson(string $url, array $payload): ?string
        {
            file_put_contents($this->path, "sent\n", FILE_APPEND | LOCK_EX);
            return null;
        }
    };
    echo json_encode((new WebhookDeliveryWorker($repository, $transport))->process()) . "\n";
    exit(0);
}
if ($mode === 'crash') {
    echo json_encode($repository->claim((int) $output, 'pending_mail')) . "\n";
    exit(0); // Abrupt process exit without completing or releasing the claim.
}
if ($mode === 'rate' || $mode === 'locked-rate') {
    if ($mode === 'locked-rate') { echo "attempting\n"; fflush(STDOUT); }
    echo json_encode($repository->consume('contact', 'ip', 10, 3, 100)) . "\n";
    exit(0);
}
$mail = new class($output) implements MailSenderInterface {
    public function __construct(private string $path) {}
    public function send(string $recipient, string $subject, array $fields): void
    {
        file_put_contents($this->path, "sent\n", FILE_APPEND | LOCK_EX);
    }
};
echo json_encode((new MailDeliveryWorker(['contact' => ['recipient' => 'owner@example.com']], $mail, $repository))->process()) . "\n";
