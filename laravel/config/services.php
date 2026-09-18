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
        'inspect_timeout' => (int) env('SCRAPER_INSPECT_TIMEOUT', 360),
        'max_timeout' => (int) env('SCRAPER_MAX_TIMEOUT', 7200),
        'startup_seconds' => (int) env('SCRAPER_STARTUP_SECONDS', 90),
        'seconds_per_page' => (float) env('SCRAPER_SECONDS_PER_PAGE', 8),
        'seconds_per_file' => (float) env('SCRAPER_SECONDS_PER_FILE', 3.2),
        'rows_per_page' => (int) env('SCRAPER_ROWS_PER_PAGE', 20),
        'timeout_buffer' => (float) env('SCRAPER_TIMEOUT_BUFFER', 1.5),
        'snapshot_fresh_minutes' => (int) env('SCRAPER_SNAPSHOT_FRESH_MINUTES', 120),
        'snapshot_retention_days' => (int) env('SCRAPER_SNAPSHOT_RETENTION_DAYS', 7),
        'snapshot_min_completeness' => (float) env('SCRAPER_SNAPSHOT_MIN_COMPLETENESS', 60),
    ],

    'telegram_proxy' => [
        'enabled' => (bool) env('TELEGRAM_PROXY_ENABLED', false),
        'provider_url' => env('TELEGRAM_PROXY_PROVIDER_URL', 'https://proxyxoay.shop/api/get.php'),
        'api_key' => env('TELEGRAM_PROXY_API_KEY'),
        'carrier' => env('TELEGRAM_PROXY_CARRIER', 'random'),
        'province' => env('TELEGRAM_PROXY_PROVINCE', '0'),
        'whitelist' => env('TELEGRAM_PROXY_WHITELIST', ''),
        'ttl_seconds' => (int) env('TELEGRAM_PROXY_TTL_SECONDS', 60),
        'connect_timeout' => (int) env('TELEGRAM_PROXY_CONNECT_TIMEOUT', 15),
        'timeout' => (int) env('TELEGRAM_PROXY_TIMEOUT', 180),
    ],

    'telegram_delivery' => [
        'chunk_size' => (int) env('TELEGRAM_DELIVERY_CHUNK_SIZE', 5),
        'delay_ms' => (int) env('TELEGRAM_DELIVERY_DELAY_MS', 1000),
    ],

];
