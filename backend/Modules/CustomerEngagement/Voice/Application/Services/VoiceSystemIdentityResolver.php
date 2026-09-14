<?php

declare(strict_types=1);

namespace Modules\CustomerEngagement\Voice\Application\Services;

use App\Models\User;
use Modules\CustomerEngagement\Voice\Domain\Exceptions\VoiceIdentityNotProvisionedException;

/**
 * TASK-ECOS-V1.1-CRM-03-OMNICHANNEL-VOICE-BACKEND-IMPLEMENTATION-015 §11 — the read side of
 * ProvisionVoiceSystemIdentityAction. Fails closed: a company whose identity has never been
 * explicitly provisioned gets an exception, never a fallback to any other user (§11).
 */
final class VoiceSystemIdentityResolver
{
    private const EMAIL_PREFIX = 'ai-voice-assistant+';

    private const EMAIL_DOMAIN = '@system.ecos.internal';

    public function forCompany(string $companyId): User
    {
        $user = User::query()
            ->where('company_id', $companyId)
            ->where('email', self::EMAIL_PREFIX.$companyId.self::EMAIL_DOMAIN)
            ->first();

        if ($user === null) {
            throw VoiceIdentityNotProvisionedException::forCompany($companyId);
        }

        return $user;
    }
}
