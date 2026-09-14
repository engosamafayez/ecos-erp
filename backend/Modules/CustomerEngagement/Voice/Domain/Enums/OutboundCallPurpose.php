<?php

declare(strict_types=1);

namespace Modules\CustomerEngagement\Voice\Domain\Enums;

/**
 * TASK-ECOS-V1.1-CRM-03-OMNICHANNEL-VOICE-BACKEND-IMPLEMENTATION-015 §15 — architecture report
 * BUSINESS DECISIONS #4 (approved): V1 Voice supports only inbound answering plus manual/
 * user-triggered (or a direct single-record system trigger) outbound calls — never automated/
 * unsolicited/campaign calling. There is deliberately no `Campaign` case here; see
 * VoiceOutboundEligibilityService.
 */
enum OutboundCallPurpose: string
{
    case Transactional = 'transactional';
    case RequestedCallback = 'requested_callback';
    case Support = 'support';
}
