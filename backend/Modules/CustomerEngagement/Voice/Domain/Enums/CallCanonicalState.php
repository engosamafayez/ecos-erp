<?php

declare(strict_types=1);

namespace Modules\CustomerEngagement\Voice\Domain\Enums;

/**
 * TASK-ECOS-V1.1-CRM-03-...-015 §5 / architecture report "CALL STATES" — the ONE
 * provider-neutral business truth for a call's lifecycle. Never a vendor's raw status string;
 * see {@see \Modules\CustomerEngagement\Voice\Application\Services\CallStateMapper}, which is
 * the only thing allowed to translate a provider's `provider_raw_status` into one of these
 * (mirrors WooCommerceProductAvailabilityResolver's own translate-only discipline, CRM-02).
 */
enum CallCanonicalState: string
{
    case Initiated = 'initiated';
    case Ringing = 'ringing';
    case Connected = 'connected';
    case AiActive = 'ai_active';
    case Transferring = 'transferring';
    case HumanActive = 'human_active';
    case Completed = 'completed';
    case Failed = 'failed';
    case NoAnswer = 'no_answer';
    case Busy = 'busy';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Initiated => 'Initiated',
            self::Ringing => 'Ringing',
            self::Connected => 'Connected',
            self::AiActive => 'AI Active',
            self::Transferring => 'Transferring',
            self::HumanActive => 'Human Active',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
            self::NoAnswer => 'No Answer',
            self::Busy => 'Busy',
            self::Cancelled => 'Cancelled',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Failed, self::NoAnswer, self::Busy, self::Cancelled], true);
    }

    /**
     * Ranks a state's position in the normal (non-terminal) lifecycle so an out-of-order or
     * delayed provider event can never regress a call backwards (§23: "out-of-order event must
     * not regress state incorrectly"). Terminal states are handled separately by
     * {@see CallStateMapper}, never by this ordinal — once terminal, always terminal.
     */
    public function ordinal(): int
    {
        return match ($this) {
            self::Initiated => 0,
            self::Ringing => 1,
            self::Connected => 2,
            self::AiActive => 3,
            self::Transferring => 4,
            self::HumanActive => 5,
            default => 99,
        };
    }
}
