<?php

declare(strict_types=1);

namespace Modules\CustomerEngagement\Voice\Domain\Exceptions;

use RuntimeException;

/**
 * Mirrors TelephonyProviderUnavailableException/AIProviderUnavailableException's exact shape —
 * thrown by the realtime voice provider adapter whenever it cannot honestly start/continue a
 * session, never fabricated as a completed session.
 */
final class RealtimeVoiceProviderUnavailableException extends RuntimeException
{
    public static function noProviderConfigured(): self
    {
        return new self('No realtime AI voice provider is configured.');
    }

    public static function sessionFailed(string $detail): self
    {
        return new self("Realtime AI voice session failed: {$detail}");
    }
}
