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
            'id'                     => $this->id,
            'bom_number'             => $this->bom_number,
            'product_id'             => $this->product_id,
            'product'                => $this->whenLoaded('product', fn (): array => [
                'id'        => $this->product->id,
                'sku'       => $this->product->sku,
                'name'      => $this->product->name,
                'image_url' => $this->product->image_url,
                'category'  => $this->product->relationLoaded('category') && $this->product->category !== null
                    ? ['id' => $this->product->category->id, 'name' => $this->product->category->name]
                    : null,
                'channels'  => $this->product->relationLoaded('channelMappings')
                    ? $this->product->channelMappings
                        ->filter(fn ($m) => $m->channel !== null)
                        ->map(fn ($m) => [
                            'id'           => $m->channel->id,
                            'name'         => $m->channel->name,
                            'company_id'   => $m->channel->brand?->company_id,
                            'company_name' => $m->channel->brand?->company?->name,
                        ])
                        ->values()
                        ->all()
                    : [],
            ]),
            'lines_count'            => (int) ($this->lines_count ?? 0),
            'recipe_cost'            => (float) ($this->recipe_cost ?? 0),
            'packaging_cost'         => (float) ($this->packaging_cost ?? 0),
            'cost_summary'           => $this->cost_summary ?? null,
            'total_waste_pct'        => round((float) ($this->total_waste_pct ?? 0), 2),
            'version'                => $this->version,
            'bom_version_number'     => $this->bom_version_number,
            'is_active'              => (bool) $this->is_active,
            'notes'                  => $this->notes,
            'manufacturing_cost'     => (float) ($this->manufacturing_cost ?? 0),
            'other_costs'            => (float) ($this->other_costs ?? 0),
            'execution_instructions' => $this->execution_instructions,
            'lines'                  => $this->whenLoaded('lines', fn (): array => $this->lines->map(fn ($line): array => [
                'id'              => $line->id,
                'raw_material_id' => $line->raw_material_id,
                'raw_material'    => $line->relationLoaded('rawMaterial') && $line->rawMaterial !== null ? [
                    'id'            => $line->rawMaterial->id,
                    'sku'           => $line->rawMaterial->sku,
                    'name'          => $line->rawMaterial->name,
                    'product_type'  => $line->rawMaterial->product_type,
                    'image_url'     => $line->rawMaterial->image_url,
                    'material_cost' => (float) ($line->rawMaterial->material_cost ?? 0),
                    'unit'          => $line->rawMaterial->relationLoaded('unit') && $line->rawMaterial->unit !== null ? [
                        'id'     => $line->rawMaterial->unit->id,
                        'name'   => $line->rawMaterial->unit->name,
                        'symbol' => $line->rawMaterial->unit->symbol,
                    ] : null,
                ] : null,
                'quantity'        => (float) $line->quantity,
                'waste_percentage' => (float) ($line->waste_percentage ?? 0),
            ])->all()),
            'created_at'             => $this->created_at?->toIso8601String(),
            'updated_at'             => $this->updated_at?->toIso8601String(),
        ];
    }
}
