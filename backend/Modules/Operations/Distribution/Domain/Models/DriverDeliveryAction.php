<?php

declare(strict_types=1);

namespace Modules\Operations\Distribution\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DriverDeliveryAction extends Model
{
    protected $table = 'driver_delivery_actions';

    public $timestamps = false;

    protected $fillable = [
        'stop_id',
        'action_type',
        'reason',
        'notes',
        'new_delivery_date',
        'corrected_lat',
        'corrected_lng',
        'performed_by',
        'created_at',
    ];

    protected $casts = [
        'new_delivery_date' => 'date',
        'corrected_lat'     => 'decimal:7',
        'corrected_lng'     => 'decimal:7',
        'created_at'        => 'datetime',
    ];

    public function stop(): BelongsTo
    {
        return $this->belongsTo(DriverDeliveryStop::class, 'stop_id');
    }
}
