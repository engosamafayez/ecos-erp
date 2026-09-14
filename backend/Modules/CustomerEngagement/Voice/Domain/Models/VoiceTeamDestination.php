<?php

declare(strict_types=1);

namespace Modules\CustomerEngagement\Voice\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Organization\Teams\Domain\Models\Team;

/**
 * TASK-ECOS-V1.1-CRM-03-VOICE-UX-AND-FINAL-SOURCE-CLOSURE-016 §2 — Gap A. The smallest explicit
 * Voice transfer destination configuration for a Team (no existing phone concept for teams
 * anywhere in the codebase — see the migration's own docblock). Individual employees never use
 * this table; they resolve through hr_employees directly (TransferDestinationResolver).
 */
class VoiceTeamDestination extends Model
{
    use HasUuids;

    protected $table = 'cep_voice_team_destinations';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
