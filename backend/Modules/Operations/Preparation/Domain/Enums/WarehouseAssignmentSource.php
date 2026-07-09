<?php

declare(strict_types=1);

namespace Modules\Operations\Preparation\Domain\Enums;

enum WarehouseAssignmentSource: string
{
    /** Assigned automatically via a WarehouseAssignmentPolicy match. */
    case AutoPolicy    = 'auto_policy';

    /** Manually overridden by a supervisor after initial assignment. */
    case ManualOverride = 'manual_override';

    /** Fallback: channel has a single default warehouse configured. */
    case ChannelDefault = 'channel_default';

    /** No policy matched and no default exists — requires manual resolution. */
    case Unassigned = 'unassigned';

    public function label(): string
    {
        return match ($this) {
            self::AutoPolicy     => 'Auto (Policy)',
            self::ManualOverride => 'Manual Override',
            self::ChannelDefault => 'Channel Default',
            self::Unassigned     => 'Unassigned',
        };
    }

    public function isAuto(): bool
    {
        return $this === self::AutoPolicy || $this === self::ChannelDefault;
    }
}
