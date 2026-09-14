<?php

declare(strict_types=1);

namespace Modules\CustomerEngagement\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SlaPolicy extends Model
{
    use HasUuids;

    protected $table = 'cep_sla_policies';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'first_response_minutes' => 'integer',
            'resolution_minutes' => 'integer',
            // TASK-...-CRM-03-...-015 §3B — business_hours_only was a stored, unenforced flag
            // (architecture report gap #2); business_hours/timezone give it an actual schedule
            // to check. This is the one shared authority — see BusinessHoursService — Voice's
            // own after-hours fallback (§17) reads the SAME policy via the Call's Conversation,
            // never a second schedule.
            'business_hours_only' => 'boolean',
            'business_hours' => 'array',
            'is_default' => 'boolean',
            'config' => 'array',
        ];
    }

    public function violations(): HasMany
    {
        return $this->hasMany(SlaViolation::class, 'sla_policy_id');
    }
}
