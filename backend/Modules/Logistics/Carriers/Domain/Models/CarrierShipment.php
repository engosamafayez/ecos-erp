<?php

declare(strict_types=1);

namespace Modules\Logistics\Carriers\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Modules\Logistics\Distribution\Domain\Models\DeliveryStop;
use Modules\Logistics\Distribution\Domain\Models\Trip;

/**
 * The bounded external-carrier execution record (TASK-ECOS-V1.1-OPS-03-TASK1-
 * BOSTA). One row per DeliveryStop tendered to an external carrier.
 *
 * NOT a second Shipping Order: it owns only the facts a real carrier tender
 * produces (external reference, tracking, label, raw status) plus the one
 * factual COD echo the verified Bosta contract exposes. Every other business
 * fact — order lines, customer, address, price — is read through the
 * canonical Trip/DeliveryStop/Order it references.
 */
class CarrierShipment extends Model
{
    protected $table = 'carrier_shipments';

    protected $fillable = [
        'uuid', 'company_id', 'trip_id', 'delivery_stop_id', 'carrier_account_id',
        'external_reference', 'tracking_number', 'label_url',
        'raw_status', 'last_event_at', 'cod_amount', 'currency', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'last_event_at' => 'datetime',
            'cod_amount' => 'float',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $shipment): void {
            if ($shipment->uuid === null) {
                $shipment->uuid = (string) Str::uuid();
            }
        });
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class, 'trip_id');
    }

    public function deliveryStop(): BelongsTo
    {
        return $this->belongsTo(DeliveryStop::class, 'delivery_stop_id');
    }

    public function carrierAccount(): BelongsTo
    {
        return $this->belongsTo(CarrierAccount::class, 'carrier_account_id');
    }

    public function hasBeenTendered(): bool
    {
        return $this->external_reference !== null;
    }
}
