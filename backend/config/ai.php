<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Resident AI (CORE-03)
|--------------------------------------------------------------------------
|
| Server-side only. Nothing in this file — least of all `api_key` — is ever
| exposed to the frontend; the frontend talks to ECOS's own /api/ai/assistant
| endpoint, never to a model vendor directly.
|
| `enabled` false, an empty `api_key`, or an unset/unknown `provider` all fail
| CLOSED (App\Core\AI\Exceptions\AIProviderUnavailableException) rather than
| silently falling back to a fabricated answer.
*/

return [

    'enabled' => (bool) env('AI_ENABLED', false),

    'provider' => env('AI_PROVIDER', 'openai'),

    'providers' => [
        'openai' => [
            'api_key' => env('OPENAI_API_KEY'),
            'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
            'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        ],
    ],

    'timeout_seconds' => (int) env('AI_TIMEOUT', 20),

    'max_response_tokens' => (int) env('AI_MAX_TOKENS', 800),

    // Hard ceiling on how many tool-call round-trips one assistant request may
    // make before it is stopped safely (§27: "prevent infinite tool loops").
    'max_tool_calls_per_request' => (int) env('AI_MAX_TOOL_CALLS', 4),

    // Bounded client-supplied recent history (§26 — no persistent conversation
    // table in V1; the caller resends its own recent turns each request).
    'max_recent_messages' => (int) env('AI_MAX_RECENT_MESSAGES', 12),

    'max_message_length' => (int) env('AI_MAX_MESSAGE_LENGTH', 2000),

];
