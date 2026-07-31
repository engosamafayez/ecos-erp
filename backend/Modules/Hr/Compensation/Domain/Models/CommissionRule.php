<?php

declare(strict_types=1);

namespace Modules\Hr\Compensation\Domain\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Hr\Compensation\Domain\Enums\CommissionMethod;
use Modules\Hr\Compensation\Domain\Enums\CommissionScope;

/** A configured commission scheme: which metric, which method, what rate, for whom. */
class CommissionRule extends Model
{
    use HasUuids;

    protected $table = 'hr_commission_rules';

    protected $fillable = [
        'company_id', 'code', 'name', 'description', 'metric_key', 'method', 'rate',
        'applies_to', 'target_id', 'dimension_key', 'dimension_value',
        'min_amount', 'max_amount', 'threshold_value',
        'effective_from', 'effective_to', 'priority', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'method' => CommissionMethod::class,
            'applies_to' => CommissionScope::class,
            'rate' => 'decimal:4',
            'min_amount' => 'decimal:2',
            'max_amount' => 'decimal:2',
            'threshold_value' => 'decimal:4',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'priority' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function tiers(): HasMany
    {
        return $this->hasMany(CommissionRuleTier::class, 'rule_id')->orderBy('sequence');
    }

    /** Rules live on a date — an expired scheme never pays. */
    public function scopeEffectiveOn(Builder $query, string $date): Builder
    {
        return $query->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', $date))
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date));
    }

    public function isEffectiveOn(Carbon $date): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->effective_from !== null && $date->lessThan($this->effective_from)) {
            return false;
        }

        return ! ($this->effective_to !== null && $date->greaterThan($this->effective_to));
    }
}
