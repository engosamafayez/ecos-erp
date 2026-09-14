<?php

declare(strict_types=1);

namespace Modules\CustomerEngagement\Voice\Domain\ValueObjects;

/**
 * TASK-ECOS-V1.1-CRM-03-OMNICHANNEL-VOICE-BACKEND-IMPLEMENTATION-015 §9 — everything a realtime
 * voice provider needs to start one call's session. Deliberately excludes any authorization
 * data (no permission list, no user object) — mirrors AIRequestContext's own discipline (CORE-03):
 * "context must never become an authorization authority". Tool authorization happens entirely in
 * VoiceAIToolInvoker, never inside the realtime session layer.
 *
 * `toolDefinitions` are plain JSON-Schema tool descriptors (name/description/inputSchema) drawn
 * from VoiceAIToolRegistry — the realtime provider only needs to know a tool's shape to offer it
 * to the model; it never executes a tool itself.
 */
final class RealtimeVoiceSessionConfig
{
    /**
     * @param  list<array<string, mixed>>  $toolDefinitions
     */
    public function __construct(
        public readonly string $callId,
        public readonly string $language,
        public readonly string $systemPrompt,
        public readonly array $toolDefinitions,
        public readonly int $maxDurationSeconds,
    ) {}
}
