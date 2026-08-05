<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services
    | such as Mailgun, Postmark, AWS and more.
    |
    */

    'meta_whatsapp' => [
        'verify_token' => env('META_WA_VERIFY_TOKEN'),
        'app_secret' => env('META_WA_APP_SECRET'),
        'token' => env('META_WA_TOKEN'),
        'phone_number_id' => env('META_WA_PHONE_NUMBER_ID'),
        'api_url' => env('META_WA_API_URL', 'https://graph.facebook.com/v18.0'),
        'waba_id' => env('META_WA_WABA_ID'),
        'typing_indicator_enabled' => env('META_WA_TYPING_INDICATOR_ENABLED', true),
    ],

    'n8n' => [
        'webhook_url' => env('N8N_WEBHOOK_URL'),
        'secret' => env('N8N_SECRET'),
        'incoming_secret' => env('N8N_INCOMING_SECRET'),
        'debounce_seconds' => env('N8N_DEBOUNCE_SECONDS', 5),
    ],

    'holiday_api' => [
        'url' => env('HOLIDAY_API_URL'),
        'key' => env('HOLIDAY_API_KEY'),
    ],

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
