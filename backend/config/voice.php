<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| CRM-03 Voice (Omnichannel Customer Conversations + AI Voice Calling)
|--------------------------------------------------------------------------
|
| Server-side only — no telephony/realtime-voice credential is ever exposed to the frontend.
|
| No concrete telephony or realtime-AI-voice vendor is selected as of this task (architecture
| report, EXTERNAL DEPENDENCIES). `telephony.driver`/`realtime.driver` unset (or unknown) both
| fail CLOSED — UnavailableTelephonyProvider / UnavailableRealtimeVoiceProvider — rather than
| silently fabricating a call or session. Do not invent a vendor value here.
*/

return [

    'telephony' => [
        'driver' => env('VOICE_TELEPHONY_DRIVER'),
    ],

    'realtime' => [
        'driver' => env('VOICE_REALTIME_DRIVER'),
    ],

    // Bounded, mirrors ai.max_tool_calls_per_request's own "prevent infinite tool loops" reasoning.
    'max_tool_calls_per_call' => (int) env('VOICE_MAX_TOOL_CALLS', 6),

    'max_call_duration_seconds' => (int) env('VOICE_MAX_CALL_DURATION_SECONDS', 1800),

];
