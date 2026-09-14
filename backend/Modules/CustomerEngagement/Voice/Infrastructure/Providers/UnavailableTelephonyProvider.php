<?php

declare(strict_types=1);

namespace Modules\CustomerEngagement\Voice\Infrastructure\Providers;

use Illuminate\Http\Request;
use Modules\CustomerEngagement\Voice\Application\Contracts\TelephonyProviderContract;
use Modules\CustomerEngagement\Voice\Domain\Exceptions\TelephonyProviderUnavailableException;

/**
 * TASK-ECOS-V1.1-CRM-03-...-015 §7 — bound in place of a real telephony adapter until a vendor
 * is selected (architecture report: EXTERNAL-CONTRACT, does not block source foundation). Fails
 * closed on every call, mirroring DisabledAIProvider's exact pattern (CORE-03) — no vendor is
 * invented here, and `validateWebhook()` returns false (never true) so an inbound event can
 * never transition Call state while this binding is active.
 */
final class UnavailableTelephonyProvider implements TelephonyProviderContract
{
    public function initiateOutboundCall(string $fromNumber, string $toNumber, array $options = []): array
    {
        throw TelephonyProviderUnavailableException::noProviderConfigured();
    }

    public function validateWebhook(Request $request, string $webhookSecret): bool
    {
        return false;
    }

    public function parseInboundEvent(array $payload): array
    {
        throw TelephonyProviderUnavailableException::noProviderConfigured();
    }

    public function answer(string $providerCallId): bool
    {
        throw TelephonyProviderUnavailableException::noProviderConfigured();
    }

    public function hangup(string $providerCallId): bool
    {
        throw TelephonyProviderUnavailableException::noProviderConfigured();
    }

    public function bridgeTransfer(string $providerCallId, string $targetNumber): array
    {
        throw TelephonyProviderUnavailableException::noProviderConfigured();
    }

    public function getCallStatus(string $providerCallId): array
    {
        throw TelephonyProviderUnavailableException::noProviderConfigured();
    }
}
