<?php

declare(strict_types=1);

namespace Modules\Inventory\Products\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Inventory\Products\Domain\Models\Product;

/**
 * @mixin Product
 */
final class ProductResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sku' => $this->sku,
            'barcode' => $this->barcode,
            'name' => $this->name,
            'description' => $this->description,
            'category_id' => $this->category_id,
            'category' => $this->whenLoaded('category', fn (): array => [
                'id' => $this->category->id,
                'code' => $this->category->code,
                'name' => $this->category->name,
            ]),
            'unit_id' => $this->unit_id,
            'unit' => $this->whenLoaded('unit', fn (): array => [
                'id' => $this->unit->id,
                'code' => $this->unit->code,
                'name' => $this->unit->name,
                'symbol' => $this->unit->symbol,
            ]),
            'product_type' => $this->product_type,
            'is_active' => (bool) $this->is_active,
            'image_url' => $this->image_url,
            'regular_price' => $this->regular_price,
            'sale_price' => $this->sale_price,
            'short_description' => $this->short_description,
            'long_description' => $this->long_description,
            'stock_status'         => $this->stock_status?->value,
            'material_cost'        => $this->material_cost,
            'last_purchase_cost'   => $this->last_purchase_cost,
            'average_cost'         => $this->average_cost,
            'current_fifo_cost'    => $this->current_fifo_cost,
            'last_purchase_date'   => $this->last_purchase_date?->toDateString(),
            'last_supplier_id'     => $this->last_supplier_id,
            'cost_source'          => $this->cost_source?->value,
            'can_manufacture'      => (bool) $this->can_manufacture,
            'can_disassemble'      => (bool) $this->can_disassemble,
            'allow_negative_stock' => (bool) $this->allow_negative_stock,
            'created_at'           => $this->created_at?->toIso8601String(),
            'updated_at'           => $this->updated_at?->toIso8601String(),
        ];
    }
}
