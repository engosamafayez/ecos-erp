<?php

declare(strict_types=1);

namespace Modules\Logistics\Distribution\Domain\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Logistics\Distribution\Domain\Models\DistributionWindowOrder;

/** An Order entered a Distribution Window — automatically or by hand. */
class OrderAddedToDistributionWindow
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly DistributionWindowOrder $assignment,
        public readonly ?string $actor = null,
    ) {}
}
