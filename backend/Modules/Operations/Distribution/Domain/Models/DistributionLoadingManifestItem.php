<?php

declare(strict_types=1);

namespace Modules\Operations\Distribution\Domain\Models;

use Illuminate\Database\Eloquent\Model;

class DistributionLoadingManifestItem extends Model
{
    protected $table = 'distribution_loading_manifest_items';

    protected $fillable = [
        'loading_manifest_id',
        'product_id',
        'product_name',
        'product_sku',
        'required_qty',
        'unit',
        'loaded_qty',
        'status',
        'shortage_qty',
        'shortage_resolution',
        'shortage_notes',
        'confirmed_by',
        'confirmed_at',
        'driver_received_qty',
        'driver_status',
        'driver_confirmed_at',
        'driver_confirmed_by',
    ];

    protected $casts = [
        'required_qty'        => 'float',
        'loaded_qty'          => 'float',
        'shortage_qty'        => 'float',
        'driver_received_qty' => 'float',
        'confirmed_at'        => 'datetime',
        'driver_confirmed_at' => 'datetime',
    ];

    public function isPending(): bool    { return $this->status === 'pending'; }
    public function isConfirmed(): bool  { return $this->status === 'confirmed'; }
    public function isShortage(): bool   { return $this->status === 'shortage'; }

    public function getShortageResolvedAttribute(): bool
    {
        return $this->isShortage() && $this->shortage_resolution !== null;
    }
}
