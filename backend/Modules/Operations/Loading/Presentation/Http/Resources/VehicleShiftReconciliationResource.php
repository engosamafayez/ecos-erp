<?php

declare(strict_types=1);

namespace Modules\Operations\Loading\Presentation\Http\Resources;

use BackedEnum;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Read model for a vehicle-shift reconciliation (ADR-015 §6.4).
 *
 * Emits the header totals and, when the `lines` relation is loaded, one line per
 * vehicle inventory item carrying the three quantity authorities and the derived
 * variance (loaded - delivered - returned). No quantity is computed here — every
 * value comes straight from VehicleShiftReconciliationService.
 */
final class VehicleShiftReconciliationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status instanceof BackedEnum ? $this->status->value : $this->status,
            'vehicle_assignment_id' => $this->vehicle_assignment_id,
            'loading_session_id' => $this->loading_session_id,
            'vehicle_id' => $this->vehicle_id,
            'driver_assignment_id' => $this->driver_assignment_id,
            'operational_date' => $this->operational_date,
            'total_quantity_loaded' => (float) $this->total_quantity_loaded,
            'total_quantity_delivered' => (float) $this->total_quantity_delivered,
            'total_quantity_returned' => (float) $this->total_quantity_returned,
            // ADR-015 §6.4: loaded - delivered - returned, terminal value 0.
            'total_variance' => (float) $this->total_variance,
            'has_variance' => (bool) $this->has_variance,
            'variance_notes' => $this->variance_notes,
            'opened_at' => $this->opened_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'lines' => VehicleShiftReconciliationLineResource::collection(
                $this->whenLoaded('lines'),
            ),
        ];
    }
}
