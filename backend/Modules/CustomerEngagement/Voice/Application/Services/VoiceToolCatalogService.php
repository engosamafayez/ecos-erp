<?php

declare(strict_types=1);

namespace Modules\CustomerEngagement\Voice\Application\Services;

use Modules\CustomerEngagement\Voice\Domain\Enums\CallerVerificationLevel;

/**
 * TASK-ECOS-V1.1-CRM-03-OMNICHANNEL-VOICE-BACKEND-IMPLEMENTATION-015 §14 — decides which tool
 * NAMES a given call's realtime session should be offered, based on its current caller
 * verification level (§14: "before verification: only approved non-sensitive tools; after
 * verification: the approved expanded scope").
 *
 * This governs what is OFFERED in RealtimeVoiceSessionConfig.toolDefinitions — a session-config-
 * time gate, not by itself the hard security boundary (VoiceAIToolInvoker's own permission/scope
 * checks remain that regardless of what was offered). Foundation-scoped deliberately: enforcing
 * this per-call at the invoker layer too requires per-call state to flow into AIRequestContext,
 * which has no live caller to test against until a concrete realtime provider exists (external
 * dependency) — noted explicitly in the task report as a Task 2 / later refinement, not silently
 * treated as already complete.
 */
final class VoiceToolCatalogService
{
    private const BASE_TOOL_NAMES = [
        'get_customer_summary',
        'get_customer_orders',
        'get_order_summary',
        'get_order_payment_proof_state',
        'get_stock_availability',
        'create_follow_up',
        'schedule_callback',
        'create_support_ticket',
    ];

    private const VERIFIED_ONLY_TOOL_NAMES = [
        'get_customer_balance',
    ];

    /**
     * @return list<string>
     */
    public function toolNamesFor(CallerVerificationLevel $level): array
    {
        if ($level === CallerVerificationLevel::OrderCorroborated) {
            return [...self::BASE_TOOL_NAMES, ...self::VERIFIED_ONLY_TOOL_NAMES];
        }

        return self::BASE_TOOL_NAMES;
    }
}
