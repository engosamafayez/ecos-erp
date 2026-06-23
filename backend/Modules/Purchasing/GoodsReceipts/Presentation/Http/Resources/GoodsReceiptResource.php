<?php

declare(strict_types=1);

namespace Modules\Purchasing\GoodsReceipts\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Purchasing\GoodsReceipts\Domain\Models\GoodsReceipt;

/**
 * @mixin GoodsReceipt
 */
final class GoodsReceiptResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'receipt_number' => $this->receipt_number,
            'purchase_order_id' => $this->purchase_order_id,
            'purchase_order' => $this->whenLoaded('purchaseOrder', fn () => [
                'id' => $this->purchaseOrder->id,
                'po_number' => $this->purchaseOrder->po_number,
            ]),
            'warehouse_id' => $this->warehouse_id,
            'warehouse' => $this->whenLoaded('warehouse', fn () => [
                'id' => $this->warehouse->id,
                'code' => $this->warehouse->code,
                'name' => $this->warehouse->name,
            ]),
            'receipt_date' => $this->receipt_date?->toDateString(),
            'status' => $this->status->value,
            'notes' => $this->notes,
            'lines' => GoodsReceiptLineResource::collection($this->whenLoaded('lines')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
