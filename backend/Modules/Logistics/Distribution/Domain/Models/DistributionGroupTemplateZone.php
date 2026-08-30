<?php

declare(strict_types=1);

namespace Modules\Logistics\Distribution\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One Zone a template will attach when it is applied.
 *
 * An INTENTION, not a claim. `distribution_slot_zones` — keyed by (window,
 * warehouse, zone) — remains the only authority on who is actually planning a Zone,
 * so two templates naming the same Zone is a normal state, not a conflict: neither
 * plans anything until applied.
 *
 * No `company_id`: the tenant is the parent template's, and a copy here could
 * disagree with it. Every read reaches this row through the company-scoped template.
 *
 * @property int $id
 * @property string $distribution_group_template_id
 * @property int $distribution_zone_id
 */
class DistributionGroupTemplateZone extends Model
{
    protected $table = 'distribution_group_template_zones';

    protected $fillable = [
        'distribution_group_template_id',
        'distribution_zone_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'distribution_zone_id' => 'integer',
        ];
    }

    /** @return BelongsTo<DistributionGroupTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(
            DistributionGroupTemplate::class,
            'distribution_group_template_id',
        );
    }

    /** @return BelongsTo<DistributionZone, $this> */
    public function zone(): BelongsTo
    {
        return $this->belongsTo(DistributionZone::class, 'distribution_zone_id');
    }
}
