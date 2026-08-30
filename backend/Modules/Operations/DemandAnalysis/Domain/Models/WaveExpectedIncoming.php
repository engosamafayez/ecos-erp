<?php

declare(strict_types=1);

namespace Modules\Operations\DemandAnalysis\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Operations\Preparation\Domain\Models\PreparationWave;

/**
 * Operator-owned Expected Incoming for one material in one preparation wave.
 *
 * PLANNING ONLY. This is Procurement's estimate of what will arrive for the wave; it is
 * not inventory, not a purchase-order balance, and not a goods receipt. It never changes
 * on-hand, available, reserved, the stock ledger, reservations, or the real missing_qty —
 * it only feeds Uncovered = max(0, missing_qty - expected_incoming).
 *
 * Lives in its own table rather than on the demand projections, which are rebuilt
 * wholesale by the calculators and would clobber an operator value.
 *
 * @property string $id
 * @property string $company_id
 * @property string $preparation_wave_id
 * @property string $material_id
 * @property float $expected_qty
 * @property int|null $updated_by
 */
final class WaveExpectedIncoming extends Model
{
    use HasUuids;

    protected $table = 'wave_expected_incoming';

    protected $fillable = [
        'id',
        'company_id',
        'preparation_wave_id',
        'material_id',
        'expected_qty',
        'updated_by',
    ];

    protected $casts = [
        'expected_qty' => 'float',
        'updated_by' => 'integer',
    ];

    public function wave(): BelongsTo
    {
        return $this->belongsTo(PreparationWave::class, 'preparation_wave_id');
    }
}
