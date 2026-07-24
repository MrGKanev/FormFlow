<?php

declare(strict_types=1);

return [
    'allowed_ips' => [
        '::1',
    ],

    'login_rate_limit' => [
        'max' => 5,
        'window_minutes' => 15,
    ],
];
