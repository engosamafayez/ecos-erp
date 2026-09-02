<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Broadcasting
|--------------------------------------------------------------------------
|
| Did not exist before TASK-ECOS-COLLABORATION-MEDIA-VOICE-REALTIME-
| NOTIFICATIONS-SEARCH-003 (ADR-044 §1.8) — this repository had no
| broadcasting configuration at all despite docs/CLAUDE.md naming Reverb as
| intended stack (see the Task 1 architecture report and this task's
| engineering report for that finding). Standard Laravel 11/12 stub content.
|
| `default` stays 'log' unless BROADCAST_CONNECTION is explicitly changed —
| every existing request/response path is unaffected by this file's mere
| presence. Switching to 'reverb' additionally requires the `laravel/reverb`
| package to actually be installed (declared in composer.json by this same
| task, but `composer install`/`update` was not run here — no PHP toolchain
| on this device) and a running Reverb server — see the engineering report's
| "Broadcasting/Reverb capability findings" section for the exact runtime-
| dependency status before switching this in any real environment.
*/

return [

    'default' => env('BROADCAST_CONNECTION', 'log'),

    'connections' => [

        'reverb' => [
            'driver' => 'reverb',
            'key' => env('REVERB_APP_KEY'),
            'secret' => env('REVERB_APP_SECRET'),
            'app_id' => env('REVERB_APP_ID'),
            'options' => [
                'host' => env('REVERB_HOST'),
                'port' => env('REVERB_PORT', 443),
                'scheme' => env('REVERB_SCHEME', 'https'),
                'useTLS' => env('REVERB_SCHEME', 'https') === 'https',
            ],
            'client_options' => [],
        ],

        'pusher' => [
            'driver' => 'pusher',
            'key' => env('PUSHER_APP_KEY'),
            'secret' => env('PUSHER_APP_SECRET'),
            'app_id' => env('PUSHER_APP_ID'),
            'options' => [
                'cluster' => env('PUSHER_APP_CLUSTER'),
                'host' => env('PUSHER_HOST') ?: 'api-'.env('PUSHER_APP_CLUSTER', 'mt1').'.pusherapp.com',
                'port' => env('PUSHER_PORT', 443),
                'scheme' => env('PUSHER_SCHEME', 'https'),
                'encrypted' => true,
                'useTLS' => env('PUSHER_SCHEME', 'https') === 'https',
            ],
            'client_options' => [],
        ],

        'log' => [
            'driver' => 'log',
        ],

        'null' => [
            'driver' => 'null',
        ],

    ],

];
