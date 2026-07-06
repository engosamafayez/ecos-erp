<?php

declare(strict_types=1);

namespace Modules\Operations\Preparation\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Operations\Preparation\Domain\Models\PreparationWave;

/**
 * @mixin PreparationWave
 */
final class PreparationWaveResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $completionPct = $this->total_units_required > 0
            ? round(($this->total_units_prepared / $this->total_units_required) * 100, 1)
            : 0.0;

        return [
            'id'                     => $this->id,
            'wave_number'            => $this->wave_number,
            'status'                 => $this->status?->value,
            'planning_date'          => $this->planning_date?->toDateString(),
            'warehouse_id'           => $this->warehouse_id,
            'orders_count'           => $this->orders_count,
            'products_count'         => $this->products_count,
            'lines_count'            => $this->lines_count,
            'total_units_required'   => $this->total_units_required,
            'total_units_prepared'   => $this->total_units_prepared,
            'completion_pct'         => $completionPct,
            'shortage_detected'      => $this->shortage_detected,
            'config_version_id'      => $this->config_version_id,
            'notes'                  => $this->notes,
            'approved_at'            => $this->approved_at?->toIso8601String(),
            'approved_by'            => $this->approved_by,
            'started_at'             => $this->started_at?->toIso8601String(),
            'started_by'             => $this->started_by,
            'completed_at'           => $this->completed_at?->toIso8601String(),
            'completed_by'           => $this->completed_by,
            'cancelled_at'           => $this->cancelled_at?->toIso8601String(),
            'cancelled_by'           => $this->cancelled_by,
            'cancellation_reason'    => $this->cancellation_reason,
            'created_at'             => $this->created_at?->toIso8601String(),
            'created_by'             => $this->created_by,

            'orders' => $this->whenLoaded('waveOrders', fn () =>
                $this->waveOrders->map(fn ($o) => [
                    'id'           => $o->id,
                    'order_id'     => $o->order_id,
                    'order_number' => $o->order_number,
                    'added_at'     => $o->added_at?->toIso8601String(),
                ])
            ),

            'wave_items' => $this->whenLoaded('waveItems', fn () =>
                $this->waveItems->map(fn ($i) => [
                    'id'                => $i->id,
                    'product_id'        => $i->product_id,
                    'sku'               => $i->sku_snapshot,
                    'name'              => $i->name_snapshot,
                    'quantity_required' => $i->quantity_required,
                    'quantity_prepared' => $i->quantity_prepared,
                    'quantity_short'    => $i->quantity_short,
                    'completion_pct'    => $i->completionPct(),
                    'status'            => $i->status?->value,
                    'prepared_at'       => $i->prepared_at?->toIso8601String(),
                    'prepared_by'       => $i->prepared_by,
                    'notes'             => $i->notes,
                ])
            ),

            'material_requirements' => $this->whenLoaded('materialRequirements', fn () =>
                $this->materialRequirements->map(fn ($m) => [
                    'id'                  => $m->id,
                    'raw_material_id'     => $m->raw_material_id,
                    'name'                => $m->material_name_snapshot,
                    'unit'                => $m->unit_snapshot,
                    'quantity_required'   => $m->quantity_required,
                    'quantity_available'  => $m->quantity_available,
                    'quantity_to_purchase'=> $m->quantity_to_purchase,
                    'shortage'            => $m->shortage,
                    'shortage_amount'     => $m->shortage_amount,
                    'resolved'            => $m->resolved,
                ])
            ),

            'exceptions' => $this->whenLoaded('exceptions', fn () =>
                $this->exceptions->map(fn ($e) => [
                    'id'             => $e->id,
                    'exception_type' => $e->exception_type,
                    'severity'       => $e->severity?->value,
                    'description'    => $e->description,
                    'status'         => $e->status?->value,
                ])
            ),

            'workers' => $this->whenLoaded('workers', fn () =>
                $this->workers->whereNull('released_at')->map(fn ($w) => [
                    'id'      => $w->id,
                    'user_id' => $w->user_id,
                    'role'    => $w->role?->value,
                ])
            ),

            'pick_list' => $this->whenLoaded('pickList', fn () => $this->pickList ? [
                'id'           => $this->pickList->id,
                'status'       => $this->pickList->status?->value,
                'items_count'  => $this->pickList->items()->count(),
                'generated_at' => $this->pickList->generated_at?->toIso8601String(),
            ] : null),
        ];
    }
}
