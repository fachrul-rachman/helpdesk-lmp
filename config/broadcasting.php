<?php

return [

    'default' => env('BROADCAST_CONNECTION', 'null'),

    'connections' => [

        'reverb' => [
            'driver' => 'reverb',
            'key' => env('REVERB_APP_KEY'),
            'secret' => env('REVERB_APP_SECRET'),
            'app_id' => env('REVERB_APP_ID'),
            'options' => [
                // Untuk broadcast server-to-server (Laravel -> Reverb). Boleh beda dari host publik (frontend).
                // Default sengaja `http` agar broadcast job tidak timeout saat frontend pakai `https/wss`
                // (umumnya TLS hanya di reverse proxy, bukan di port Reverb langsung).
                'host' => env('REVERB_SERVER_HOST', '127.0.0.1'),
                'port' => env('REVERB_SERVER_PORT', 8080),
                'scheme' => env('REVERB_SERVER_SCHEME', 'http'),
                'useTLS' => env('REVERB_SERVER_SCHEME', 'http') === 'https',
            ],
            'client_options' => [],
        ],

        'null' => [
            'driver' => 'null',
        ],

        'log' => [
            'driver' => 'log',
        ],

    ],

];
