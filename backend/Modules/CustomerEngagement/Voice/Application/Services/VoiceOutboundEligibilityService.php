<?php

declare(strict_types=1);

namespace Modules\CustomerEngagement\Voice\Application\Services;

use Modules\Crm\Customers\Domain\Models\CustomerPreference;
use Modules\CustomerEngagement\Voice\Domain\Enums\OutboundCallPurpose;

/**
 * TASK-ECOS-V1.1-CRM-03-OMNICHANNEL-VOICE-BACKEND-IMPLEMENTATION-015 §15 — architecture report
 * CONSENT + BUSINESS DECISIONS #4 (approved V1 default): outbound purpose is restricted to
 * {@see OutboundCallPurpose} (transactional/requested_callback/support only — there is no
 * marketing/campaign case, so automated or unsolicited calling is structurally unreachable, not
 * merely policy-discouraged). Reuses the existing `crm_customer_preferences` key/value store
 * (CustomerPreference, CRM Customers EPIC C1) rather than creating a second consent engine — an
 * explicit opt-out is still honored even for a transactional/support purpose, defensively.
 */
final class VoiceOutboundEligibilityService
{
    private const PREFERENCE_KEY = 'voice_call_opt_out';

    public function isEligible(string $customerId, OutboundCallPurpose $purpose): bool
    {
        $optOut = CustomerPreference::query()
            ->where('customer_id', $customerId)
            ->where('key', self::PREFERENCE_KEY)
            ->value('value');

        return $optOut !== 'true';
    }
}
