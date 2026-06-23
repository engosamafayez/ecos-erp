<?php

declare(strict_types=1);

namespace Modules\Purchasing\GoodsReceipts\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Purchasing\GoodsReceipts\Domain\Models\GoodsReceiptLine;

/**
 * @mixin GoodsReceiptLine
 */
final class GoodsReceiptLineResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'purchase_order_line_id' => $this->purchase_order_line_id,
            'product_id' => $this->product_id,
            'product' => $this->whenLoaded('product', fn () => [
                'id' => $this->product->id,
                'sku' => $this->product->sku,
                'name' => $this->product->name,
            ]),
            'ordered_quantity' => (float) $this->ordered_quantity,
            'received_quantity' => (float) $this->received_quantity,
            'remaining_quantity' => round((float) $this->ordered_quantity - (float) $this->received_quantity, 4),
        ];
    }
}
