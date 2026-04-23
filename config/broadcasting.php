<?php

return [
    'default' => env('BROADCAST_CONNECTION', 'log'),

    'connections' => [
        'log' => [
            'driver' => 'log',
        ],

        'null' => [
            'driver' => 'null',
        ],

        'reverb' => [
            'driver' => 'reverb',
            'key' => env('REVERB_APP_KEY'),
            'secret' => env('REVERB_APP_SECRET'),
            'app_id' => env('REVERB_APP_ID'),
            'options' => [
                'host' => env('REVERB_HOST', '127.0.0.1'),
                'port' => env('REVERB_PORT', 8080),
                'scheme' => env('REVERB_SCHEME', 'http'),
                'useTLS' => env('REVERB_SCHEME', 'http') === 'https',
            ],
            'client_options' => [],
        ],

        'pusher' => [
            'driver' => 'pusher',
            'key' => env('PUSHER_APP_KEY'),
            'secret' => env('PUSHER_APP_SECRET'),
            'app_id' => env('PUSHER_APP_ID'),
            'options' => array_merge(
                [
                    'cluster' => env('PUSHER_APP_CLUSTER', 'mt1'),
                    'port' => env('PUSHER_PORT', 443),
                    'scheme' => env('PUSHER_SCHEME', 'https'),
                    'useTLS' => env('PUSHER_SCHEME', 'https') === 'https',
                ],
                filled(env('PUSHER_HOST')) ? ['host' => trim((string) env('PUSHER_HOST'))] : [],
            ),
        ],
    ],
];
