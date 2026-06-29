<?php

declare(strict_types=1);

namespace Modules\Operations\OrderLifecycle\Domain\Enums;

/**
 * The action the OrderLifecycleCoordinator took for a lifecycle event.
 *
 * Callers branch on this value to understand what happened:
 *
 *   StatusIgnored         — coordinator did not evaluate; status never triggers manufacturing
 *   PolicyRejected        — policy evaluated and returned ineligible; no manufacturing attempted
 *   ManufacturingTriggered — policy approved + manufacturing service invoked + completed
 *   ManufacturingBlocked   — policy approved + manufacturing service invoked + workflow blocked
 *
 * Future actions (planned):
 *   ShippingTriggered, ProcurementTriggered, NotificationSent, ...
 */
enum LifecycleAction: string
{
    case StatusIgnored          = 'status_ignored';
    case PolicyRejected         = 'policy_rejected';
    case ManufacturingTriggered = 'manufacturing_triggered';
    case ManufacturingBlocked   = 'manufacturing_blocked';

    public function label(): string
    {
        return match ($this) {
            self::StatusIgnored          => 'Status ignored — no evaluation performed',
            self::PolicyRejected         => 'Policy rejected — manufacturing not eligible',
            self::ManufacturingTriggered => 'Manufacturing triggered successfully',
            self::ManufacturingBlocked   => 'Manufacturing attempted but workflow blocked',
        };
    }

    /** True when the coordinator successfully initiated manufacturing. */
    public function isManufacturingComplete(): bool
    {
        return $this === self::ManufacturingTriggered;
    }

    /** True when the coordinator took no effective action. */
    public function isNoOp(): bool
    {
        return $this === self::StatusIgnored || $this === self::PolicyRejected;
    }
}
