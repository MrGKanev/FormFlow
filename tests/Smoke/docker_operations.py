#!/usr/bin/env python3
"""Verify the production image with isolated volumes and no outbound mail."""

import argparse
import json
import subprocess
import time
import uuid


def run(arguments, expected=0):
    result = subprocess.run(arguments, text=True, capture_output=True, timeout=45)
    if result.returncode != expected:
        raise RuntimeError(
            f"{arguments[:5]}: expected exit {expected}, got {result.returncode}\n"
            f"{result.stdout}{result.stderr}"
        )
    return result.stdout


def require(condition, message):
    if not condition:
        raise RuntimeError(message)


def verify(image):
    suffix = uuid.uuid4().hex[:12]
    container = "formflow-smoke-" + suffix
    volumes = ["formflow-storage-" + suffix, "formflow-config-" + suffix]

    def php(code, *arguments):
        return run(["docker", "exec", container, "php", "-r", code, *arguments])

    def cli(*arguments, expected=0):
        return run(
            ["docker", "exec", container, "php", "bin/formflow", *arguments],
            expected,
        )

    def wait_ready():
        check = """
$context = stream_context_create(['http' => ['timeout' => 2]]);
$body = @file_get_contents('http://127.0.0.1/health?format=json', false, $context);
exit(str_contains((string) $body, '"status":"ok"') ? 0 : 1);
"""
        deadline = time.monotonic() + 20
        while time.monotonic() < deadline:
            result = subprocess.run(
                ["docker", "exec", container, "php", "-r", check],
                capture_output=True,
                timeout=5,
            )
            if result.returncode == 0:
                return
            time.sleep(0.1)
        raise RuntimeError("Container health check did not become ready")

    def api(token, status_code, healthy=None):
        php(r"""
$headers = '';
$handle = curl_init('http://127.0.0.1/api/delivery-status');
curl_setopt_array($handle, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 5,
    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $argv[1]],
    CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$headers): int {
        $headers .= $line;
        return strlen($line);
    },
]);
$body = curl_exec($handle);
$code = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
$response = json_decode((string) $body, true, flags: JSON_THROW_ON_ERROR);
$valid = $code === (int) $argv[2] && str_contains(strtolower($headers), 'cache-control: no-store');
if ($argv[3] !== 'none') {
    $valid = $valid && ($response['healthy'] ?? null) === ($argv[3] === 'true');
}
if (!$valid) {
    fwrite(STDERR, 'Unexpected API response: ' . $code . ' ' . $body);
}
exit($valid ? 0 : 1);
""", token, str(status_code), "none" if healthy is None else str(healthy).lower())

    try:
        run([
            "docker", "run", "-d", "--name", container,
            "--read-only", "--security-opt", "no-new-privileges:true",
            "--tmpfs", "/tmp", "--tmpfs", "/var/run/apache2",
            "--tmpfs", "/var/lock/apache2",
            "-v", volumes[0] + ":/var/www/html/storage",
            "-v", volumes[1] + ":/var/www/html/config", image,
        ])
        wait_ready()
        php(r"""
require 'vendor/autoload.php';
$environment = "APP_ENV=production\nMAILER_DSN=null://null\nMAIL_FROM=no-reply@example.test\n"
    . "MAIL_DELIVERY_MODE=queue\nOPERATIONS_API_TOKEN=smoke-token\nIP_HASH_SECRET=smoke-secret\n";
if (file_put_contents('.env', $environment) !== strlen($environment) || !is_readable('.env')) {
    throw new RuntimeException('Persistent environment symlink is not writable/readable.');
}
$submissions = new \formflow\SqliteSubmissionRepository('storage/submissions.sqlite');
$submissions->create('smoke', ['message' => 'operation smoke'], null, 'pending_mail');
$forms = new \formflow\SqliteFormRepository('storage/submissions.sqlite');
$forms->create('smoke', ['recipient' => 'recipient@example.test', 'allowed_origins' => ['https://example.test']]);
file_put_contents('storage/uploads/smoke.txt', 'smoke-upload');
""")
        status = json.loads(cli("delivery:status", expected=1))
        require(not status["healthy"] and status["mail"]["pending"] == 1, "Missing pending-mail alert")
        api("wrong-token", 401)
        api("smoke-token", 503, False)
        cli("mail:work")
        status = json.loads(cli("delivery:status"))
        require(
            status["healthy"] and status["mail"]["last_success"]
            and status["mail"]["last_summary"]["sent"] == 1,
            "Mail delivery or successful heartbeat missing",
        )
        cli("backup:create", "--output=/tmp/smoke-backup.tar")
        cli("backup:restore", "--input=/tmp/smoke-backup.tar", "--target=/tmp/restored")
        restored = json.loads(cli("delivery:status", "--root=/tmp/restored"))
        require(restored["healthy"] and restored["mail"]["pending"] == 0, "Restore status failed")
        php("exit(file_get_contents('/tmp/restored/storage/uploads/smoke.txt') === 'smoke-upload' ? 0 : 1);")
        api("smoke-token", 200, True)
        run(["docker", "restart", container])
        wait_ready()
        api("smoke-token", 200, True)
        print("Docker operations passed: read-only runtime, mail/heartbeat, API auth/status, full backup/restore, restart persistence.")
    finally:
        subprocess.run(["docker", "rm", "-f", container], capture_output=True, timeout=30)
        for volume in volumes:
            subprocess.run(["docker", "volume", "rm", volume], capture_output=True, timeout=30)


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--image", default="formflow-test", help="Already built production image")
    verify(parser.parse_args().image)
