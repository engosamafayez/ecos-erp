<?php

declare(strict_types=1);

namespace Modules\CustomerEngagement\Voice\Domain\Enums;

/**
 * TASK-ECOS-V1.1-CRM-03-OMNICHANNEL-VOICE-BACKEND-IMPLEMENTATION-015 §14 — architecture report
 * CALLER VERIFICATION (approved V1 default): caller-ID match alone never authorizes a sensitive
 * fact. `OrderCorroborated` requires the caller to supply an order reference the backend
 * independently confirms belongs to the resolved Customer — never taken on the caller's word
 * alone. Stored in `Call.metadata['verification_level']` (no new dedicated column — this is
 * exactly the kind of small operational fact that field exists for).
 */
enum CallerVerificationLevel: string
{
    case Unverified = 'unverified';
    case OrderCorroborated = 'order_corroborated';
}
