<?php

declare(strict_types=1);

namespace Modules\CustomerEngagement\Voice\Domain\Exceptions;

use RuntimeException;

/**
 * TASK-ECOS-V1.1-CRM-03-OMNICHANNEL-VOICE-BACKEND-IMPLEMENTATION-015 §11 — "fail closed if
 * missing/misconfigured". Thrown when Voice tool/call handling for a company is attempted
 * before that company's own AI Voice Assistant system identity has been explicitly provisioned
 * (ProvisionVoiceSystemIdentityAction) — never silently auto-created, never falls back to any
 * other user.
 */
final class VoiceIdentityNotProvisionedException extends RuntimeException
{
    public static function forCompany(string $companyId): self
    {
        return new self("No AI Voice Assistant identity is provisioned for company {$companyId}.");
    }
}
