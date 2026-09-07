<?php

declare(strict_types=1);

namespace Modules\Logistics\Distribution\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Logistics\Distribution\Domain\Models\TripCashHandover;

/**
 * @mixin TripCashHandover
 */
class TripCashHandoverResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'trip_id' => $this->trip_id,
            'trip_settlement_id' => $this->trip_settlement_id,

            'driver_declared_cash' => $this->driver_declared_cash !== null ? (float) $this->driver_declared_cash : null,
            'expected_cash' => (float) $this->expected_cash,
            'received_cash' => (float) $this->received_cash,
            'difference' => (float) $this->difference,
            'is_exact' => $this->isExact(),
            'is_short' => $this->isShort(),
            'is_over' => $this->isOver(),

            'cash_account_id' => $this->cash_account_id,
            'cash_transaction_id' => $this->cash_transaction_id,

            'received_by' => $this->received_by,
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'notes' => $this->notes,
        ];
    }
}
