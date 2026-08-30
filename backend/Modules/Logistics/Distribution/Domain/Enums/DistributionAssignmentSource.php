<?php

declare(strict_types=1);

namespace Modules\Logistics\Distribution\Domain\Enums;

/**
 * How an Order came to be in the Window it is in.
 *
 * This exists so that "why is this Order here?" is answerable from the row
 * itself. A late Order pulled into an already-cut-off Window by a manager is
 * operationally very different from one the collector picked up automatically,
 * and after the fact the timestamps alone cannot distinguish them.
 */
enum DistributionAssignmentSource: string
{
    /** Picked up by automatic collection while the Window was open. */
    case Automatic = 'auto';

    /** Manual Late-Order Assignment — pulled into a Window past its cutoff (§17). */
    case ManualLate = 'manual_late';

    /** Manager moved the Order's Zone or Slot within its Window (§14, §19). */
    case ManualMove = 'manual_move';

    public function label(): string
    {
        return match ($this) {
            self::Automatic => 'Automatic',
            self::ManualLate => 'Manual Late-Order Assignment',
            self::ManualMove => 'Manual Move',
        };
    }

    public function isManual(): bool
    {
        return $this !== self::Automatic;
    }
}
