<?php

declare(strict_types=1);

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
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],
    'meta' => [
        'app_id' => env('META_APP_ID'),
        'app_secret' => env('META_APP_SECRET'),
        'redirect_uri' => env('META_REDIRECT_URI'),
    ],

    // Google Maps — server-side ONLY. The key resolves an order's complete delivery
    // address to coordinates (Geocoding) when no lat/lng was captured. It is read from
    // the environment (never hardcoded, never sent to the frontend). Absent key =>
    // geocoding is reported "not configured" and fails gracefully. The canonical ECOS
    // contract is `services.google_maps.key` ← env `GOOGLE_MAPS_API_KEY`.
    'google_maps' => [
        'key' => env('GOOGLE_MAPS_API_KEY'),
    ],

    // Bosta — the first external carrier (TASK-ECOS-V1.1-OPS-03-TASK1-BOSTA).
    // API-key auth (docs/contracts/INTEGRATION-CATALOG.md §3.4) via Laravel's
    // own standard encrypted config/env layer — see BostaCarrierAdapter's own
    // class docblock for why this is used instead of the Marketing module's
    // OAuth-specific Provider Platform. base_url has NO default host: the
    // verified contract confirms only the "/v2/" version path, not the real
    // domain, so it must be set explicitly rather than guessed.
    'bosta' => [
        'api_key' => env('BOSTA_API_KEY'),
        'base_url' => env('BOSTA_BASE_URL'),
        'timeout' => env('BOSTA_HTTP_TIMEOUT', 15),
    ],

];
