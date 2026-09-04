<?php

return [
    // Disabled until the additive migration and VAPID configuration are deployed.
    'enabled' => env('WEBPUSH_ENABLED', false),
    'subject' => env('WEBPUSH_VAPID_SUBJECT'),
    'public_key' => env('WEBPUSH_VAPID_PUBLIC_KEY'),
    'private_key' => env('WEBPUSH_VAPID_PRIVATE_KEY'),
    'connection' => env('WEBPUSH_QUEUE_CONNECTION', 'database'),
];
