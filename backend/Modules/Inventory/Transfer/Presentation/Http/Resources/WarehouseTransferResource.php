<?php

declare(strict_types=1);

namespace Modules\Inventory\Transfer\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Inventory\Transfer\Domain\Models\WarehouseTransfer;

/**
 * @mixin WarehouseTransfer
 */
final class WarehouseTransferResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'transfer_number' => $this->transfer_number,
            'company_id' => $this->company_id,
            'source_warehouse_id' => $this->source_warehouse_id,
            'destination_warehouse_id' => $this->destination_warehouse_id,
            'product_id' => $this->product_id,
            'quantity' => (float) $this->quantity,
            'total_cost' => (float) $this->total_cost,
            'weighted_unit_cost' => (float) $this->weighted_unit_cost,
            'status' => $this->status?->value,
            'transferred_by' => $this->transferred_by,
            'transferred_at' => $this->transferred_at?->toIso8601String(),
            'reference' => $this->reference,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
