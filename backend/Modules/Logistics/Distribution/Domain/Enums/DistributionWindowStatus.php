<?php

declare(strict_types=1);

namespace Modules\Logistics\Distribution\Domain\Enums;

/**
 * Lifecycle of a daily Distribution Window.
 *
 * The distinction that matters is CutoffReached vs Closed. Cutoff stops
 * AUTOMATIC ingestion and nothing else: the Workspace stays visible, the
 * aggregation stays live, Slot planning stays editable, and a manager may still
 * attach late Orders by hand (business contract §15). Reading cutoff as "locked"
 * is the single most likely misinterpretation of this module, so the two states
 * are named and separated rather than collapsed into one boolean.
 */
enum DistributionWindowStatus: string
{
    /** Created for a future date; not yet accepting Orders. */
    case Scheduled = 'scheduled';

    /** Between opens_at and closes_at — automatic ingestion is running. */
    case Open = 'open';

    /** Past closes_at. Automatic ingestion stopped; manual edits still allowed. */
    case CutoffReached = 'cutoff_reached';

    /** Handed on to Loading. Terminal for this module. */
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Scheduled => 'Scheduled',
            self::Open => 'Open',
            self::CutoffReached => 'Cutoff Reached',
            self::Closed => 'Closed',
        };
    }

    /** Does automatic Order ingestion run in this state? */
    public function acceptsAutomaticIngestion(): bool
    {
        return $this === self::Open;
    }

    /**
     * May a manager still change assignments, plan Slots, or attach a late Order?
     *
     * True after cutoff — that is the whole point of separating the two states.
     */
    public function acceptsManualAssignment(): bool
    {
        return $this === self::Open || $this === self::CutoffReached;
    }
}
