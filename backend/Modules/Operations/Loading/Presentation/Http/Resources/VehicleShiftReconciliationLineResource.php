<?php

declare(strict_types=1);

namespace Modules\Operations\Loading\Presentation\Http\Resources;

use BackedEnum;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One reconciliation line = one vehicle inventory item's quantity account.
 *
 * variance = quantity_loaded - quantity_delivered - quantity_returned_actual
 * (ADR-015 §6.4). quantity_returned_expected = loaded - delivered is the same
 * equation rearranged and is shown for the operator's benefit.
 */
final class VehicleShiftReconciliationLineResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'vehicle_inventory_item_id' => $this->vehicle_inventory_item_id,
            'product_id' => $this->product_id,
            'sku_snapshot' => $this->sku_snapshot,
            'quantity_loaded' => (float) $this->quantity_loaded,
            'quantity_delivered' => (float) $this->quantity_delivered,
            'quantity_returned_expected' => (float) $this->quantity_returned_expected,
            'quantity_returned_actual' => (float) $this->quantity_returned_actual,
            'variance' => (float) $this->variance,
            'variance_resolution' => $this->variance_resolution instanceof BackedEnum
                ? $this->variance_resolution->value
                : $this->variance_resolution,
            'resolution_notes' => $this->resolution_notes,
        ];
    }
}
