<?php

declare(strict_types=1);

namespace Modules\CustomerEngagement\Voice\Domain\ValueObjects;

/**
 * Opaque handle to a live realtime provider session — the provider's own session identifier
 * plus the Call it belongs to. Callers never parse or depend on the session id's shape.
 */
final class RealtimeVoiceSessionHandle
{
    public function __construct(
        public readonly string $providerSessionId,
        public readonly string $callId,
    ) {}
}
