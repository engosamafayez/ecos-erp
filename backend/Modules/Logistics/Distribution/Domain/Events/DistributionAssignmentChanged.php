<?php

declare(strict_types=1);

namespace Modules\Logistics\Distribution\Domain\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Logistics\Distribution\Domain\Models\DistributionWindowOrder;

/**
 * An Order's Zone or Slot changed within Distribution.
 *
 * Carries the previous values because "what changed" is the operational
 * question; the row alone only answers "what is it now".
 */
class DistributionAssignmentChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly DistributionWindowOrder $assignment,
        public readonly ?int $previousZoneId,
        public readonly ?string $previousSlotId,
        public readonly ?string $actor = null,
    ) {}
}
