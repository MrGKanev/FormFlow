<?php

declare(strict_types=1);

return [
    'contact' => [
        'recipient' => 'hello@example.com',

        'allowed_origins' => [
            'https://example.com',
            'https://www.example.com',
        ],

        'allowed_fields' => [
            'name',
            'email',
            'phone',
            'message',
        ],

        'required_fields' => [
            'name',
            'email',
            'message',
        ],

        'subject' => 'New contact form submission',

        'success_redirect' => 'https://example.com/thank-you',

        'turnstile' => true,

        'rate_limit_per_ip' => [
            'max' => 5,
            'window_minutes' => 10,
        ],

        'daily_limit' => 200,

        'blocked_patterns' => [
            'viagra',
            '<a href=',
        ],
    ],

    'support' => [
        'recipient' => 'support@example.com',

        'allowed_origins' => [
            'https://support.example.com',
        ],

        'allowed_fields' => [
            'email',
            'order_number',
            'message',
        ],

        'required_fields' => [
            'email',
            'message',
        ],

        'subject' => 'New support request',

        'success_redirect' => 'https://support.example.com/thank-you',

        'turnstile' => true,
    ],
];
