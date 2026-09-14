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
 * time gate. As of TASK-ECOS-V1.1-CRM-03-VOICE-UX-AND-FINAL-SOURCE-CLOSURE-016 §5 (Gap B),
 * {@see requiresVerification()} (the same VERIFIED_ONLY_TOOL_NAMES list, exposed as a per-name
 * check) is ALSO consulted by VoiceAIToolInvoker at the final execution choke point — offering
 * and executing are no longer the same trust boundary; being offered a tool never implies it is
 * executable.
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

    /**
     * §5 (Gap B) — the single source of truth for "does this tool need caller verification",
     * consulted both when building a session's offered tool list (above) and, independently, by
     * VoiceAIToolInvoker at invocation time. One list, two callers — not two policies.
     */
    public function requiresVerification(string $toolName): bool
    {
        return in_array($toolName, self::VERIFIED_ONLY_TOOL_NAMES, true);
    }
}
