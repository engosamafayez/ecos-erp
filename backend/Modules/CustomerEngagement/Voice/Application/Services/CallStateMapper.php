<?php

declare(strict_types=1);

namespace Modules\CustomerEngagement\Voice\Application\Services;

use Modules\CustomerEngagement\Voice\Domain\Enums\CallCanonicalState;
use Modules\CustomerEngagement\Voice\Domain\Models\Call;

/**
 * TASK-ECOS-V1.1-CRM-03-OMNICHANNEL-VOICE-BACKEND-IMPLEMENTATION-015 §5 — the ONE place a
 * provider-normalized call signal becomes canonical business state. Mirrors
 * WooCommerceProductAvailabilityResolver's translate-only discipline (CRM-02): this class never
 * invents state, it only maps.
 *
 * A concrete telephony adapter (external dependency, not built in this task — see
 * TelephonyProviderContract) is responsible for turning ITS vendor's raw status vocabulary into
 * one of the small set of provider-agnostic `NormalizedSignal` values this mapper accepts; the
 * vendor's own raw string is still stored verbatim on `Call.provider_raw_status`, kept entirely
 * separate from `canonical_state`, so a future adapter swap never has to reinterpret history.
 *
 * Idempotent and out-of-order-safe (§23): a terminal state, once set, is never overwritten by a
 * later event (a delayed "ringing" arriving after "completed" is a no-op); a non-terminal signal
 * only advances `canonical_state` forward along CallCanonicalState::ordinal(), never backward.
 */
final class CallStateMapper
{
    public const SIGNAL_RINGING = 'ringing';

    public const SIGNAL_ANSWERED = 'answered';

    public const SIGNAL_AI_ENGAGED = 'ai_engaged';

    public const SIGNAL_TRANSFER_STARTED = 'transfer_started';

    public const SIGNAL_HUMAN_ENGAGED = 'human_engaged';

    public const SIGNAL_COMPLETED = 'completed';

    public const SIGNAL_FAILED = 'failed';

    public const SIGNAL_NO_ANSWER = 'no_answer';

    public const SIGNAL_BUSY = 'busy';

    public const SIGNAL_CANCELLED = 'cancelled';

    private const SIGNAL_STATE_MAP = [
        self::SIGNAL_RINGING => CallCanonicalState::Ringing,
        self::SIGNAL_ANSWERED => CallCanonicalState::Connected,
        self::SIGNAL_AI_ENGAGED => CallCanonicalState::AiActive,
        self::SIGNAL_TRANSFER_STARTED => CallCanonicalState::Transferring,
        self::SIGNAL_HUMAN_ENGAGED => CallCanonicalState::HumanActive,
        self::SIGNAL_COMPLETED => CallCanonicalState::Completed,
        self::SIGNAL_FAILED => CallCanonicalState::Failed,
        self::SIGNAL_NO_ANSWER => CallCanonicalState::NoAnswer,
        self::SIGNAL_BUSY => CallCanonicalState::Busy,
        self::SIGNAL_CANCELLED => CallCanonicalState::Cancelled,
    ];

    /**
     * Applies a normalized signal to $call in place (does not persist — caller saves).
     * $rawProviderStatus, if given, always overwrites provider_raw_status regardless of whether
     * the canonical state itself changes, so the raw log is never lossy even on a no-op event.
     */
    public function apply(Call $call, string $normalizedSignal, ?string $rawProviderStatus = null): void
    {
        if ($rawProviderStatus !== null) {
            $call->provider_raw_status = $rawProviderStatus;
        }

        $target = self::SIGNAL_STATE_MAP[$normalizedSignal] ?? null;

        if ($target === null) {
            return;
        }

        /** @var CallCanonicalState $current */
        $current = $call->canonical_state;

        if ($current->isTerminal()) {
            return;
        }

        if ($target->isTerminal()) {
            $call->canonical_state = $target;

            return;
        }

        if ($target->ordinal() > $current->ordinal()) {
            $call->canonical_state = $target;
        }
    }
}
