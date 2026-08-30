<?php

declare(strict_types=1);

namespace Modules\Logistics\Distribution\Domain\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Logistics\Distribution\Domain\Models\DistributionWindowOrder;

/**
 * A manager pulled a late Order into a Window that had already passed cutoff.
 *
 * This is a Manual Late-Order Assignment — the Order stays inside Distribution.
 * It is NOT a direct dispatch and NOT a shipping bypass, and the event is named
 * so that downstream consumers cannot mistake it for one.
 */
class LateOrderManuallyAssigned
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly DistributionWindowOrder $assignment,
        public readonly ?string $previousWindowId,
        public readonly ?string $actor = null,
    ) {}
}
