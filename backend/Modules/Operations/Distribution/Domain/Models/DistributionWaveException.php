<?php

declare(strict_types=1);

namespace Modules\Operations\Distribution\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DistributionWaveException extends Model
{
    protected $table = 'distribution_wave_exceptions';

    public $timestamps = false;

    protected $fillable = [
        'preparation_wave_id',
        'order_id',
        'distribution_trip_id',
        'reason',
        'notes',
        'returned_by',
        'returned_at',
        'resolved_at',
        'resolved_by',
        'resolution',
    ];

    protected $casts = [
        'returned_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function isResolved(): bool
    {
        return $this->resolved_at !== null;
    }
}
