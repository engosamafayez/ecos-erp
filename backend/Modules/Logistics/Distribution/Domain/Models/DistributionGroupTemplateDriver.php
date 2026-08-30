<?php

declare(strict_types=1);

namespace Modules\Logistics\Distribution\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Logistics\Drivers\Domain\Models\Driver;

/**
 * One Driver a template RECOMMENDS to the operator.
 *
 * A SUGGESTION, not an assignment and not a claim. Applying a template creates a
 * Group with open Driver selection; the Group's real Driver is chosen later
 * through the existing assignment endpoint. Two templates recommending the same
 * Driver is a normal state — recommendations are not ownership — which is why the
 * table has no unique on the driver id alone.
 *
 * No `company_id`: the tenant is the parent template's, and a copy here could
 * disagree with it. Every read reaches this row through the company-scoped
 * template, and driver eligibility is validated against the tenant-scoped Driver.
 *
 * @property int $id
 * @property string $distribution_group_template_id
 * @property int $logistics_driver_id
 */
class DistributionGroupTemplateDriver extends Model
{
    protected $table = 'distribution_group_template_drivers';

    protected $fillable = [
        'distribution_group_template_id',
        'logistics_driver_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'logistics_driver_id' => 'integer',
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

    /** @return BelongsTo<Driver, $this> */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class, 'logistics_driver_id');
    }
}
