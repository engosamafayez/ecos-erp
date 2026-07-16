<?php

declare(strict_types=1);

namespace Modules\Operations\Distribution\Presentation\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Operations\Distribution\Domain\Models\DistributionTrip;

/** @mixin DistributionTrip */
class DistributionTripResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'                   => $this->id,
            'preparation_wave_id'  => $this->preparation_wave_id,
            'distribution_zone_id' => $this->distribution_zone_id,
            'trip_number'          => $this->trip_number,
            'name'                 => $this->name,
            'type'                 => $this->type,
            'capacity'             => $this->capacity,
            'orders_count'         => $this->orders_count,
            'collection_amount'    => (float) $this->collection_amount,
            'capacity_usage_percent' => $this->capacity_usage_percent,
            'capacity_status'      => $this->capacity_status,
            'status'               => $this->status,
            'notes'                => $this->notes,
            'finalized_at'         => $this->finalized_at?->toISOString(),
            'created_at'           => $this->created_at?->toISOString(),

            'fleet_vehicle_id'     => $this->fleet_vehicle_id,
            'fleet_driver_id'      => $this->fleet_driver_id,
            'external_carrier_id'  => $this->external_carrier_id,
            'driver_name'          => $this->driver_name,
            'driver_phone'         => $this->driver_phone,

            'vehicle' => $this->whenLoaded('vehicle', fn () => $this->vehicle ? [
                'id'             => $this->vehicle->id,
                'plate_number'   => $this->vehicle->plate_number,
                'type'           => $this->vehicle->type,
                'make'           => $this->vehicle->make,
                'model'          => $this->vehicle->model,
                'display_name'   => $this->vehicle->display_name,
                'capacity_orders' => $this->vehicle->capacity_orders,
            ] : null),

            'driver' => $this->whenLoaded('driver', fn () => $this->driver ? [
                'id'      => $this->driver->id,
                'name_en' => $this->driver->name_en,
                'name_ar' => $this->driver->name_ar,
                'phone'   => $this->driver->phone,
            ] : null),

            'carrier' => $this->whenLoaded('carrier', fn () => $this->carrier ? [
                'id'             => $this->carrier->id,
                'name'           => $this->carrier->name,
                'phone'          => $this->carrier->phone,
                'rate_per_order' => $this->carrier->rate_per_order,
            ] : null),

            'custody_items' => $this->whenLoaded('custodyItems', fn () =>
                $this->custodyItems->map(fn ($item) => [
                    'id'          => $item->id,
                    'item_type'   => $item->item_type,
                    'label'       => $item->label,
                    'description' => $item->description,
                    'quantity'    => $item->quantity,
                    'notes'       => $item->notes,
                ])->values()
            ),

            'is_ready_for_loading' => $this->isReadyForLoading(),
        ];
    }
}
