<?php

declare(strict_types=1);

namespace Modules\Logistics\Drivers\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Logistics\Drivers\Domain\Models\Vehicle;

/**
 * @mixin Vehicle
 */
class VehicleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'plate_number' => $this->plate_number,
            'type' => $this->type,
            'make' => $this->make,
            'model' => $this->model,
            'year' => $this->year,
            'capacity_orders' => $this->capacity_orders,
            'shipping_company_id' => $this->shipping_company_id,
            'shipping_company_name' => $this->whenLoaded('shippingCompany', fn () => $this->shippingCompany?->name),
            'status' => $this->status,
            'notes' => $this->notes,
            'label' => $this->label(),
            // Gate on relationLoaded() so an unassigned vehicle reports false,
            // not null — whenLoaded() collapses empty relations to null.
            'is_assigned' => $this->when(
                $this->relationLoaded('activeAssignment'),
                fn () => $this->activeAssignment !== null
            ),
            'assigned_driver_name' => $this->when(
                $this->relationLoaded('activeAssignment'),
                fn () => $this->activeAssignment?->driver?->full_name
            ),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
