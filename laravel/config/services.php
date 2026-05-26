<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

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

    'scraper' => [
        'url' => env('SCRAPER_URL', 'http://127.0.0.1:3333/api/scrape'),
        'min_timeout' => (int) env('SCRAPER_MIN_TIMEOUT', 180),
        'max_timeout' => (int) env('SCRAPER_MAX_TIMEOUT', 7200),
        'startup_seconds' => (int) env('SCRAPER_STARTUP_SECONDS', 90),
        'seconds_per_page' => (float) env('SCRAPER_SECONDS_PER_PAGE', 8),
        'seconds_per_file' => (float) env('SCRAPER_SECONDS_PER_FILE', 3.2),
        'rows_per_page' => (int) env('SCRAPER_ROWS_PER_PAGE', 20),
        'timeout_buffer' => (float) env('SCRAPER_TIMEOUT_BUFFER', 1.5),
    ],

];
