<?php

declare(strict_types=1);

namespace Modules\Collaboration\Domain\Enums;

/**
 * The config-driven V1 set (ADR-044 §1.7, ratified). Adding a future type
 * (e.g. Customer) is a new case here, not a schema change — see the
 * `collaboration_operational_context_links` migration.
 */
enum OperationalContextType: string
{
    case Order = 'order';
    case DistributionGroup = 'distribution_group';
    case Trip = 'trip';
    case Driver = 'driver';
}
