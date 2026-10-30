<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
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

    /*
    | Semaphore (https://semaphore.co) — Philippine SMS gateway used to reach
    | farmers on basic feature phones. Leave SEMAPHORE_API_KEY empty in local
    | development: messages are then logged in `sms_logs` with status "skipped".
    */
    'semaphore' => [
        'api_key' => env('SEMAPHORE_API_KEY'),
        'sender_name' => env('SEMAPHORE_SENDER_NAME', 'AGRIMATCH'),
        'endpoint' => env('SEMAPHORE_ENDPOINT', 'https://api.semaphore.co/api/v4/messages'),
        'timeout' => (int) env('SEMAPHORE_TIMEOUT', 10),
    ],

];
