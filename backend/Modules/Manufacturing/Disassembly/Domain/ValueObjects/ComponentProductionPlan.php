<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Disassembly\Domain\ValueObjects;

/**
 * Immutable plan for producing a single component during disassembly.
 *
 * The inverse of ComponentConsumptionPlan — instead of consuming raw materials,
 * disassembly produces them back into inventory.
 */
final readonly class ComponentProductionPlan
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $component_id,
        public string $sku,
        public string $name,
        public string $unit_symbol,
        /** Total quantity to add to inventory for this component. */
        public float $qty_to_produce,
        /** Quantity per finished-good unit from the recipe. */
        public float $required_per_unit,
        /**
         * Cost per unit of this material, frozen when the recipe was approved.
         *
         * The authoritative cost basis for the inventory this disassembly creates.
         * It is NOT derived from the finished product's cost or its FIFO layers:
         * what a component is worth is what the approved recipe said it cost, not a
         * share of what the assembled product was acquired for.
         */
        public float $unit_cost = 0.0,
        public array $metadata = [],
    ) {}

    /** Total value of the inventory this component adds. */
    public function extendedCost(): float
    {
        return round($this->qty_to_produce * $this->unit_cost, 4);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'component_id' => $this->component_id,
            'sku' => $this->sku,
            'name' => $this->name,
            'unit_symbol' => $this->unit_symbol,
            'qty_to_produce' => $this->qty_to_produce,
            'required_per_unit' => $this->required_per_unit,
            'unit_cost' => $this->unit_cost,
            'extended_cost' => $this->extendedCost(),
        ];
    }
}
