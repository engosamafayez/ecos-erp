<?php

declare(strict_types=1);

namespace Modules\CustomerEngagement\Voice\Domain\Exceptions;

use RuntimeException;

/**
 * Thrown by a telephony provider adapter (never by the caller) whenever it cannot honestly act:
 * no concrete vendor configured, disabled, missing credentials, or an upstream failure — mirrors
 * AIProviderUnavailableException's exact shape (§27: fail closed, never fabricate a call outcome).
 */
final class TelephonyProviderUnavailableException extends RuntimeException
{
    public static function noProviderConfigured(): self
    {
        return new self('No telephony provider is configured. Voice calling is not yet available.');
    }

    public static function misconfigured(string $detail): self
    {
        return new self("Telephony provider is misconfigured: {$detail}");
    }

    public static function requestFailed(string $detail): self
    {
        return new self("Telephony provider request failed: {$detail}");
    }
}
