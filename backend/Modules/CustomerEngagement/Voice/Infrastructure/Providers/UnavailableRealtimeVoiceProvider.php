<?php

declare(strict_types=1);

namespace Modules\CustomerEngagement\Voice\Infrastructure\Providers;

use Modules\CustomerEngagement\Voice\Application\Contracts\RealtimeVoiceProviderContract;
use Modules\CustomerEngagement\Voice\Domain\Exceptions\RealtimeVoiceProviderUnavailableException;
use Modules\CustomerEngagement\Voice\Domain\ValueObjects\RealtimeVoiceSessionConfig;
use Modules\CustomerEngagement\Voice\Domain\ValueObjects\RealtimeVoiceSessionHandle;

/**
 * Bound in place of a real realtime voice adapter until a vendor is selected — fails closed on
 * every call, mirroring UnavailableTelephonyProvider/DisabledAIProvider. No vendor is invented.
 */
final class UnavailableRealtimeVoiceProvider implements RealtimeVoiceProviderContract
{
    public function createSession(RealtimeVoiceSessionConfig $config): RealtimeVoiceSessionHandle
    {
        throw RealtimeVoiceProviderUnavailableException::noProviderConfigured();
    }

    public function submitToolResult(RealtimeVoiceSessionHandle $session, string $toolCallId, array $result): void
    {
        throw RealtimeVoiceProviderUnavailableException::noProviderConfigured();
    }

    public function requestHumanHandoff(RealtimeVoiceSessionHandle $session): void
    {
        throw RealtimeVoiceProviderUnavailableException::noProviderConfigured();
    }

    public function closeSession(RealtimeVoiceSessionHandle $session): void
    {
        throw RealtimeVoiceProviderUnavailableException::noProviderConfigured();
    }
}
