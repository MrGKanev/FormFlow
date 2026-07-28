<?php

declare(strict_types=1);

return [
    'blocked_ips' => [
        // '203.0.113.5',
        // '198.51.100.0/24',
    ],

    'trusted_proxies' => [
        // '127.0.0.1',
        // '10.0.0.0/8',
    ],

    'trusted_ip_headers' => [
        'HTTP_CF_CONNECTING_IP',
        'HTTP_X_FORWARDED_FOR',
        'HTTP_X_REAL_IP',
    ],
];
