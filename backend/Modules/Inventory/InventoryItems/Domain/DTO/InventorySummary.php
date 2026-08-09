<?php

declare(strict_types=1);

namespace Modules\Inventory\InventoryItems\Domain\DTO;

use Modules\Inventory\InventoryItems\Domain\Enums\AvailabilityState;

/**
 * Canonical inventory summary for a single product.
 *
 * The one shape every consumer (dashboards, reports, exports, widgets, APIs)
 * reads for On Hand / Reserved / Available / Value / Warehouse Distribution.
 * Produced only by InventorySummaryService.
 *
 * @param list<array{warehouse_id:string,warehouse_name:?string,warehouse_code:?string,on_hand_qty:float,reserved_qty:float,available_qty:float}> $warehouses
 */
final class InventorySummary
{
    public function __construct(
        public readonly string $productId,
        public readonly float $onHand,
        public readonly float $reserved,
        public readonly float $available,
        public readonly float $inventoryValue,
        public readonly array $warehouses,
        /**
         * Derived projection of {@see $available}. Defaulted so every existing
         * construction site stays valid — this parameter is additive.
         */
        public readonly AvailabilityState $availabilityState = AvailabilityState::Untracked,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'product_id' => $this->productId,
            'on_hand_qty' => $this->onHand,
            'reserved_qty' => $this->reserved,
            'available_qty' => $this->available,
            'availability_state' => $this->availabilityState->value,
            'inventory_value' => $this->inventoryValue,
            'warehouses' => $this->warehouses,
            'total_on_hand' => $this->onHand,
            'total_reserved' => $this->reserved,
            'total_available' => $this->available,
        ];
    }
}
