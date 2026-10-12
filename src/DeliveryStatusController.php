<?php

declare(strict_types=1);

namespace formflow;

final class DeliveryStatusController
{
    public function __construct(private readonly DeliveryMonitor $monitor, private readonly string $token)
    {
    }

    public function handle(string $method, string $authorization): HttpResponse
    {
        $headers = ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store'];
        if ($this->token === '' || !hash_equals('Bearer ' . $this->token, $authorization)) {
            return new HttpResponse(401, ['error' => 'Unauthorized'], headers: $headers);
        }
        if ($method !== 'GET') {
            return new HttpResponse(405, ['error' => 'Method not allowed'], headers: $headers + ['Allow' => 'GET']);
        }
        $status = $this->monitor->snapshot();
        return new HttpResponse($status['healthy'] ? 200 : 503, $status, headers: $headers);
    }
}
