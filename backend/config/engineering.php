<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Auto Deploy
    |--------------------------------------------------------------------------
    | Controls whether the Deployment Guardian stage automatically triggers
    | a production deployment. Requires explicit opt-in via env.
    */
    'auto_deploy' => (bool) env('ENGINEERING_AUTO_DEPLOY', false),

    /*
    |--------------------------------------------------------------------------
    | CI Provider
    |--------------------------------------------------------------------------
    | The active CI/CD provider. Built-in: "github".
    | Future: "gitlab", "azure_devops", "jenkins", "bitbucket".
    */
    'ci_provider' => env('ENGINEERING_CI_PROVIDER', 'github'),

    /*
    |--------------------------------------------------------------------------
    | Provider Configurations
    |--------------------------------------------------------------------------
    */
    'providers' => [
        'github' => [
            'class'                => \Modules\System\Engineering\Infrastructure\Providers\CI\GitHubCiProvider::class,
            'poll_interval_seconds' => (int) env('ENGINEERING_GH_POLL_INTERVAL', 30),
            'max_wait_seconds'     => (int) env('ENGINEERING_GH_MAX_WAIT', 600),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Pipeline Behavior
    |--------------------------------------------------------------------------
    */
    'defaults' => [
        'template' => env('ENGINEERING_DEFAULT_TEMPLATE', 'release'),
        'branch'   => env('ENGINEERING_DEFAULT_BRANCH', 'main'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Real-time Update Transport
    |--------------------------------------------------------------------------
    | "polling" uses the existing 3-second polling approach.
    | "sse" and "websocket" are reserved for future implementation.
    */
    'realtime' => [
        'driver'                   => env('ENGINEERING_REALTIME_DRIVER', 'polling'),
        'polling_interval_seconds' => (int) env('ENGINEERING_POLL_INTERVAL', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | Pipeline Permissions
    |--------------------------------------------------------------------------
    | Role slugs that are allowed per pipeline action.
    | If empty array, the action is unrestricted (auth:sanctum only).
    */
    'permissions' => [
        'view'    => [],
        'execute' => [],
        'retry'   => [],
        'cancel'  => [],
        'approve' => [],
        'admin'   => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Analytics Lookback Window
    |--------------------------------------------------------------------------
    */
    'analytics' => [
        'lookback_days'    => (int) env('ENGINEERING_ANALYTICS_DAYS', 30),
        'recent_pipelines' => (int) env('ENGINEERING_ANALYTICS_RECENT', 10),
    ],
];
