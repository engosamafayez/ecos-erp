<?php

declare(strict_types=1);

namespace Modules\CostManagement\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\CostManagement\Domain\Models\PricingReview;

/**
 * @mixin PricingReview
 */
class PricingReviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var PricingReview $this */
        $product = $this->product;
        $unit    = $product?->unit;

        return [
            'id'      => $this->id,
            'product' => [
                'id'        => $product?->id,
                'name'      => $product?->name,
                'sku'       => $product?->sku,
                'image_url' => $product?->image_url,
                'unit'      => $unit?->symbol ?? $unit?->name,
            ],
            'company' => [
                'id'   => $this->company_id,
                'name' => $this->company_id,  // resolved by frontend from context
            ],
            'channel' => [
                'id'   => $this->channel_id ?? 'all',
                'name' => $this->channel_id ? 'Channel' : 'All Channels',
            ],

            // Official terminology (Part 1 dictionary)
            'product_cost'           => $this->product_cost,
            'previous_product_cost'  => $this->previous_product_cost,
            'cost_difference'        => $this->cost_difference,
            'cost_change_pct'        => $this->previous_product_cost > 0
                ? round(($this->cost_difference / $this->previous_product_cost) * 100, 2)
                : null,
            'selling_price'          => $this->selling_price,
            'suggested_selling_price'=> $this->suggested_selling_price,
            'target_margin'          => $this->target_margin,
            'current_margin'         => $this->current_margin,

            'impacts'       => $this->impacts ?? [],
            'status'        => $this->status->value,
            'reviewer'      => $this->reviewer_name
                ? ['id' => null, 'name' => $this->reviewer_name]
                : null,
            'snooze_until'  => $this->snooze_until?->toDateString(),
            'notes'         => $this->notes,
            'created_at'    => $this->created_at->toIso8601String(),
            'updated_at'    => $this->updated_at->toIso8601String(),
        ];
    }
}
