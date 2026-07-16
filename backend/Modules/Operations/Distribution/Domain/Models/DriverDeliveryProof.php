<?php

declare(strict_types=1);

namespace Modules\Operations\Distribution\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DriverDeliveryProof extends Model
{
    protected $table = 'driver_delivery_proofs';

    public $timestamps = false;

    protected $fillable = [
        'stop_id',
        'signature_path',
        'photos',
        'notes',
        'captured_at',
        'captured_by',
    ];

    protected $casts = [
        'photos'      => 'array',
        'captured_at' => 'datetime',
    ];

    public function stop(): BelongsTo
    {
        return $this->belongsTo(DriverDeliveryStop::class, 'stop_id');
    }
}
