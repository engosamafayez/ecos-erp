<?php

declare(strict_types=1);

namespace Modules\Manufacturing\BillsOfMaterials\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Manufacturing\BillsOfMaterials\Domain\Models\BillOfMaterial;

/**
 * @mixin BillOfMaterial
 */
final class BomResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'bom_number' => $this->bom_number,
            'product_id' => $this->product_id,
            'product' => $this->whenLoaded('product', fn (): array => [
                'id' => $this->product->id,
                'sku' => $this->product->sku,
                'name' => $this->product->name,
                'image_url' => $this->product->image_url,
            ]),
            'version' => $this->version,
            'is_active' => (bool) $this->is_active,
            'notes' => $this->notes,
            'lines' => $this->whenLoaded('lines', fn (): array => $this->lines->map(fn ($line): array => [
                'id' => $line->id,
                'raw_material_id' => $line->raw_material_id,
                'raw_material' => $line->relationLoaded('rawMaterial') && $line->rawMaterial !== null ? [
                    'id' => $line->rawMaterial->id,
                    'sku' => $line->rawMaterial->sku,
                    'name' => $line->rawMaterial->name,
                    'unit' => $line->rawMaterial->relationLoaded('unit') && $line->rawMaterial->unit !== null ? [
                        'id' => $line->rawMaterial->unit->id,
                        'name' => $line->rawMaterial->unit->name,
                        'symbol' => $line->rawMaterial->unit->symbol,
                    ] : null,
                ] : null,
                'quantity' => (float) $line->quantity,
                'waste_percentage' => (float) $line->waste_percentage,
            ])->all()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
