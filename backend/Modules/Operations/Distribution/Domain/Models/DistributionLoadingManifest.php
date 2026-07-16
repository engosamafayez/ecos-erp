<?php

declare(strict_types=1);

namespace Modules\Operations\Distribution\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DistributionLoadingManifest extends Model
{
    protected $table = 'distribution_loading_manifests';

    protected $fillable = [
        'distribution_trip_id',
        'preparation_wave_id',
        'company_id',
        'status',
        'total_products',
        'confirmed_products',
        'shortage_products',
        'notes',
        'warehouse_user_id',
        'approved_by',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'started_at'   => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function trip(): BelongsTo
    {
        return $this->belongsTo(DistributionTrip::class, 'distribution_trip_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(DistributionLoadingManifestItem::class, 'loading_manifest_id');
    }

    public function isComplete(): bool
    {
        return $this->status === 'completed';
    }

    public function hasPendingShortages(): bool
    {
        return $this->items()->where('status', 'shortage')->whereNull('shortage_resolution')->exists();
    }
}
