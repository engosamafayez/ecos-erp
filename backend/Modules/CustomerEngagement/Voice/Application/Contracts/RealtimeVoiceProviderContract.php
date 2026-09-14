<?php

declare(strict_types=1);

namespace Modules\CustomerEngagement\Voice\Application\Contracts;

use Modules\CustomerEngagement\Voice\Domain\ValueObjects\RealtimeVoiceSessionConfig;
use Modules\CustomerEngagement\Voice\Domain\ValueObjects\RealtimeVoiceSessionHandle;

/**
 * TASK-ECOS-V1.1-CRM-03-OMNICHANNEL-VOICE-BACKEND-IMPLEMENTATION-015 §9 — the realtime AI voice
 * seam. Deliberately NOT an extension of {@see \App\Core\AI\Contracts\AIProviderInterface}:
 * that contract is a single synchronous request/response call with no concept of a persistent
 * session, partial output, or interruption, and genuinely cannot represent a live voice turn
 * (architecture report, CORE-03 REUSE — "not reusable as-is"). This is a separate, provider-
 * agnostic session contract; realtime media/audio transport, turn detection, and barge-in are
 * the concrete adapter's concern (external dependency, not built in this task).
 *
 * This contract carries NO authorization logic — "Realtime provider abstraction != business-tool
 * authorization" (§9 of the ticket). Whatever orchestrates a live session is responsible for
 * routing a provider's tool-call event through VoiceAIToolInvoker and feeding the result back via
 * submitToolResult(); this interface only shapes the session transport itself.
 */
interface RealtimeVoiceProviderContract
{
    public function createSession(RealtimeVoiceSessionConfig $config): RealtimeVoiceSessionHandle;

    /**
     * Feeds a completed VoiceAIToolInvoker result back into a live session so the model can
     * continue the turn. $result is the tool's own minimized, already-authorized output —
     * never a raw DB row, never unminimized business data (§21/§30 of the CORE-03 report).
     *
     * @param  array<string, mixed>  $result
     */
    public function submitToolResult(RealtimeVoiceSessionHandle $session, string $toolCallId, array $result): void;

    /**
     * Signals the provider session that a human is taking over (§14) — the realtime layer must
     * stop generating AI speech for this call once this is called; the actual telephony bridge
     * to a human happens through TelephonyProviderContract::bridgeTransfer(), not here.
     */
    public function requestHumanHandoff(RealtimeVoiceSessionHandle $session): void;

    public function closeSession(RealtimeVoiceSessionHandle $session): void;
}
